<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must be run from the CLI.\n");
    exit(1);
}

$options = getopt('', ['db::', 'to:']);
if (!defined('DB_PATH') && isset($options['db']) && is_string($options['db']) && $options['db'] !== '') {
    define('DB_PATH', $options['db']);
}

if (!isset($options['to']) || !is_string($options['to']) || !preg_match('/^\d+$/', $options['to'])) {
    fwrite(STDERR, "Usage: php bin/rollback.php --to=<version> [--db=/path/to/cloaking.sqlite]\n");
    exit(1);
}

require dirname(__DIR__) . '/includes/database.php';

try {
    $db = getDB();
    rollbackDatabaseToVersion($db, (int) $options['to']);
    fwrite(STDOUT, sprintf("Rolled back to schema version %d.\n", (int) $options['to']));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
