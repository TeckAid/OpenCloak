<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must be run from the CLI.\n");
    exit(1);
}

$options = getopt('', ['db::', 'backup-manifest::', 'app-key::', 'max-backup-age::', 'restored-copy']);
if (!defined('DB_PATH') && isset($options['db']) && is_string($options['db']) && $options['db'] !== '') {
    define('DB_PATH', $options['db']);
}
if (!defined('DB_PATH')) {
    define('DB_PATH', dirname(__DIR__) . '/cloaking-runtime/cloaking.sqlite');
}

require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/backup_manifest.php';

try {
    $destructivePending = false;
    if (is_file(DB_PATH) && filesize(DB_PATH) > 0) {
        $probe = new PDO('sqlite:' . DB_PATH);
        $probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $probe->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $destructivePending = database_destructive_migration_pending($probe);
        unset($probe);
    }
    if ($destructivePending) {
        $manifestPath = is_string($options['backup-manifest'] ?? null) ? $options['backup-manifest'] : '';
        $appKeyPath = is_string($options['app-key'] ?? null) ? $options['app-key'] : '';
        $maxAge = isset($options['max-backup-age']) ? (int) $options['max-backup-age'] : 300;
        if ($manifestPath === '' || $appKeyPath === '') {
            throw new RuntimeException(
                'Migration 002 requires a verified backup manifest; pass --backup-manifest and --app-key.'
            );
        }
        if (isset($options['restored-copy'])) {
            backup_manifest_verify_restored_copy_for_migration(
                $manifestPath,
                (string) DB_PATH,
                $appKeyPath,
                get_expected_schema_version()
            );
        } else {
            backup_manifest_verify_for_migration(
                $manifestPath,
                (string) DB_PATH,
                $appKeyPath,
                get_expected_schema_version(),
                $maxAge
            );
        }
    }
    $db = getDB();
    migrate($db, $destructivePending);
    verifyDatabaseSchema($db);
    $versions = database_get_applied_migration_versions($db);
    fwrite(STDOUT, sprintf(
        "Applied schema version %d (%s).\n",
        get_expected_schema_version(),
        $versions === [] ? 'no migrations recorded' : implode(',', $versions)
    ));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
