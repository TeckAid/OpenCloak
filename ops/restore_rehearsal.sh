#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
app_root="$(cd "${script_dir}/.." && pwd)"
backup_dir=""
evidence_dir=""
keep_temp="0"

usage() {
    cat <<'EOF'
Usage: ops/restore_rehearsal.sh --backup=/path/to/backup-dir --evidence-dir=/path/to/evidence [--app-root=/path/to/app] [--keep-temp]
EOF
}

for arg in "$@"; do
    case "$arg" in
        --app-root=*)
            app_root="${arg#*=}"
            ;;
        --backup=*)
            backup_dir="${arg#*=}"
            ;;
        --evidence-dir=*)
            evidence_dir="${arg#*=}"
            ;;
        --keep-temp)
            keep_temp="1"
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: ${arg}" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ -z "${backup_dir}" || -z "${evidence_dir}" ]]; then
    echo "--backup and --evidence-dir are required." >&2
    usage >&2
    exit 1
fi

for path in "${backup_dir}/cloaking.sqlite" "${backup_dir}/app.key" "${backup_dir}/config.local.php" "${backup_dir}/backup-metadata.json" "${backup_dir}/SHA256SUMS"; do
    if [[ ! -f "${path}" ]]; then
        echo "Missing backup artifact: ${path}" >&2
        exit 1
    fi
done

mkdir -p "${evidence_dir}"
if find "${evidence_dir}" -mindepth 1 -maxdepth 1 | read -r _; then
    echo "Evidence directory must be empty: ${evidence_dir}" >&2
    exit 1
fi

started_epoch="$(php -r 'echo sprintf("%.6f", microtime(true));')"
started_utc="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
tmp_root="$(mktemp -d "${TMPDIR:-/tmp}/cloaking-restore.XXXXXX")"

cleanup() {
    if [[ -n "${server_pid:-}" ]]; then
        kill "${server_pid}" >/dev/null 2>&1 || true
        wait "${server_pid}" >/dev/null 2>&1 || true
    fi
    if [[ "${keep_temp}" != "1" ]]; then
        rm -rf "${tmp_root}"
    fi
}
trap cleanup EXIT

CHECKSUM_FILE="${backup_dir}/SHA256SUMS" BACKUP_ROOT="${backup_dir}" php <<'PHP'
<?php
$checksumsPath = (string) getenv('CHECKSUM_FILE');
$root = (string) getenv('BACKUP_ROOT');
$lines = file($checksumsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    fwrite(STDERR, "Unable to read checksum file.\n");
    exit(1);
}
foreach ($lines as $line) {
    if (!preg_match('/^([a-f0-9]{64})  (.+)$/', trim($line), $matches)) {
        fwrite(STDERR, "Invalid checksum line: {$line}\n");
        exit(1);
    }
    $expected = $matches[1];
    $file = $matches[2];
    $path = $root . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing checksummed file: {$file}\n");
        exit(1);
    }
    $actual = hash_file('sha256', $path);
    if (!hash_equals($expected, $actual)) {
        fwrite(STDERR, "Checksum mismatch for {$file}\n");
        exit(1);
    }
}
PHP

restore_root="${tmp_root}/app"
runtime_dir="${restore_root}/cloaking-runtime"
logs_dir="${runtime_dir}/logs"
mkdir -p "${restore_root}" "${runtime_dir}" "${logs_dir}"

for dir in admin api assets bin includes migrations; do
    cp -R "${app_root}/${dir}" "${restore_root}/${dir}"
done
for file in config.php index.php install.php dev-router.php .htaccess; do
    cp "${app_root}/${file}" "${restore_root}/${file}"
done

cp "${backup_dir}/cloaking.sqlite" "${runtime_dir}/cloaking.sqlite"
cp "${backup_dir}/app.key" "${runtime_dir}/app.key"
cp "${backup_dir}/config.local.php" "${restore_root}/config.local.backup.php"

runtime_dir_export="$(php -r 'echo var_export($argv[1], true);' "${runtime_dir}")"
restore_db_export="$(php -r 'echo var_export($argv[1], true);' "${runtime_dir}/cloaking.sqlite")"
logs_dir_export="$(php -r 'echo var_export($argv[1], true);' "${logs_dir}/")"

