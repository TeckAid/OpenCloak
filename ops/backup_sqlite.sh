#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
app_root="$(cd "${script_dir}/.." && pwd)"
db_path=""
app_key_path=""
config_path=""
output_dir=""

usage() {
    cat <<'EOF'
Usage: ops/backup_sqlite.sh --output=/path/to/backup-dir [--app-root=/path/to/app] [--db=/path/to/cloaking.sqlite] [--app-key=/path/to/app.key] [--config=/path/to/config.local.php]
EOF
}

for arg in "$@"; do
    case "$arg" in
        --app-root=*)
            app_root="${arg#*=}"
            ;;
        --db=*)
            db_path="${arg#*=}"
            ;;
        --app-key=*)
            app_key_path="${arg#*=}"
            ;;
        --config=*)
            config_path="${arg#*=}"
            ;;
        --output=*)
            output_dir="${arg#*=}"
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

if [[ -z "${output_dir}" ]]; then
    echo "--output is required." >&2
    usage >&2
    exit 1
fi

if [[ -z "${db_path}" ]]; then
    db_path="${app_root}/cloaking-runtime/cloaking.sqlite"
fi

if [[ -z "${app_key_path}" ]]; then
    app_key_path="$(dirname "${db_path}")/app.key"
fi

if [[ -z "${config_path}" ]]; then
    config_path="${app_root}/config.local.php"
fi

if [[ ! -f "${db_path}" ]]; then
    echo "Database file not found: ${db_path}" >&2
    exit 1
fi

if [[ ! -f "${app_key_path}" ]]; then
    echo "App key file not found: ${app_key_path}" >&2
    exit 1
fi

mkdir -p "${output_dir}"
if find "${output_dir}" -mindepth 1 -maxdepth 1 | read -r _; then
    echo "Output directory must be empty: ${output_dir}" >&2
    exit 1
fi

tmp_root="$(mktemp -d "${TMPDIR:-/tmp}/cloaking-backup.XXXXXX")"
cleanup() {
    rm -rf "${tmp_root}"
}
trap cleanup EXIT

backup_db="${tmp_root}/cloaking.sqlite"
backup_key="${tmp_root}/app.key"
backup_config="${tmp_root}/config.local.php"
metadata_path="${tmp_root}/backup-metadata.json"
checksums_path="${tmp_root}/SHA256SUMS"

SOURCE_DB="${db_path}" BACKUP_DB="${backup_db}" php <<'PHP'
<?php
$source = (string) getenv('SOURCE_DB');
$destination = (string) getenv('BACKUP_DB');
$db = new PDO('sqlite:' . $source);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA busy_timeout=5000');
$db->exec('PRAGMA foreign_keys=ON');
$quoted = $db->quote($destination);
$db->exec("VACUUM INTO {$quoted}");
PHP

cp "${app_key_path}" "${backup_key}"

if [[ -f "${config_path}" ]]; then
    cp "${config_path}" "${backup_config}"
else
    printf '%s\n' '<?php' '// no local config file was present during backup' > "${backup_config}"
fi

SOURCE_DB="${db_path}" BACKUP_DB="${backup_db}" SOURCE_KEY="${app_key_path}" BACKUP_KEY="${backup_key}" CONFIG_PATH="${config_path}" METADATA_PATH="${metadata_path}" php <<'PHP'
<?php
$sourceDb = (string) getenv('SOURCE_DB');
$backupDb = (string) getenv('BACKUP_DB');
$sourceKey = (string) getenv('SOURCE_KEY');
$backupKey = (string) getenv('BACKUP_KEY');
$configPath = (string) getenv('CONFIG_PATH');
$metadataPath = (string) getenv('METADATA_PATH');

$sourceTimes = [];
foreach ([$sourceDb, $sourceDb . '-wal', $sourceDb . '-shm'] as $candidate) {
    if (is_file($candidate)) {
        $sourceTimes[] = filemtime($candidate) ?: 0;
    }
}

$configSnapshot = [
    'app_base_url' => null,
    'system_hosts' => [],
    'trusted_proxies' => [],
    'runtime_dir' => null,
    'log_path' => null,
];

if (is_file($configPath)) {
    require $configPath;
    $configSnapshot = [
        'app_base_url' => defined('APP_BASE_URL') ? APP_BASE_URL : null,
        'system_hosts' => defined('SYSTEM_HOSTS') && is_array(SYSTEM_HOSTS) ? array_values(SYSTEM_HOSTS) : [],
        'trusted_proxies' => defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES) ? array_values(TRUSTED_PROXIES) : [],
        'runtime_dir' => defined('APP_RUNTIME_DIR') ? APP_RUNTIME_DIR : null,
        'log_path' => defined('LOG_PATH') ? LOG_PATH : null,
    ];
}

$backup = new PDO('sqlite:' . $backupDb);
$backup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$appliedVersions = [];
$hasMigrations = (bool) $backup->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'")->fetchColumn();
if ($hasMigrations) {
    $appliedVersions = array_map('intval', $backup->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
}

$capturedAt = time();
$metadata = [
    'created_at_utc' => gmdate('c', $capturedAt),
    'captured_at_epoch' => $capturedAt,
    'source' => [
        'db_path' => $sourceDb,
        'app_key_path' => $sourceKey,
        'config_path' => $configPath,
        'source_latest_mtime_epoch' => $sourceTimes === [] ? null : max($sourceTimes),
    ],
    'config' => $configSnapshot,
    'artifacts' => [
        'database' => [
            'filename' => basename($backupDb),
            'sha256' => hash_file('sha256', $backupDb),
            'bytes' => filesize($backupDb),
            'applied_migrations' => $appliedVersions,
        ],
        'app_key' => [
            'filename' => basename($backupKey),
            'sha256' => hash_file('sha256', $backupKey),
            'bytes' => filesize($backupKey),
        ],
        'config' => [
            'filename' => 'config.local.php',
            'sha256' => hash_file('sha256', dirname($metadataPath) . '/config.local.php'),
            'bytes' => filesize(dirname($metadataPath) . '/config.local.php'),
        ],
    ],
];

file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
PHP

BACKUP_DB="${backup_db}" php <<'PHP'
<?php
$db = new PDO('sqlite:' . (string) getenv('BACKUP_DB'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$result = $db->query('PRAGMA integrity_check')->fetchColumn();
if ($result !== 'ok') {
    fwrite(STDERR, "Backup integrity check failed: {$result}\n");
    exit(1);
}
PHP

CHECKSUM_ROOT="${tmp_root}" CHECKSUM_PATH="${checksums_path}" php <<'PHP'
<?php
$root = (string) getenv('CHECKSUM_ROOT');
$output = (string) getenv('CHECKSUM_PATH');
$files = ['cloaking.sqlite', 'app.key', 'config.local.php', 'backup-metadata.json'];
$lines = [];
foreach ($files as $file) {
    $path = $root . DIRECTORY_SEPARATOR . $file;
    $lines[] = hash_file('sha256', $path) . '  ' . $file;
}
file_put_contents($output, implode(PHP_EOL, $lines) . PHP_EOL);
PHP

cp "${backup_db}" "${output_dir}/cloaking.sqlite"
cp "${backup_key}" "${output_dir}/app.key"
cp "${backup_config}" "${output_dir}/config.local.php"
cp "${metadata_path}" "${output_dir}/backup-metadata.json"
cp "${checksums_path}" "${output_dir}/SHA256SUMS"

printf 'Backup written to %s\n' "${output_dir}"
