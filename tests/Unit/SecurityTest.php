<?php

require_once __DIR__ . '/../bootstrap.php';

final class SecurityTest extends TestCase
{
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
}
