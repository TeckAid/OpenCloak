<?php

require_once __DIR__ . '/../bootstrap.php';

final class SecurityTest extends TestCase
{
    public function test_rate_limit_allows_only_one_parallel_attempt_for_single_key(): void
    {
        $allowedCounts = $this->runParallelRateLimitProbe(4, 8, 1);

        foreach ($allowedCounts as $index => $allowedCount) {
            $this->assertSame(
                1,
                $allowedCount,
                sprintf('Round %d admitted %d parallel attempts for a single key', $index + 1, $allowedCount)
            );
        }
    }

    public function test_app_base_url_uses_explicit_configuration(): void
    {
        $value = $this->runSecurityProbe(<<<'PHP'
define('APP_BASE_URL', 'https://app.example.com');
$_SERVER['HTTP_HOST'] = 'evil.example';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
return app_base_url();
PHP);

        $this->assertSame('https://app.example.com', $value);
    }

    public function test_app_base_url_preserves_bracketed_ipv6_hosts(): void
    {
        $value = $this->runSecurityProbe(<<<'PHP'
define('APP_BASE_URL', 'http://[::1]:8080');
return app_base_url();
PHP);

        $this->assertSame('http://[::1]:8080', $value);
    }

    public function test_app_client_ip_ignores_untrusted_forwarded_for(): void
    {
        $value = $this->runSecurityProbe(<<<'PHP'
define('TRUSTED_PROXIES', ['10.0.0.0/8']);
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.10';
return app_client_ip();
PHP);

        $this->assertSame('198.51.100.20', $value);
    }

    public function test_app_client_ip_uses_rightmost_untrusted_ip_from_trusted_chain(): void
    {
        $value = $this->runSecurityProbe(<<<'PHP'
define('TRUSTED_PROXIES', ['10.0.0.0/8', '192.168.0.0/16']);
$_SERVER['REMOTE_ADDR'] = '10.0.0.4';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 192.168.1.10, 10.9.0.2';
return app_client_ip();
PHP);

        $this->assertSame('198.51.100.7', $value);
    }

    public function test_app_is_https_rejects_untrusted_forwarded_proto(): void
    {
        $value = $this->runSecurityProbe(<<<'PHP'
define('TRUSTED_PROXIES', ['10.0.0.0/8']);
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
return app_is_https();
PHP);

        $this->assertFalse($value);
    }

    public function test_docker_proxy_config_accepts_forwarded_https_from_trusted_caddy_subnet(): void
    {
        $value = $this->runDockerConfigSecurityProbe(<<<'PHP'
$_SERVER['REMOTE_ADDR'] = '172.23.0.2';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
return [
    'client_ip' => app_client_ip(),
    'is_https' => app_is_https(),
];
PHP);

        $this->assertSame('198.51.100.7', $value['client_ip']);
        $this->assertTrue($value['is_https']);
    }

    public function test_docker_proxy_config_rejects_forwarded_https_from_untrusted_peer(): void
    {
        $value = $this->runDockerConfigSecurityProbe(<<<'PHP'
$_SERVER['REMOTE_ADDR'] = '172.23.0.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.11';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
return [
    'client_ip' => app_client_ip(),
    'is_https' => app_is_https(),
];
PHP);

        $this->assertSame('172.23.0.10', $value['client_ip']);
        $this->assertFalse($value['is_https']);
    }

    public function test_app_normalize_host_rejects_malformed_values(): void
    {
        $value = $this->runSecurityProbe(<<<'PHP'
return app_normalize_host("bad host.example");
PHP);

        $this->assertSame(null, $value);
    }

    public function test_is_valid_offer_url_rejects_credentials_and_control_characters(): void
    {
        $value = $this->runSecurityProbe(<<<'PHP'
return [
    is_valid_offer_url('https://user:pass@example.com/offer'),
    is_valid_offer_url("https://example.com/\tbad"),
    is_valid_offer_url('https://example.com/offer'),
];
PHP);

        $this->assertSame([false, false, true], $value);
    }

    public function test_default_db_path_is_outside_application_tree(): void
    {
        $value = $this->runConfigProbe(<<<'PHP'
return [
    'db_path' => DB_PATH,
    'app_root' => $appDir,
];
PHP);

        $this->assertFalse(str_starts_with($value['db_path'], $value['app_root'] . DIRECTORY_SEPARATOR));
    }

