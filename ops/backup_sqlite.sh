#!/usr/bin/env bash
set -euo pipefail
umask 077

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
app_root="$(cd "${script_dir}/.." && pwd)"
db_path=""
app_key_path=""
config_path=""
output_dir=""
migration_target=""
source_commit=""
image_digest=""

usage() {
    cat <<'EOF'
Usage: ops/backup_sqlite.sh --output=/path/to/backup-dir --config=/path/to/config.local.php --migration-target=N --source-commit=40_HEX_SHA --image-digest=sha256:64_HEX [--app-root=/path/to/app] [--db=/path/to/cloaking.sqlite] [--app-key=/path/to/app.key]
EOF
}

for arg in "$@"; do
    case "$arg" in
        --app-root=*) app_root="${arg#*=}" ;;
        --db=*) db_path="${arg#*=}" ;;
        --app-key=*) app_key_path="${arg#*=}" ;;
        --config=*) config_path="${arg#*=}" ;;
        --output=*) output_dir="${arg#*=}" ;;
        --migration-target=*) migration_target="${arg#*=}" ;;
        --source-commit=*) source_commit="${arg#*=}" ;;
        --image-digest=*) image_digest="${arg#*=}" ;;
        --help|-h) usage; exit 0 ;;
        *) echo "Unknown argument: ${arg}" >&2; usage >&2; exit 1 ;;
    esac
done

[[ -n "${output_dir}" ]] || { echo "--output is required." >&2; usage >&2; exit 1; }
[[ -n "${config_path}" ]] || { echo "--config is required." >&2; usage >&2; exit 1; }
[[ "${migration_target}" =~ ^[1-9][0-9]*$ ]] || { echo "--migration-target must be a positive integer." >&2; exit 1; }
[[ "${source_commit}" =~ ^[a-f0-9]{40}$ ]] || { echo "--source-commit must be a 40-character lowercase git SHA." >&2; exit 1; }
[[ "${image_digest}" =~ ^sha256:[a-f0-9]{64}$ ]] || { echo "--image-digest must be an immutable sha256 digest." >&2; exit 1; }

if [[ -z "${db_path}" ]]; then db_path="${app_root}/cloaking-runtime/cloaking.sqlite"; fi
if [[ -z "${app_key_path}" ]]; then app_key_path="$(dirname "${db_path}")/app.key"; fi

[[ -f "${db_path}" ]] || { echo "Database file not found: ${db_path}" >&2; exit 1; }
[[ -f "${app_key_path}" ]] || { echo "App key file not found: ${app_key_path}" >&2; exit 1; }
[[ -r "${config_path}" ]] || { echo "Deployment config is missing or unreadable: ${config_path}" >&2; exit 1; }

mkdir -p "${output_dir}"
chmod 0700 "${output_dir}"
if find "${output_dir}" -mindepth 1 -maxdepth 1 | read -r _; then
    echo "Output directory must be empty: ${output_dir}" >&2
    exit 1
fi

tmp_root="$(mktemp -d "${TMPDIR:-/tmp}/cloaking-backup.XXXXXX")"
cleanup() { rm -rf "${tmp_root}"; }
trap cleanup EXIT
chmod 0700 "${tmp_root}"

backup_db="${tmp_root}/cloaking.sqlite"
backup_key="${tmp_root}/app.key"
backup_config="${tmp_root}/config.local.php"
metadata_path="${tmp_root}/backup-metadata.json"
checksums_path="${tmp_root}/SHA256SUMS"
source_state_path="${tmp_root}/source-state.json"

