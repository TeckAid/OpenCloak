<?php

require_once __DIR__ . '/../bootstrap.php';

final class RulesTest extends TestCase
{
    public function test_wildcard_match_list_supports_wildcards(): void
    {
        $this->assertTrue(wildcard_match_list('*.facebook.com,example.com', 'ads.facebook.com'));
        $this->assertFalse(wildcard_match_list('*.facebook.com,example.com', 'news.example.org'));
    }

    public function test_parse_os_min_versions_preserves_dotted_versions(): void
    {
        $this->assertSame(
            ['ios' => '14.9', 'android' => '10'],
            parse_os_min_versions('iOS>=14.9, Android>=10')
        );
    }

    public function test_version_at_least_uses_version_compare_semantics(): void
    {
        $this->assertTrue(version_at_least('14.10', '14.9'));
        $this->assertFalse(version_at_least('14.9', '14.10'));
    }

    public function test_parse_campaign_input_rejects_invalid_offer_pool_and_routes(): void
    {
        $this->assertThrows(
            static fn (): array => parse_campaign_input([
                'name' => 'Campaign',
                'offer_url' => 'https://offers.example/base',
                'offer_urls' => "https://offers.example/a\njavascript:alert(1)",
            ], [], ['source' => 'api']),
            BadRequestException::class,
            'offer_urls'
        );

        $this->assertThrows(
            static fn (): array => parse_campaign_input([
                'name' => 'Campaign',
                'offer_url' => 'https://offers.example/base',
                'offer_routes' => "US=https://offers.example/us\n*=notaurl",
            ], [], ['source' => 'api']),
            BadRequestException::class,
            'offer_routes'
        );
    }

    public function test_parse_link_input_sets_unchecked_form_flags_to_zero_and_accepts_303_redirect(): void
    {
        $parsed = parse_link_input([
            'name' => 'Promo',
            'slug' => 'promo',
            'offer_url' => 'https://offers.example/promo',
            'redirect_type' => '303',
            'offer_urls' => "https://offers.example/a\nhttps://offers.example/b",
            'offer_routes' => "US=https://offers.example/us\n*=https://offers.example/world",
            'offer_method' => 'iframe',
            'rotation_mode' => 'sequential',
            'delay_start' => '9',
        ], [], ['source' => 'form']);

        $this->assertSame('303', $parsed['redirect_type']);
        $this->assertSame(0, $parsed['is_active']);
        $this->assertSame(0, $parsed['block_review_infra']);
        $this->assertSame(0, $parsed['allow_empty_referer']);
        $this->assertSame(0, $parsed['forward_utms']);
        $this->assertSame(0, $parsed['allow_geo_override']);
        $this->assertSame('https://offers.example/promo', $parsed['offer_url']);
    }

    public function test_delay_start_check_blocks_only_first_n_unique_ips_for_nonpermanent_rules(): void
    {
        $db = DatabaseFixture::fresh();

        $rules = ['delay_start' => 2, 'delay_permanent' => 0];

        $this->assertSame('delay_start', delay_start_check($db, 11, true, '198.51.100.1', $rules));
        $this->assertSame('delay_start', delay_start_check($db, 11, true, '198.51.100.2', $rules));
        $this->assertSame('', delay_start_check($db, 11, true, '198.51.100.3', $rules));
        $this->assertSame('', delay_start_check($db, 11, true, '198.51.100.1', $rules));
    }
}
