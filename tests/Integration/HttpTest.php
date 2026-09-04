<?php

require_once __DIR__ . '/../bootstrap.php';

final class HttpTest extends TestCase
{
    public function test_api_rejects_malformed_offer_url_host(): void
    {
        $response = $this->requestSeeded(
            'POST',
            '/api/links',
            static function (PDO $db): void {
                $db->prepare('UPDATE users SET api_key = ? WHERE username = ?')
                    ->execute(['test-api-key', 'admin']);
            },
            [
                'Authorization' => 'Bearer test-api-key',
                'Content-Type' => 'application/json',
            ],
            json_encode([
                'slug' => 'promo',
                'offer_url' => 'https://user:pass@example.com/offer',
            ], JSON_UNESCAPED_SLASHES)
        );

        $this->assertSame(400, $response['status']);
        $this->assertTrue(str_contains($response['body'], 'offer_url must be a valid http(s) URL'));
    }

    public function test_api_rejects_array_clone_name(): void
    {
        $response = $this->requestSeeded(
            'POST',
            '/api/campaigns/1/clone',
            static function (PDO $db): void {
                $db->prepare('UPDATE users SET api_key = ? WHERE username = ?')
                    ->execute(['test-api-key', 'admin']);
                $db->prepare("
                    INSERT INTO campaigns (id, user_id, name, is_active, offer_url, white_page, reject_mode, reject_code, redirect_type, redirect_delay)
                    VALUES (1, 1, 'Original', 1, 'https://offers.example/original', '', 'white', 403, '302', 0)
                ")->execute();
            },
            [
                'Authorization' => 'Bearer test-api-key',
                'Content-Type' => 'application/json',
            ],
            json_encode([
                'name' => ['bad'],
            ], JSON_UNESCAPED_SLASHES)
        );

        $this->assertSame(400, $response['status']);
    }

    public function test_api_rejects_array_domain_input(): void
    {
        $response = $this->requestSeeded(
            'POST',
            '/api/domains',
            static function (PDO $db): void {
                $db->prepare('UPDATE users SET api_key = ? WHERE username = ?')
                    ->execute(['test-api-key', 'admin']);
            },
            [
                'Authorization' => 'Bearer test-api-key',
                'Content-Type' => 'application/json',
            ],
            json_encode([
                'domain' => ['bad.example'],
            ], JSON_UNESCAPED_SLASHES)
        );

        $this->assertSame(400, $response['status']);
    }

    public function test_api_without_bearer_is_401(): void
    {
        $response = HttpFixture::request('GET', '/api/links');

        $this->assertSame(401, $response['status']);
        $this->assertTrue(str_contains($response['body'], 'API key required'));
    }

    public function test_data_path_is_not_downloadable(): void
    {
        $response = HttpFixture::request('GET', '/data/cloaking.db');

        $this->assertSame(404, $response['status']);
        $this->assertSame('Not Found', $response['body']);
    }

    public function test_inactive_custom_host_does_not_serve_system_link(): void
    {
        $response = $this->requestSeeded(
            'GET',
            '/promo',
            static function (PDO $db): void {
                $db->prepare("INSERT INTO domains (user_id, domain, is_system, is_active) VALUES (1, 'inactive.example', 0, 0)")
                    ->execute();
                $db->prepare("
                    INSERT INTO links (user_id, slug, name, offer_url, white_page, is_active, domain_id)
                    VALUES (1, 'promo', 'Promo', 'https://offers.example/promo', '', 1, NULL)
                ")->execute();
            },
            ['Host' => 'inactive.example']
        );

        $this->assertSame(421, $response['status']);
    }

    public function test_malformed_fingerprint_query_is_400(): void
    {
        $response = $this->requestSeeded(
            'GET',
            '/promo?_fph[]=x',
            static function (PDO $db): void {
                $db->prepare("
                    INSERT INTO links (user_id, slug, name, offer_url, white_page, is_active, domain_id)
                    VALUES (1, 'promo', 'Promo', 'https://offers.example/promo', '', 1, NULL)
                ")->execute();
            }
        );

        $this->assertSame(400, $response['status']);
    }

    public function test_malformed_utm_source_query_is_400(): void
    {
        $response = $this->requestSeeded(
            'GET',
            '/promo?utm_source[]=x',
            static function (PDO $db): void {
                $db->prepare("
                    INSERT INTO links (user_id, slug, name, offer_url, white_page, is_active, domain_id)
                    VALUES (1, 'promo', 'Promo', 'https://offers.example/promo', '', 1, NULL)
                ")->execute();
            },
            [
                'User-Agent' => 'Mozilla/5.0',
                'Accept' => 'text/html',
                'Accept-Language' => 'en-US',
            ]
        );

        $this->assertSame(400, $response['status']);
    }

    public function test_unknown_host_is_rejected_with_421(): void
    {
        $response = HttpFixture::request('GET', '/definitely-missing', ['Host' => 'evil.example']);

        $this->assertSame(421, $response['status']);
    }

    public function test_unknown_slug_is_404(): void
    {
        $response = HttpFixture::request('GET', '/definitely-missing');

        $this->assertSame(404, $response['status']);
    }

    private function requestSeeded(
        string $method,
        string $path,
        callable $seed,
        array $headers = [],
        string $body = ''
    ): array {
        $runtime = $this->tempDir('cloaking-http-');
        $docroot = $runtime . DIRECTORY_SEPARATOR . 'docroot';
        $runtimeState = $runtime . DIRECTORY_SEPARATOR . 'runtime';
        $logs = $runtimeState . DIRECTORY_SEPARATOR . 'logs';
        $dbPath = $runtimeState . DIRECTORY_SEPARATOR . 'cloaking.sqlite';
        $docrootData = $docroot . DIRECTORY_SEPARATOR . 'data';

        mkdir($docroot, 0700, true);
        mkdir($runtimeState, 0700, true);
        mkdir($logs, 0700, true);
        mkdir($docrootData, 0700, true);

        $this->copyTree(APP_ROOT . '/includes', $docroot . '/includes');
        $this->copyTree(APP_ROOT . '/api', $docroot . '/api');
        $this->copyFile(APP_ROOT . '/config.php', $docroot . '/config.php');
        $this->copyFile(APP_ROOT . '/index.php', $docroot . '/index.php');
        $this->copyFile(APP_ROOT . '/dev-router.php', $docroot . '/dev-router.php');

        $this->writeConfigOverride($docroot . '/config.local.php', $dbPath, $logs . DIRECTORY_SEPARATOR);
        $this->writeDatabaseShim($docroot . '/includes/database.php');
        $this->initializeDatabaseFile($dbPath);

        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $seed($db);

        $this->copyFile($dbPath, $docrootData . '/cloaking.db');
        $this->writeRouterShim($docroot . '/router.php');

        $port = $this->findFreePort();
        $server = $this->startServer($docroot, $port);

        try {
            $response = $this->httpRequest($port, $method, $path, $headers, $body);
        } finally {
            $this->stopServer($server);
            $this->deleteTree($runtime);
        }

        return $response;
    }

    private function writeConfigOverride(string $path, string $dbPath, string $logPath): void
    {
        $contents = <<<'PHP'
<?php
define('APP_KEY', '%s');
define('APP_BASE_URL', 'http://127.0.0.1');
define('SYSTEM_HOSTS', ['127.0.0.1']);
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

    private function writeDatabaseShim(string $path): void
    {
        $contents = <<<'PHP'
<?php

function getDB(): PDO
{
    static $db = null;
    if ($db === null) {
        $dbPath = defined('DB_PATH') ? DB_PATH : __DIR__ . '/../data/cloaking.db';
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=NORMAL');
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec('PRAGMA foreign_keys=ON');
    }
    return $db;
}

function initDatabase(): PDO
{
    $db = getDB();
    $tables = ['users', 'campaigns', 'domains', 'links', 'settings', 'rate_limits', 'delay_ips', 'hit_log'];
    foreach ($tables as $table) {
        $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException("Missing required table: {$table}");
        }
    }
    return $db;
}

function migrate(PDO $db): void
{
}

function maintenance_tick(PDO $db): void
{
}
PHP;

        file_put_contents($path, $contents);
    }

    private function initializeDatabaseFile(string $dbPath): void
    {
        $setup = tempnam(sys_get_temp_dir(), 'cloaking-db-setup-');
        if ($setup === false) {
            throw new RuntimeException('Unable to create database setup script');
        }

        $script = <<<'PHP'
<?php
define('DB_PATH', %s);
require %s;
initDatabase();
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

    private function writeRouterShim(string $path): void
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

    private function httpRequest(int $port, string $method, string $path, array $headers, string $body): array
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
                $status = (int) $m[1];
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
    private function startServer(string $docroot, int $port)
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

        $this->waitForPort($port);

        return $process;
    }

    /**
     * @param resource $process
     */
    private function stopServer($process): void
    {
        proc_terminate($process);
        proc_close($process);
    }

    private function waitForPort(int $port): void
    {
        $deadline = microtime(true) + 5;
        do {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Timed out waiting for HTTP fixture server on port {$port}");
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($socket)) {
            throw new RuntimeException('Unable to allocate a free TCP port');
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($address === false) {
            throw new RuntimeException('Unable to inspect allocated TCP port');
        }

        $parts = explode(':', $address);

        return (int) end($parts);
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new RuntimeException("Unable to create directory: {$destination}");
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source . DIRECTORY_SEPARATOR . $entry;
            $to = $destination . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($from)) {
                $this->copyTree($from, $to);
                continue;
            }

            $this->copyFile($from, $to);
        }
    }

    private function copyFile(string $source, string $destination): void
    {
        if (!copy($source, $destination)) {
            throw new RuntimeException("Unable to copy {$source} to {$destination}");
        }
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->deleteTree($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }

    private function tempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        do {
            $path = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        } while (file_exists($path));

        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException("Unable to create temporary directory: {$path}");
        }

        return $path;
    }
}