SOURCE_DB="${db_path}" BACKUP_DB="${backup_db}" SOURCE_STATE="${source_state_path}" php <<'PHP'
<?php
$source = (string) getenv('SOURCE_DB');
$destination = (string) getenv('BACKUP_DB');
$statePath = (string) getenv('SOURCE_STATE');
$readState = static function (string $database): array {
    $files = [
        'database' => [
            'path' => $database,
            'sha256' => hash_file('sha256', $database),
            'bytes' => filesize($database),
            'mtime_epoch' => (int) (filemtime($database) ?: 0),
        ],
    ];
    if (is_file($database . '-wal') && filesize($database . '-wal') > 0) {
        $files['wal'] = [
            'path' => $database . '-wal',
            'sha256' => hash_file('sha256', $database . '-wal'),
            'bytes' => filesize($database . '-wal'),
            'mtime_epoch' => (int) (filemtime($database . '-wal') ?: 0),
        ];
    }
    return $files;
};
$before = $readState($source);
$db = new PDO('sqlite:' . $source);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout=5000');
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('VACUUM INTO ' . $db->quote($destination));
$after = $readState($source);
if ($before !== $after) {
    fwrite(STDERR, "Source SQLite state changed during backup capture; retry before migrating.\n");
    exit(1);
}
file_put_contents($statePath, json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
PHP

install -m 0600 "${app_key_path}" "${backup_key}"
install -m 0600 "${config_path}" "${backup_config}"

SOURCE_DB="${db_path}" SOURCE_KEY="${app_key_path}" CONFIG_PATH="${config_path}" php <<'PHP'
<?php
$sourceDb = (string) getenv('SOURCE_DB');
$sourceKey = (string) getenv('SOURCE_KEY');
$configPath = (string) getenv('CONFIG_PATH');
require $configPath;
foreach (['APP_RUNTIME_DIR', 'DB_PATH', 'LOG_PATH', 'APP_BASE_URL', 'SYSTEM_HOSTS', 'TRUSTED_PROXIES'] as $constant) {
    if (!defined($constant)) { fwrite(STDERR, "Deployment config must define {$constant}.\n"); exit(1); }
}
if (!is_array(SYSTEM_HOSTS) || SYSTEM_HOSTS === []) {
    fwrite(STDERR, "Deployment config must define at least one SYSTEM_HOSTS entry.\n"); exit(1);
}
if (!is_array(TRUSTED_PROXIES)) {
    fwrite(STDERR, "Deployment config TRUSTED_PROXIES must be an array.\n"); exit(1);
}
$normalize = static fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/');
if ($normalize((string) DB_PATH) !== $normalize($sourceDb)) {
    fwrite(STDERR, "Deployment config DB_PATH does not match --db.\n"); exit(1);
}
if ($normalize((string) APP_RUNTIME_DIR) . '/app.key' !== $normalize($sourceKey)) {
    fwrite(STDERR, "Deployment config APP_RUNTIME_DIR does not match --app-key.\n"); exit(1);
}
if (defined('APP_KEY') && !hash_equals(trim((string) file_get_contents($sourceKey)), (string) APP_KEY)) {
    fwrite(STDERR, "Deployment config APP_KEY does not match --app-key.\n"); exit(1);
}
PHP

SOURCE_DB="${db_path}" BACKUP_DB="${backup_db}" SOURCE_KEY="${app_key_path}" BACKUP_KEY="${backup_key}" CONFIG_PATH="${config_path}" METADATA_PATH="${metadata_path}" SOURCE_STATE="${source_state_path}" MIGRATION_TARGET="${migration_target}" SOURCE_COMMIT="${source_commit}" IMAGE_DIGEST="${image_digest}" php <<'PHP'
<?php
$sourceDb = (string) getenv('SOURCE_DB');
$backupDb = (string) getenv('BACKUP_DB');
$sourceKey = (string) getenv('SOURCE_KEY');
$backupKey = (string) getenv('BACKUP_KEY');
$configPath = (string) getenv('CONFIG_PATH');
$metadataPath = (string) getenv('METADATA_PATH');
$sourceStatePath = (string) getenv('SOURCE_STATE');
$manifestKey = trim((string) file_get_contents($sourceKey));
if (preg_match('/^[a-f0-9]{64}$/', $manifestKey) !== 1) {
    fwrite(STDERR, "App key must contain exactly 64 lowercase hexadecimal characters.\n"); exit(1);
}

require $configPath;
$configSnapshot = [
    'app_base_url' => APP_BASE_URL,
    'system_hosts' => array_values(SYSTEM_HOSTS),
    'trusted_proxies' => array_values(TRUSTED_PROXIES),
    'runtime_dir' => APP_RUNTIME_DIR,
    'log_path' => LOG_PATH,
];
$backup = new PDO('sqlite:' . $backupDb);
$backup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$appliedVersions = [];
$hasMigrations = (bool) $backup->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'")->fetchColumn();
if ($hasMigrations) {
    $appliedVersions = array_map('intval', $backup->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
}

$sourceFiles = json_decode((string) file_get_contents($sourceStatePath), true);
if (!is_array($sourceFiles) || !isset($sourceFiles['database'])) {
    fwrite(STDERR, "Unable to read captured source SQLite state.\n"); exit(1);
}
foreach ($sourceFiles as $name => $state) {
    $path = (string) ($state['path'] ?? '');
    $expected = $state['sha256'] ?? null;
    if (!is_file($path) || !is_string($expected) || !hash_equals($expected, (string) hash_file('sha256', $path))) {
        fwrite(STDERR, "Source SQLite state changed after backup capture ({$name}); retry before migrating.\n"); exit(1);
    }
}
$currentWalPresent = is_file($sourceDb . '-wal') && filesize($sourceDb . '-wal') > 0;
if ($currentWalPresent !== isset($sourceFiles['wal'])) {
    fwrite(STDERR, "Source SQLite WAL state changed after backup capture; retry before migrating.\n"); exit(1);
}
$sourceTimes = array_map(
    static fn (array $file): int => (int) ($file['mtime_epoch'] ?? 0),
    $sourceFiles
);
$capturedAt = time();
$metadata = [
    'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $capturedAt),
    'captured_at_epoch' => $capturedAt,
    'migration_target' => (int) getenv('MIGRATION_TARGET'),
    'provenance' => [
        'source_commit' => (string) getenv('SOURCE_COMMIT'),
        'image_digest' => (string) getenv('IMAGE_DIGEST'),
    ],
    'source' => [
        'db_path' => $sourceDb,
        'app_key_path' => $sourceKey,
        'config_path' => $configPath,
        'source_latest_mtime_epoch' => max($sourceTimes),
        'database_files' => $sourceFiles,
        'app_key_sha256' => hash_file('sha256', $sourceKey),
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
$unsigned = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($unsigned)) { fwrite(STDERR, "Unable to encode backup metadata.\n"); exit(1); }
$metadata['manifest_hmac'] = hash_hmac('sha256', $unsigned, $manifestKey);
$encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded) || file_put_contents($metadataPath, $encoded . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write backup metadata.\n"); exit(1);
}
chmod($metadataPath, 0600);
PHP

BACKUP_DB="${backup_db}" php <<'PHP'
<?php
$db = new PDO('sqlite:' . (string) getenv('BACKUP_DB'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$result = $db->query('PRAGMA integrity_check')->fetchColumn();
if ($result !== 'ok') { fwrite(STDERR, "Backup integrity check failed: {$result}\n"); exit(1); }
PHP

CHECKSUM_ROOT="${tmp_root}" CHECKSUM_PATH="${checksums_path}" php <<'PHP'
<?php
$root = (string) getenv('CHECKSUM_ROOT');
$output = (string) getenv('CHECKSUM_PATH');
$lines = [];
foreach (['cloaking.sqlite', 'app.key', 'config.local.php', 'backup-metadata.json'] as $file) {
    $lines[] = hash_file('sha256', $root . DIRECTORY_SEPARATOR . $file) . '  ' . $file;
}
file_put_contents($output, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
chmod($output, 0600);
PHP

for artifact in cloaking.sqlite app.key config.local.php backup-metadata.json SHA256SUMS; do
    install -m 0600 "${tmp_root}/${artifact}" "${output_dir}/${artifact}"
done
chmod 0700 "${output_dir}"

printf 'Backup written to %s\n' "${output_dir}"