    /**
     * @return mixed
     */
    private function runSecurityProbe(string $body)
    {
        $runtimeDir = $this->tempDir('cloaking-security-');
        $scriptPath = $runtimeDir . DIRECTORY_SEPARATOR . 'probe.php';

        $script = <<<PHP
<?php
require_once %s;
\$result = (static function () {
%s
})();
echo json_encode(\$result, JSON_UNESCAPED_SLASHES);
PHP;

        file_put_contents($scriptPath, sprintf(
            $script,
            var_export(APP_ROOT . '/includes/security.php', true),
            $body
        ));

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath);
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Security probe failed: ' . implode("\n", $output));
        }

        $decoded = json_decode(implode("\n", $output), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Security probe returned invalid JSON: ' . implode("\n", $output));
        }

        return $decoded;
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

    /**
     * @return mixed
     */
    private function runConfigProbe(string $body)
    {
        $runtimeDir = $this->tempDir('cloaking-config-');
        $appDir = $runtimeDir . DIRECTORY_SEPARATOR . 'app';
        mkdir($appDir, 0700, true);

        $configPath = $appDir . DIRECTORY_SEPARATOR . 'config.php';
        copy(APP_ROOT . '/config.php', $configPath);

        $scriptPath = $runtimeDir . DIRECTORY_SEPARATOR . 'probe.php';
        $script = <<<PHP
<?php
\$appDir = dirname(%s);
require_once %s;
\$result = ((function () use (\$appDir) {
%s
})());
echo json_encode(\$result, JSON_UNESCAPED_SLASHES);
PHP;

        file_put_contents($scriptPath, sprintf(
            $script,
            var_export($configPath, true),
            var_export($configPath, true),
            $body
        ));

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath);
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Config probe failed: ' . implode("\n", $output));
        }

        $decoded = json_decode(implode("\n", $output), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Config probe returned invalid JSON: ' . implode("\n", $output));
        }

        return $decoded;
    }

    /**
     * @return mixed
     */
    private function runDockerConfigSecurityProbe(string $body)
    {
        $runtimeDir = $this->tempDir('cloaking-docker-config-');
        $scriptPath = $runtimeDir . DIRECTORY_SEPARATOR . 'probe.php';

        $script = <<<PHP
<?php
require_once %s;
require_once %s;
\$result = (static function () {
%s
})();
echo json_encode(\$result, JSON_UNESCAPED_SLASHES);
PHP;

        file_put_contents($scriptPath, sprintf(
            $script,
            var_export(APP_ROOT . '/docker/config.local.php', true),
            var_export(APP_ROOT . '/includes/security.php', true),
            $body
        ));

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath);
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Docker config probe failed: ' . implode("\n", $output));
        }

        $decoded = json_decode(implode("\n", $output), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Docker config probe returned invalid JSON: ' . implode("\n", $output));
        }

        return $decoded;
    }

    /**
     * @return list<int>
     */
    private function runParallelRateLimitProbe(int $rounds, int $workers, int $max): array
    {
        $runtimeDir = $this->tempDir('cloaking-rate-limit-');
        $initScript = $runtimeDir . DIRECTORY_SEPARATOR . 'init.php';
        $workerScript = $runtimeDir . DIRECTORY_SEPARATOR . 'worker.php';

        file_put_contents($initScript, sprintf(
            <<<'PHP'
<?php
define('DB_PATH', $argv[1]);
require_once %s;
setupDatabase();
PHP,
            var_export(APP_ROOT . '/includes/database.php', true)
        ));

        file_put_contents($workerScript, sprintf(
            <<<'PHP'
<?php
define('DB_PATH', $argv[1]);
require_once %s;
require_once %s;

$target = (float) $argv[2];
$key = (string) $argv[3];
$max = (int) $argv[4];

while (microtime(true) < $target) {
}

echo rate_limit($key, $max, 60) ? "1\n" : "0\n";
PHP,
            var_export(APP_ROOT . '/includes/database.php', true),
            var_export(APP_ROOT . '/includes/security.php', true)
        ));

        $results = [];
        for ($round = 0; $round < $rounds; $round++) {
            $dbPath = $runtimeDir . DIRECTORY_SEPARATOR . 'round-' . $round . '.sqlite';
            $this->runPhpCommand([PHP_BINARY, $initScript, $dbPath], 'Failed to initialize rate-limit fixture database');

            $target = microtime(true) + 0.8;
            $processes = [];
            $stdoutFiles = [];
            for ($worker = 0; $worker < $workers; $worker++) {
                $stdoutFiles[$worker] = $runtimeDir . DIRECTORY_SEPARATOR . sprintf('round-%d-worker-%d.out', $round, $worker);
                $stderrFile = $runtimeDir . DIRECTORY_SEPARATOR . sprintf('round-%d-worker-%d.err', $round, $worker);
                $command = [
                    PHP_BINARY,
                    $workerScript,
                    $dbPath,
                    (string) $target,
                    'parallel-limit-key',
                    (string) $max,
                ];
                $descriptorSpec = [
                    0 => ['pipe', 'r'],
                    1 => ['file', $stdoutFiles[$worker], 'w'],
                    2 => ['file', $stderrFile, 'w'],
                ];
                $processes[$worker] = proc_open($command, $descriptorSpec, $pipes, APP_ROOT);
                if (!is_resource($processes[$worker])) {
                    throw new RuntimeException('Unable to spawn parallel rate-limit probe worker');
                }
                if (isset($pipes[0]) && is_resource($pipes[0])) {
                    fclose($pipes[0]);
                }
            }

            foreach ($processes as $process) {
                proc_close($process);
            }

            $allowedCount = 0;
            foreach ($stdoutFiles as $stdoutFile) {
                $allowedCount += (int) trim((string) file_get_contents($stdoutFile));
            }
            $results[] = $allowedCount;
        }

        return $results;
    }

    /**
     * @param list<string> $command
     */
    private function runPhpCommand(array $command, string $errorPrefix): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, APP_ROOT);
        if (!is_resource($process)) {
            throw new RuntimeException($errorPrefix . ': unable to start process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException($errorPrefix . ': ' . trim($stdout . "\n" . $stderr));
        }
    }
}