cat > "${restore_root}/config.local.php" <<PHP
<?php
define('APP_RUNTIME_DIR', ${runtime_dir_export});
define('DB_PATH', ${restore_db_export});
define('LOG_PATH', ${logs_dir_export});
define('APP_BASE_URL', 'http://127.0.0.1');
define('SYSTEM_HOSTS', ['127.0.0.1']);
define('TRUSTED_PROXIES', []);
PHP

cat > "${restore_root}/router.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/(cloaking-runtime|data|logs)/#', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not Found';
    return true;
}

return require __DIR__ . '/dev-router.php';
PHP

integrity_status="$(RESTORE_DB_PATH="${runtime_dir}/cloaking.sqlite" php <<'PHP'
<?php
try {
    $db = new PDO('sqlite:' . (string) getenv('RESTORE_DB_PATH'));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $result = $db->query('PRAGMA integrity_check')->fetchColumn();
    if ($result !== 'ok') {
        fwrite(STDERR, "Backup integrity check failed: {$result}\n");
        exit(1);
    }
    echo "ok";
} catch (Throwable $e) {
    fwrite(STDERR, "Backup integrity check failed: " . $e->getMessage() . "\n");
    exit(1);
}
PHP
)"

migration_stdout_path="${evidence_dir}/migration.stdout.log"
migration_stderr_path="${evidence_dir}/migration.stderr.log"
if php "${restore_root}/bin/migrate.php" --db="${runtime_dir}/cloaking.sqlite" >"${migration_stdout_path}" 2>"${migration_stderr_path}"; then
    migration_exit=0
else
    migration_exit=$?
fi

if [[ "${migration_exit}" -ne 0 ]]; then
    echo "Migration rehearsal failed. See ${migration_stderr_path}" >&2
    exit "${migration_exit}"
fi

port="$(php -r '$socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (!is_resource($socket)) { fwrite(STDERR, $errstr . PHP_EOL); exit(1); } $name = stream_socket_get_name($socket, false); fclose($socket); $parts = explode(":", (string) $name); echo (string) array_pop($parts);')"
php -S "127.0.0.1:${port}" -t "${restore_root}" "${restore_root}/router.php" >"${evidence_dir}/server.stdout.log" 2>"${evidence_dir}/server.stderr.log" &
server_pid=$!

RESTORE_PORT="${port}" php <<'PHP'
<?php
$port = (int) getenv('RESTORE_PORT');
$deadline = microtime(true) + 5.0;
while (microtime(true) < $deadline) {
    $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
    if (is_resource($socket)) {
        fclose($socket);
        exit(0);
    }
    usleep(100000);
}
fwrite(STDERR, "Timed out waiting for restore rehearsal HTTP server.\n");
exit(1);
PHP

