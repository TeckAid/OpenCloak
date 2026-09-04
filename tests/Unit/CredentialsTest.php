<?php

require_once __DIR__ . '/../bootstrap.php';

final class CredentialsTest extends TestCase
{
    public function test_fresh_database_is_isolated(): void
    {
        $dbOne = DatabaseFixture::fresh();
        $dbOne->exec("INSERT INTO settings (key, value) VALUES ('alpha', 'one')");

        $dbTwo = DatabaseFixture::fresh();
        $row = $dbTwo->query("SELECT value FROM settings WHERE key = 'alpha'")->fetchColumn();

        $this->assertFalse($row !== false);
        $this->assertSame(false, $row);
    }

    public function test_issue_client_credential_creates_scoped_verify_only_row(): void
    {
        $db = DatabaseFixture::fresh();
        $this->seedCampaignOwner($db, 1, 10);

        $token = issue_client_credential($db, 1, 10, 3600);
        $row = $db->query('SELECT * FROM client_credentials')->fetch();
        $auth = authenticate_client_credential($db, $token);

        $this->assertTrue(is_string($token) && $token !== '');
        $this->assertTrue(is_array($row));
        $this->assertSame(1, (int) $row['user_id']);
        $this->assertSame(10, (int) $row['campaign_id']);
        $this->assertSame('active', (string) $row['status']);
        $this->assertSame('verify', (string) $row['scope']);
        $this->assertFalse(str_contains((string) $row['credential_hash'], $token));
        $this->assertTrue(is_array($auth));
        $this->assertSame(10, (int) $auth['campaign_id']);
        $this->assertSame(1, (int) $auth['user_id']);
    }

    public function test_issue_client_credential_rotates_prior_campaign_token(): void
    {
        $db = DatabaseFixture::fresh();
        $this->seedCampaignOwner($db, 1, 10);

        $first = issue_client_credential($db, 1, 10, 3600);
        $second = issue_client_credential($db, 1, 10, 3600);
        $rows = $db->query('SELECT * FROM client_credentials ORDER BY id')->fetchAll();

        $this->assertSame(2, count($rows));
        $this->assertSame('revoked', (string) $rows[0]['status']);
        $this->assertTrue((string) $rows[0]['revoked_at'] !== '');
        $this->assertSame('active', (string) $rows[1]['status']);
        $this->assertSame(null, $rows[1]['revoked_at']);
        $this->assertSame(false, authenticate_client_credential($db, $first));
        $this->assertTrue(is_array(authenticate_client_credential($db, $second)));
    }

    public function test_client_credentials_reject_revoked_expired_and_cross_campaign_use(): void
    {
        $db = DatabaseFixture::fresh();
        $this->seedCampaignOwner($db, 1, 10);
        $this->seedCampaignOwner($db, 2, 20);

        $revoked = issue_client_credential($db, 1, 10, 3600);
        $expired = issue_client_credential($db, 2, 20, -60);
        $active = issue_client_credential($db, 1, 10, 3600);

        $db->prepare("UPDATE client_credentials SET status = 'revoked', revoked_at = CURRENT_TIMESTAMP WHERE campaign_id = 10 AND credential_hash = ?")
            ->execute([client_credential_hash($revoked)]);

        $this->assertSame(false, authenticate_client_credential($db, $revoked));
        $this->assertSame(false, authenticate_client_credential($db, $expired));
        $this->assertSame(10, (int) authenticate_client_credential($db, $active)['campaign_id']);
        $this->assertSame(false, credential_allows_campaign(authenticate_client_credential($db, $active), 20));
        $this->assertSame(true, credential_allows_campaign(authenticate_client_credential($db, $active), 10));
    }

    public function test_signed_visitor_tokens_are_scope_bound_and_tamper_evident(): void
    {
        if (!defined('APP_KEY')) {
            define('APP_KEY', str_repeat('a', 64));
        }

        $token = sign_visitor_token('campaign:10', 'visitor-123');

        $this->assertSame('visitor-123', verify_visitor_token($token, 'campaign:10'));
        $this->assertSame(false, verify_visitor_token($token, 'campaign:11'));
        $this->assertSame(false, verify_visitor_token($token . 'x', 'campaign:10'));
    }

    private function seedCampaignOwner(PDO $db, int $userId, int $campaignId): void
    {
        $db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
            ->execute([$userId, 'user' . $userId, password_hash('StrongPass123!', PASSWORD_DEFAULT), 'api-key-' . $userId]);
        $db->prepare("
            INSERT INTO campaigns (id, user_id, name, is_active, offer_url, white_page, reject_mode, reject_code, redirect_type, redirect_delay)
            VALUES (?, ?, ?, 1, 'https://offers.example/' || ?, '<p>white</p>', 'white', 403, '302', 0)
        ")->execute([$campaignId, $userId, 'Campaign ' . $campaignId, 'campaign-' . $campaignId]);
    }
}
