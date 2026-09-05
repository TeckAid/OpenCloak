<?php

require_once __DIR__ . '/../bootstrap.php';
require_once APP_ROOT . '/includes/bot_detector.php';

final class BotDetectorTest extends TestCase
{
    public function test_ip_intelligence_transport_is_authenticated_https_and_validates_subject_ip(): void
    {
        if (!defined('IP_INTELLIGENCE_ENDPOINT')) {
            define('IP_INTELLIGENCE_ENDPOINT', 'https://intel.example.test/v1/lookup');
        }
        if (!defined('IP_INTELLIGENCE_API_KEY')) {
            define('IP_INTELLIGENCE_API_KEY', 'test-intel-key');
        }

        $captured = [];
        $transport = static function (string $url, array $options) use (&$captured): string {
            $captured = ['url' => $url, 'options' => $options];

            return json_encode([
                'ip' => '198.51.100.20',
                'asn' => 'AS14061',
                'country_code' => 'US',
                'is_proxy' => false,
                'is_hosting' => true,
            ], JSON_UNESCAPED_SLASHES) ?: '';
        };

        $result = app_fetch_ip_intelligence('198.51.100.20', $transport);

        $this->assertSame('https://intel.example.test/v1/lookup', $captured['url'] ?? null);
        $this->assertSame('POST', $captured['options']['method'] ?? null);
        $this->assertTrue(str_contains((string) ($captured['options']['headers'] ?? ''), 'Authorization: Bearer test-intel-key'));
        $this->assertTrue(str_contains((string) ($captured['options']['body'] ?? ''), '198.51.100.20'));
        $this->assertSame('198.51.100.20', $result['ip'] ?? null);
        $this->assertSame('AS14061', $result['asn'] ?? null);

        $mismatch = app_fetch_ip_intelligence(
            '198.51.100.20',
            static fn (): string => '{"ip":"198.51.100.21","asn":"AS14061","country_code":"US","is_proxy":false,"is_hosting":true}'
        );
        $this->assertSame(null, $mismatch);
    }

    public function test_ip_intelligence_failure_mode_is_explicit_and_fail_closed_by_default(): void
    {
        if (!defined('IP_INTELLIGENCE_FAILURE_MODE')) {
            define('IP_INTELLIGENCE_FAILURE_MODE', 'closed');
        }

        $detector = new BotDetector(
            '1.1.1.1',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36',
            ['accept' => 'text/html', 'language' => 'en-US', 'referer' => 'https://example.test'],
            static fn (): false => false
        );
        $evaluation = $detector->evaluate([
            'block_datacenters' => 1,
            'block_vpn' => 0,
            'block_review_infra' => 0,
            'block_tor' => 0,
            'block_bots' => 0,
            'block_headless' => 0,
            'block_curl' => 0,
            'fast_mode' => 0,
        ]);
        $result = $detector->getResult();

        $this->assertFalse($evaluation['allowed']);
        $this->assertTrue(in_array('ip_intelligence_unavailable', $evaluation['reasons'], true));
        $this->assertSame('unavailable', $result['ip_intelligence_status'] ?? null);
    }
}