RESTORE_DB_PATH="${runtime_dir}/cloaking.sqlite" RESTORE_PORT="${port}" SMOKE_OUTPUT="${evidence_dir}/smoke-results.json" php <<'PHP'
<?php
function rehearsal_request(int $port, string $path, array $headers = []): array
{
    $headerLines = ['Connection: close', 'Host: 127.0.0.1:' . $port];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headerLines),
            'ignore_errors' => true,
            'timeout' => 10,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
    ]);

    $body = file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    if ($body === false) {
        throw new RuntimeException("Request failed for {$path}");
    }

    $status = 0;
    $headersOut = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $matches)) {
            $status = (int) $matches[1];
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headersOut[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    return [
        'status' => $status,
        'headers' => $headersOut,
        'body_excerpt' => substr($body, 0, 200),
    ];
}

$db = new PDO('sqlite:' . (string) getenv('RESTORE_DB_PATH'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$port = (int) getenv('RESTORE_PORT');
$output = (string) getenv('SMOKE_OUTPUT');
$linkRow = $db->query("SELECT slug FROM links WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;

$results = [
    'login' => rehearsal_request($port, '/admin/login.php'),
    'api' => rehearsal_request($port, '/api/links'),
    'link' => null,
];

if (is_array($linkRow) && ($linkRow['slug'] ?? '') !== '') {
    $results['link'] = rehearsal_request($port, '/' . rawurlencode((string) $linkRow['slug']), [
        'User-Agent' => 'Mozilla/5.0 (Recovery Rehearsal)',
        'Accept' => 'text/html',
        'Accept-Language' => 'en-US,en;q=0.9',
    ]);
}

if (($results['login']['status'] ?? 0) !== 200) {
    fwrite(STDERR, "Login smoke check failed.\n");
    exit(1);
}
if (($results['api']['status'] ?? 0) !== 401) {
    fwrite(STDERR, "API smoke check failed.\n");
    exit(1);
}
if (is_array($results['link']) && ($results['link']['status'] ?? 0) !== 302) {
    fwrite(STDERR, "Link smoke check failed.\n");
    exit(1);
}

file_put_contents($output, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
PHP

completed_epoch="$(php -r 'echo sprintf("%.6f", microtime(true));')"
completed_utc="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
BACKUP_METADATA_PATH="${backup_dir}/backup-metadata.json" RESTORE_DB_PATH="${runtime_dir}/cloaking.sqlite" RESTORE_PORT="${port}" RESTORE_STARTED_EPOCH="${started_epoch}" RESTORE_COMPLETED_EPOCH="${completed_epoch}" RESTORE_STARTED_UTC="${started_utc}" RESTORE_COMPLETED_UTC="${completed_utc}" RESTORE_INTEGRITY_STATUS="${integrity_status}" RESTORE_MIGRATION_EXIT="${migration_exit}" RESTORE_TEMP_ROOT="${tmp_root}" RESTORE_KEEP_TEMP="${keep_temp}" REHEARSAL_SUMMARY_PATH="${evidence_dir}/rehearsal-summary.json" php <<'PHP'
<?php
$backupMetadata = json_decode((string) file_get_contents((string) getenv('BACKUP_METADATA_PATH')), true);
if (!is_array($backupMetadata)) {
    fwrite(STDERR, "Unable to parse backup metadata.\n");
    exit(1);
}
$dbPath = (string) getenv('RESTORE_DB_PATH');
$port = (int) getenv('RESTORE_PORT');
$startedEpoch = (float) getenv('RESTORE_STARTED_EPOCH');
$completedEpoch = (float) getenv('RESTORE_COMPLETED_EPOCH');
$startedUtc = (string) getenv('RESTORE_STARTED_UTC');
$completedUtc = (string) getenv('RESTORE_COMPLETED_UTC');
$integrityStatus = (string) getenv('RESTORE_INTEGRITY_STATUS');
$migrationExit = (int) getenv('RESTORE_MIGRATION_EXIT');
$tempRoot = (string) getenv('RESTORE_TEMP_ROOT');
$keepTemp = (string) getenv('RESTORE_KEEP_TEMP');
$output = (string) getenv('REHEARSAL_SUMMARY_PATH');
$sourceMtime = $backupMetadata['source']['source_latest_mtime_epoch'] ?? null;
$capturedAt = $backupMetadata['captured_at_epoch'] ?? null;
$summary = [
    'started_at_utc' => $startedUtc,
    'completed_at_utc' => $completedUtc,
    'port' => $port,
    'integrity_check' => $integrityStatus,
    'migration_exit' => $migrationExit,
    'rpo_seconds' => is_int($sourceMtime) && is_int($capturedAt) ? max(0, $capturedAt - $sourceMtime) : null,
    'rto_seconds' => round(max(0, $completedEpoch - $startedEpoch), 3),
    'restored_db_sha256' => hash_file('sha256', $dbPath),
    'temp_root' => $keepTemp === '1' ? $tempRoot : null,
    'temp_root_cleaned_on_exit' => $keepTemp !== '1',
];
file_put_contents($output, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
PHP

printf 'Restore rehearsal completed using backup %s\n' "${backup_dir}"
printf 'Evidence written to %s\n' "${evidence_dir}"
