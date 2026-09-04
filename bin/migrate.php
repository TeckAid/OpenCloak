<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must be run from the CLI.\n");
    exit(1);
}

$options = getopt('', ['db::']);
if (!defined('DB_PATH') && isset($options['db']) && is_string($options['db']) && $options['db'] !== '') {
    define('DB_PATH', $options['db']);
}

require dirname(__DIR__) . '/includes/database.php';

try {
    $db = setupDatabase();
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
