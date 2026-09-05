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

    private function intelTransport(array $data): callable
    {
        return static fn (): string => json_encode($data, JSON_UNESCAPED_SLASHES) ?: '';
    }

    public function test_cloudflare_country_satisfies_geo_rule_without_adapter_call(): void
    {
        $called = false;
        $detector = new BotDetector(
            '198.51.100.20',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36',
            [
                'accept' => 'text/html',
                'language' => 'en-US',
                'referer' => 'https://example.test',
                'cf_ipcountry' => 'US',
            ],
            function () use (&$called): string {
                $called = true;

                return '{}';
            }
        );
        $evaluation = $detector->evaluate([
            'allowed_countries' => 'US',
            'blocked_countries' => '',
            'block_datacenters' => 0,
            'block_vpn' => 0,
            'block_review_infra' => 0,
            'block_tor' => 0,
            'block_bots' => 0,
            'block_headless' => 0,
            'block_curl' => 0,
            'fast_mode' => 0,
        ]);

        $this->assertTrue($evaluation['allowed']);
        $this->assertFalse($called, 'Adapter must not be called when Cloudflare country satisfies the geo rule');
    }

    public function test_cloudflare_asn_classifies_review_infrastructure_without_adapter_call(): void
    {
        $called = false;
        $detector = new BotDetector(
            '198.51.100.20',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36',
            [
                'accept' => 'text/html',
                'language' => 'en-US',
                'referer' => 'https://example.test',
                'cf_ipcountry' => 'US',
                'cf_ipasn' => 'AS15169',
            ],
            function () use (&$called): string {
                $called = true;

                return '{}';
            }
        );
        $evaluation = $detector->evaluate([
            'block_review_infra' => 1,
            'block_datacenters' => 0,
            'block_vpn' => 0,
            'block_tor' => 0,
            'block_bots' => 0,
            'block_headless' => 0,
            'block_curl' => 0,
            'fast_mode' => 0,
        ]);
        $result = $detector->getResult();

        $this->assertFalse($evaluation['allowed']);
        $this->assertTrue(in_array('review_infrastructure', $evaluation['reasons'], true));
        $this->assertSame('google', $result['review_platform'] ?? null);
        $this->assertFalse($called, 'Adapter must not be called when Cloudflare ASN satisfies the rule');
    }

    public function test_vpn_rule_still_queries_adapter_even_with_cloudflare_headers(): void
    {
        $called = false;
        $detector = new BotDetector(
            '198.51.100.20',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36',
            [
                'accept' => 'text/html',
                'language' => 'en-US',
                'referer' => 'https://example.test',
                'cf_ipcountry' => 'US',
                'cf_ipasn' => 'AS15169',
            ],
            function () use (&$called): string {
                $called = true;

                return json_encode([
                    'ip' => '198.51.100.20',
                    'asn' => 'AS64500',
                    'country_code' => 'US',
                    'is_proxy' => true,
                    'is_hosting' => false,
                ], JSON_UNESCAPED_SLASHES) ?: '';
            }
        );
        $evaluation = $detector->evaluate([
            'block_vpn' => 1,
            'block_datacenters' => 0,
            'block_review_infra' => 0,
            'block_tor' => 0,
            'block_bots' => 0,
            'block_headless' => 0,
            'block_curl' => 0,
            'fast_mode' => 0,
        ]);

        $this->assertTrue($called, 'VPN rules require proxy flags only the adapter can provide');
        $this->assertFalse($evaluation['allowed']);
        $this->assertTrue(in_array('vpn_or_proxy', $evaluation['reasons'], true));
    }

    public function test_geo_rule_fails_closed_when_cloudflare_headers_absent_and_adapter_unavailable(): void
    {        if (!defined('IP_INTELLIGENCE_FAILURE_MODE')) {
            define('IP_INTELLIGENCE_FAILURE_MODE', 'closed');
        }

        $detector = new BotDetector(
            '1.1.1.1',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36',
            ['accept' => 'text/html', 'language' => 'en-US', 'referer' => 'https://example.test'],
            static fn (): false => false
        );
        $evaluation = $detector->evaluate([
            'allowed_countries' => 'US',
            'blocked_countries' => '',
            'block_datacenters' => 0,
            'block_vpn' => 0,
            'block_review_infra' => 0,
            'block_tor' => 0,
            'block_bots' => 0,
            'block_headless' => 0,
            'block_curl' => 0,
            'fast_mode' => 0,
        ]);

        $this->assertFalse($evaluation['allowed']);
        $this->assertTrue(in_array('ip_intelligence_unavailable', $evaluation['reasons'], true));
    }

    public function test_ip_allowlist_overrides_all_rules(): void
    {
        $detector = new BotDetector(
            '198.51.100.20',
            'curl/8.4.0',
            ['accept' => '', 'language' => '', 'referer' => ''],
            static fn (): false => false
        );
        $evaluation = $detector->evaluate([
            'ip_allowlist' => "192.0.2.1\n198.51.100.0/24",
            'block_bots' => 1,
            'block_curl' => 1,
            'block_datacenters' => 1,
            'block_review_infra' => 1,
            'allowed_countries' => 'US',
            'block_ipv6' => 1,
            'block_tor' => 0,
            'block_vpn' => 0,
            'block_headless' => 0,
            'fast_mode' => 1,
        ]);

        $this->assertTrue($evaluation['allowed'], 'A whitelisted IP must bypass every rule');
        $this->assertSame([], $evaluation['reasons']);
    }

    public function test_ip_allowlist_does_not_match_other_ips(): void
    {
        $detector = new BotDetector(
            '203.0.113.10',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36',
            ['accept' => 'text/html', 'language' => 'en-US', 'referer' => 'https://example.test'],
            static fn (): false => false
        );
        $evaluation = $detector->evaluate([
            'ip_allowlist' => '198.51.100.0/24',
            'block_bots' => 1,
            'block_datacenters' => 0,
            'block_review_infra' => 0,
            'block_tor' => 0,
            'block_vpn' => 0,
            'block_headless' => 0,
            'block_curl' => 0,
            'fast_mode' => 1,
        ]);

        $this->assertTrue($evaluation['allowed']);
    }

    public function test_block_ipv6_denies_ipv6_clients(): void
    {        $detector = new BotDetector(
            '2001:db8::1',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36',
            ['accept' => 'text/html', 'language' => 'en-US', 'referer' => 'https://example.test'],
            static fn (): false => false
        );
        $evaluation = $detector->evaluate([
            'block_ipv6' => 1,
            'block_bots' => 0,
            'block_datacenters' => 0,
            'block_review_infra' => 0,
            'block_tor' => 0,
            'block_vpn' => 0,
            'block_headless' => 0,
            'block_curl' => 0,
            'fast_mode' => 1,
        ]);

        $this->assertFalse($evaluation['allowed']);
        $this->assertTrue(in_array('ipv6_blocked', $evaluation['reasons'], true));
    }

    public function test_browser_detection_and_rules(): void
    {
        $this->assertSame('chrome', BotDetector::detectBrowser('Mozilla/5.0 (Macintosh) Chrome/151.0.0.0 Safari/537.36'));
        $this->assertSame('safari', BotDetector::detectBrowser('Mozilla/5.0 (iPhone) Version/17.0 Mobile/15E148 Safari/604.1'));
        $this->assertSame('firefox', BotDetector::detectBrowser('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0'));
        $this->assertSame('edge', BotDetector::detectBrowser('Mozilla/5.0 (Windows NT 10.0) Edg/125.0.0.0'));
        $this->assertSame('', BotDetector::detectBrowser('curl/8.4.0'));

        $detector = new BotDetector('198.51.100.20', 'Mozilla/5.0 (Windows NT 10.0) Edg/125.0.0.0', [
            'accept' => 'text/html', 'language' => 'en-US', 'referer' => 'https://example.test',
        ], static fn (): false => false);
        $evaluation = $detector->evaluate([
            'allowed_browsers' => 'chrome,safari',
            'block_bots' => 0, 'block_datacenters' => 0, 'block_review_infra' => 0,
            'block_tor' => 0, 'block_vpn' => 0, 'block_headless' => 0, 'block_curl' => 0, 'fast_mode' => 1,
        ]);
        $this->assertFalse($evaluation['allowed']);
        $this->assertTrue(in_array('browser_not_allowed', $evaluation['reasons'], true));

        $evaluation = $detector->evaluate([
            'blocked_browsers' => 'edge',
            'block_bots' => 0, 'block_datacenters' => 0, 'block_review_infra' => 0,
            'block_tor' => 0, 'block_vpn' => 0, 'block_headless' => 0, 'block_curl' => 0, 'fast_mode' => 1,
        ]);
        $this->assertFalse($evaluation['allowed']);
        $this->assertTrue(in_array('browser_blocked', $evaluation['reasons'], true));
    }

    public function test_ip_blocklist_denies_listed_ips(): void
    {
        $detector = new BotDetector('203.0.113.10', 'Mozilla/5.0 (X11; Linux x86_64) Chrome/140 Safari/537.36', [
            'accept' => 'text/html', 'language' => 'en-US', 'referer' => 'https://example.test',
        ], static fn (): false => false);
        $evaluation = $detector->evaluate([
            'ip_blocklist' => "198.51.100.0/24\n203.0.113.10",
            'block_bots' => 0, 'block_datacenters' => 0, 'block_review_infra' => 0,
            'block_tor' => 0, 'block_vpn' => 0, 'block_headless' => 0, 'block_curl' => 0, 'fast_mode' => 1,
        ]);
        $this->assertFalse($evaluation['allowed']);
        $this->assertTrue(in_array('ip_blocked', $evaluation['reasons'], true));
    }
}
