<?php

final class DatabaseFixture
{
    public static function fresh(): PDO
    {
        $runtimeDir = self::tempDir('cloaking-db-');
        $dbPath = $runtimeDir . DIRECTORY_SEPARATOR . 'cloaking.sqlite';
        $bootstrap = $runtimeDir . DIRECTORY_SEPARATOR . 'init-db.php';

        $script = <<<'PHP'
<?php
define('DB_PATH', %s);
require %s;
initDatabase();
PHP;

        file_put_contents($bootstrap, sprintf(
            $script,
            var_export($dbPath, true),
            var_export(APP_ROOT . '/includes/database.php', true)
        ));

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bootstrap);
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException("Failed to initialize isolated database: " . implode("\n", $output));
        }

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    private static function tempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        do {
            $dir = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        } while (file_exists($dir));

        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create temporary directory: {$dir}");
        }

        return $dir;
    }
}
