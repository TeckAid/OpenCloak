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

        public function test_parse_api_create_defaults_is_active_on_for_links_and_campaigns(): void
    {
        $link = parse_link_input([
            'name' => 'Promo',
            'slug' => 'promo',
            'offer_url' => 'https://offers.example/promo',
        ], [], ['source' => 'api']);
        $this->assertSame(1, $link['is_active']);

        $campaign = parse_campaign_input([
            'name' => 'Campaign',
            'offer_url' => 'https://offers.example/base',
        ], [], ['source' => 'api']);
        $this->assertSame(1, $campaign['is_active']);

        // Explicit opt-out still works
        $inactive = parse_link_input([
            'name' => 'Draft',
            'slug' => 'draft',
            'offer_url' => 'https://offers.example/draft',
            'is_active' => 0,
        ], [], ['source' => 'api']);
        $this->assertSame(0, $inactive['is_active']);

        // Rule flags remain explicit-opt-in (not affected by the change)
        $this->assertSame(0, $link['block_bots']);
        $this->assertSame(0, $link['single_visit_only']);
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

    public function test_delete_campaign_safely_fails_closed_when_concurrent_reference_write_is_open(): void
    {
        $db = DatabaseFixture::fresh();
        $this->configureSqliteBusyTimeout($db, 50);
        $this->seedDeleteSafetyFixture($db);

        $writer = $this->openSiblingConnection($db);
        $this->configureSqliteBusyTimeout($writer, 50);
        $writer->exec('BEGIN IMMEDIATE');
        $writer->prepare("
            INSERT INTO links (user_id, slug, name, campaign_id, domain_id, offer_url, white_page, is_active)
            VALUES (1, 'campaign-race', 'Campaign Race', 7, NULL, 'https://offers.example/race', '', 1)
        ")->execute();

        $message = delete_campaign_safely($db, 1, 7);

        $this->assertSame('Campaign could not be deleted safely while related links are being updated. Please retry.', $message);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM campaigns WHERE id = 7')->fetchColumn());

        $writer->rollBack();
    }

    public function test_delete_domain_safely_fails_closed_when_concurrent_reference_write_is_open(): void
    {
        $db = DatabaseFixture::fresh();
        $this->configureSqliteBusyTimeout($db, 50);
        $this->seedDeleteSafetyFixture($db);

        $writer = $this->openSiblingConnection($db);
        $this->configureSqliteBusyTimeout($writer, 50);
        $writer->exec('BEGIN IMMEDIATE');
        $writer->prepare("
            INSERT INTO links (user_id, slug, name, campaign_id, domain_id, offer_url, white_page, is_active)
            VALUES (1, 'domain-race', 'Domain Race', NULL, 8, 'https://offers.example/race', '', 1)
        ")->execute();

        $message = delete_domain_safely($db, 1, 8);

        $this->assertSame('Domain could not be deleted safely while related links are being updated. Please retry.', $message);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM domains WHERE id = 8')->fetchColumn());

        $writer->rollBack();
    }

    private function seedDeleteSafetyFixture(PDO $db): void
    {
        $db->prepare('INSERT INTO users (id, username, password) VALUES (1, ?, ?)')
            ->execute(['owner', password_hash('StrongPass123!', PASSWORD_DEFAULT)]);
        $db->prepare("
            INSERT INTO campaigns (id, user_id, name, is_active, offer_url, white_page)
            VALUES (7, 1, 'Campaign', 1, 'https://offers.example/campaign', '')
        ")->execute();
        $db->prepare("
            INSERT INTO domains (id, user_id, domain, is_system, is_active)
            VALUES (8, 1, 'go.example.com', 0, 1)
        ")->execute();
    }

    private function openSiblingConnection(PDO $db): PDO
    {
        $path = null;
        foreach ($db->query('PRAGMA database_list')->fetchAll() as $row) {
            if (($row['name'] ?? '') === 'main') {
                $path = (string) ($row['file'] ?? '');
                break;
            }
        }

        if ($path === null || $path === '') {
            throw new RuntimeException('Could not resolve SQLite database path.');
        }

        $sibling = new PDO('sqlite:' . $path);
        $sibling->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sibling->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $sibling;
    }

    private function configureSqliteBusyTimeout(PDO $db, int $milliseconds): void
    {
        $db->exec('PRAGMA busy_timeout = ' . (int) $milliseconds);
    }
}
