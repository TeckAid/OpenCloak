<?php

final class HttpFixture
{
    /**
     * @return array{status:int,headers:array<string,mixed>,body:string}
     */
    public static function request(string $method, string $path, array $headers = [], string $body = ''): array
    {
        $runtime = self::tempDir('cloaking-http-');
        $docroot = $runtime . DIRECTORY_SEPARATOR . 'docroot';
        $runtimeState = $runtime . DIRECTORY_SEPARATOR . 'runtime';
        $logs = $runtimeState . DIRECTORY_SEPARATOR . 'logs';
        $dbPath = $runtimeState . DIRECTORY_SEPARATOR . 'cloaking.sqlite';
        $docrootData = $docroot . DIRECTORY_SEPARATOR . 'data';

        mkdir($docroot, 0700, true);
        mkdir($runtimeState, 0700, true);
        mkdir($logs, 0700, true);
        mkdir($docrootData, 0700, true);

        self::copyTree(APP_ROOT . '/includes', $docroot . '/includes');
        self::copyTree(APP_ROOT . '/api', $docroot . '/api');
        self::copyTree(APP_ROOT . '/migrations', $docroot . '/migrations');
        self::copyFile(APP_ROOT . '/config.php', $docroot . '/config.php');
        self::copyFile(APP_ROOT . '/index.php', $docroot . '/index.php');
        self::copyFile(APP_ROOT . '/dev-router.php', $docroot . '/dev-router.php');

        self::writeConfigOverride($docroot . '/config.local.php', $dbPath, $logs . DIRECTORY_SEPARATOR);
        self::initializeDatabaseFile($dbPath);
        self::copyFile($dbPath, $docrootData . '/cloaking.db');
        self::writeRouterShim($docroot . '/router.php');

        $port = self::findFreePort();
        $server = self::startServer($docroot, $port);

        try {
            $response = self::httpRequest($port, $method, $path, $headers, $body);
        } finally {
            self::stopServer($server);
            self::deleteTree($runtime);
        }

        return $response;
    }

    private static function writeConfigOverride(string $path, string $dbPath, string $logPath): void
    {
        $contents = <<<'PHP'
<?php
define('APP_KEY', '%s');
define('DB_PATH', '%s');
define('LOG_PATH', '%s');
PHP;

        file_put_contents($path, sprintf(
            $contents,
            bin2hex(random_bytes(32)),
            $dbPath,
            $logPath
        ));
    }

    private static function initializeDatabaseFile(string $dbPath): void
    {
        $setup = tempnam(sys_get_temp_dir(), 'cloaking-db-setup-');
        if ($setup === false) {
            throw new RuntimeException('Unable to create database setup script');
        }

        $script = <<<'PHP'
<?php
define('DB_PATH', %s);
require %s;
setupDatabase();
PHP;
        file_put_contents($setup, sprintf(
            $script,
            var_export($dbPath, true),
            var_export(APP_ROOT . '/includes/database.php', true)
        ));

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($setup);
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        unlink($setup);

        if ($exitCode !== 0) {
            throw new RuntimeException("Failed to initialize fixture database: " . implode("\n", $output));
        }
    }

    private static function writeRouterShim(string $path): void
    {
        $contents = <<<'PHP'
<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/(data|logs)/#', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not Found';
    return true;
}

return require __DIR__ . '/dev-router.php';
PHP;

        file_put_contents($path, $contents);
    }

    /**
     * @return array{status:int,headers:array<string,mixed>,body:string}
     */
    private static function httpRequest(int $port, string $method, string $path, array $headers, string $body): array
    {
        $headerLines = [
            'Host: 127.0.0.1:' . $port,
            'Connection: close',
        ];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $responseBody = file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        if ($responseBody === false) {
            throw new RuntimeException("Request to {$path} failed");
        }

        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        $parsedHeaders = [];
        foreach ($responseHeaders as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                $status = (int)$m[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                if (isset($parsedHeaders[$name])) {
                    if (!is_array($parsedHeaders[$name])) {
                        $parsedHeaders[$name] = [$parsedHeaders[$name]];
                    }
                    $parsedHeaders[$name][] = $value;
                } else {
                    $parsedHeaders[$name] = $value;
                }
            }
        }

        return [
            'status' => $status,
            'headers' => $parsedHeaders,
            'body' => $responseBody,
        ];
    }

    /**
     * @return resource
     */
    private static function startServer(string $docroot, int $port)
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $command = [
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $port,
            '-t',
            $docroot,
            $docroot . '/router.php',
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $docroot);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start HTTP fixture server');
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::waitForPort($port);

        return $process;
    }

    /**
     * @param resource $process
     */
    private static function stopServer($process): void
    {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }

    private static function waitForPort(int $port): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }

        throw new RuntimeException("Timed out waiting for HTTP fixture server on port {$port}");
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($socket)) {
            throw new RuntimeException("Unable to reserve free port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $parts = explode(':', (string)$name);
        return (int)array_pop($parts);
    }

    private static function copyTree(string $source, string $destination): void
    {
        if (is_dir($destination)) {
            self::deleteTree($destination);
        }
        mkdir($destination, 0700, true);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                mkdir($target, 0700, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private static function copyFile(string $source, string $destination): void
    {
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        copy($source, $destination);
    }

    private static function deleteTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
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
