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

    public function test_delete_campaign_safely_soft_deletes_and_keeps_links_bound(): void
    {
        $db = DatabaseFixture::fresh();
        $db->prepare('INSERT INTO users (id, username, password) VALUES (1, ?, ?)')
            ->execute(['owner', password_hash('StrongPass123!', PASSWORD_DEFAULT)]);
        $db->prepare("
            INSERT INTO campaigns (id, user_id, name, is_active, offer_url, white_page)
            VALUES (7, 1, 'Campaign', 1, 'https://offers.example/campaign', '')
        ")->execute();
        $db->prepare("
            INSERT INTO links (id, user_id, slug, name, campaign_id, offer_url, white_page, is_active)
            VALUES (1, 1, 'bound', 'Bound', 7, 'https://offers.example/campaign', '', 1)
        ")->execute();

        $this->assertSame(null, delete_campaign_safely($db, 1, 7));
        $this->assertSame(1, (int) $db->query("SELECT is_deleted FROM campaigns WHERE id = 7")->fetchColumn());
        // Bound links are untouched; the campaign just stops resolving them
        $this->assertSame(7, (int) $db->query("SELECT campaign_id FROM links WHERE id = 1")->fetchColumn());
        $this->assertSame(null, effective_rules($db, $db->query("SELECT * FROM links WHERE id = 1")->fetch()));
    }

    public function test_delete_domain_safely_soft_deletes(): void
    {
        $db = DatabaseFixture::fresh();
        $db->prepare('INSERT INTO users (id, username, password) VALUES (1, ?, ?)')
            ->execute(['owner', password_hash('StrongPass123!', PASSWORD_DEFAULT)]);
        $db->prepare("
            INSERT INTO domains (id, user_id, domain, is_system, is_active)
            VALUES (8, 1, 'go.example.com', 0, 1)
        ")->execute();

        $this->assertSame(null, delete_domain_safely($db, 1, 8));
        $this->assertSame(1, (int) $db->query("SELECT is_deleted FROM domains WHERE id = 8")->fetchColumn());
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

    public function test_filter_list_check_black_and_white_semantics(): void
    {
        $db = DatabaseFixture::fresh();
        $db->prepare("INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (1, ?, ?, ?, 0)")
           ->execute(["t", "x", "k"]);
        $db->prepare("INSERT INTO filter_lists (id, user_id, name, list_type, list_ips, list_agents, list_providers, list_referers)
                      VALUES (1, 1, ?, ?, ?, ?, ?, ?)")
           ->execute(["Black Spy", "black", "203.0.113.0/24", "puppeteer", "", "evil.example"]);
        $db->prepare("INSERT INTO filter_lists (id, user_id, name, list_type, list_ips, list_agents, list_providers, list_referers)
                      VALUES (2, 1, ?, ?, ?, ?, ?, ?)")
           ->execute(["White TikTok", "white", "198.51.100.0/24", "", "", "tiktok.com"]);

        // Black list: IP match denies
        $this->assertSame("black_list", filter_list_check($db, 1, 1, "203.0.113.77", "Mozilla/5.0", "", ""));
        // Black list: UA match denies
        $this->assertSame("black_list", filter_list_check($db, 1, 1, "8.8.8.8", "Puppeteer/1.0", "", ""));
        // Black list: referer match denies
        $this->assertSame("black_list", filter_list_check($db, 1, 1, "8.8.8.8", "Mozilla/5.0", "https://evil.example/x", ""));
        // Black list: no match passes
        $this->assertSame("", filter_list_check($db, 1, 1, "8.8.8.8", "Mozilla/5.0", "https://ok.example", ""));

        // White list: matching referer passes
        $this->assertSame("", filter_list_check($db, 2, 1, "8.8.8.8", "Mozilla/5.0", "https://tiktok.com/v", ""));
        // White list: no match denies
        $this->assertSame("white_list", filter_list_check($db, 2, 1, "8.8.8.8", "Mozilla/5.0", "https://other.example", ""));

        // Deleted lists never apply
        $db->prepare("UPDATE filter_lists SET is_deleted = 1 WHERE id = 1")->execute();
        $this->assertSame("", filter_list_check($db, 1, 1, "203.0.113.77", "Mozilla/5.0", "", ""));
    }

    public function test_ip_clicks_per_day_cap_counts_and_denies(): void
    {
        $db = DatabaseFixture::fresh();
        $this->assertTrue(ip_clicks_per_day_check($db, true, 1, "198.51.100.10", 2));
        $this->assertTrue(ip_clicks_per_day_check($db, true, 1, "198.51.100.10", 2));
        $this->assertFalse(ip_clicks_per_day_check($db, true, 1, "198.51.100.10", 2));
        // Different scope (link) has its own budget
        $this->assertTrue(ip_clicks_per_day_check($db, false, 1, "198.51.100.10", 2));
        // Zero limit disables the check
        $this->assertTrue(ip_clicks_per_day_check($db, true, 1, "203.0.113.99", 0));
    }

    public function test_refresh_domain_status_persists_resolution(): void
    {
        $db = DatabaseFixture::fresh();
        $db->prepare('INSERT INTO users (id, username, password) VALUES (1, ?, ?)')
            ->execute(['owner', password_hash('StrongPass123!', PASSWORD_DEFAULT)]);
        $db->prepare("INSERT INTO domains (id, user_id, domain, is_system, is_active) VALUES (8, 1, 'localhost', 0, 1)")->execute();

        $status = refresh_domain_status($db, $db->query('SELECT * FROM domains WHERE id = 8')->fetch());
        $this->assertSame('connected', $status['status']);
        $this->assertTrue(count($status['records']) > 0);

        $persisted = $db->query('SELECT dns_status FROM domains WHERE id = 8')->fetchColumn();
        $this->assertSame('connected', $persisted);

        $unknown = refresh_domain_status($db, ['id' => 8, 'domain' => 'this-domain-definitely-does-not-exist-8273.example']);
        $this->assertSame('not_connected', $unknown['status']);
    }
}
