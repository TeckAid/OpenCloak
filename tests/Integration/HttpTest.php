<?php

require_once __DIR__ . '/../bootstrap.php';

final class HttpTest extends TestCase
{
    public function test_fresh_admin_request_does_not_create_any_users(): void
    {
        $runtime = $this->createRuntimeApp(null, [], true);

        try {
            $server = $this->startRuntimeServer($runtime['docroot']);
            $response = $this->httpRequest($server['port'], 'GET', '/admin/login.php');
            $this->stopServer($server['process']);

            $db = new PDO('sqlite:' . $runtime['dbPath']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();

            $this->assertSame(200, $response['status']);
            $this->assertSame(0, $count);
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_uninitialized_admin_request_does_not_create_schema(): void
    {
        $runtime = $this->createRuntimeApp(null, [], false);

        try {
            $server = $this->startRuntimeServer($runtime['docroot']);
            $response = $this->httpRequest($server['port'], 'GET', '/admin/login.php');
            $this->stopServer($server['process']);

            $db = new PDO('sqlite:' . $runtime['dbPath']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $usersTable = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();

            $this->assertSame(500, $response['status']);
            $this->assertSame(false, $usersTable);
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_initialized_admin_request_does_not_mutate_schema(): void
    {
        $runtime = $this->createRuntimeApp(null, [], true);

        try {
            $db = new PDO('sqlite:' . $runtime['dbPath']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $before = (int) $db->query('PRAGMA schema_version')->fetchColumn();

            $server = $this->startRuntimeServer($runtime['docroot']);
            $response = $this->httpRequest($server['port'], 'GET', '/admin/login.php');
            $this->stopServer($server['process']);

            $after = (int) $db->query('PRAGMA schema_version')->fetchColumn();

            $this->assertSame(200, $response['status']);
            $this->assertSame($before, $after);
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_install_creates_first_administrator_once_without_hidden_default_account(): void
    {
        $runtime = $this->createRuntimeApp(null, [], false);

        try {
            $firstRun = $this->runInstallCommand($runtime, ['--username=owner', '--generate-password']);
            preg_match('/Generated password:\s+(\S+)/', $firstRun['stdout'], $matches);
            $generatedPassword = $matches[1] ?? '';

            $this->assertSame(0, $firstRun['exit']);
            $this->assertTrue($generatedPassword !== '', 'Installer did not print a generated password.');

            $db = new PDO('sqlite:' . $runtime['dbPath']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $users = $db->query('SELECT username, password, must_change_password FROM users ORDER BY id')->fetchAll();
            $this->assertSame(1, count($users), 'Installer should create exactly one administrator.');
            $this->assertSame('owner', $users[0]['username']);
            $this->assertTrue(password_verify($generatedPassword, $users[0]['password']));
            $this->assertSame(0, (int) $users[0]['must_change_password']);

            $secondRun = $this->runInstallCommand($runtime, ['--username=second-owner', '--password=AnotherStrong123!']);

            $this->assertSame(1, $secondRun['exit']);
            $this->assertTrue(str_contains($secondRun['stderr'] . $secondRun['stdout'], 'already'));

            $rows = $db->query('SELECT username FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            $this->assertSame(['owner'], $rows);
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_default_admin_credentials_cannot_authenticate_after_custom_install(): void
    {
        $runtime = $this->createRuntimeApp(null, [], false);

        try {
            $install = $this->runInstallCommand($runtime, ['--username=owner', '--password=StrongPass123!']);
            $this->assertSame(0, $install['exit']);

            $server = $this->startRuntimeServer($runtime['docroot']);
            $loginPage = $this->httpRequest($server['port'], 'GET', '/admin/login.php');
            $csrf = $this->extractCsrfToken($loginPage['body']);
            $sessionCookie = $this->extractCookieHeader($loginPage['headers'], 'cloaksess');

            $response = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/login.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $sessionCookie,
                ],
                http_build_query([
                    '_csrf' => $csrf,
                    'username' => 'admin',
                    'password' => 'admin',
                ])
            );

            $this->stopServer($server['process']);

            $this->assertSame(200, $response['status']);
            $this->assertTrue(str_contains($response['body'], 'Invalid username or password.'));
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_admin_login_rejects_post_without_csrf_token(): void
    {
        $runtime = $this->createRuntimeApp(null, [], true);

        try {
            $server = $this->startRuntimeServer($runtime['docroot']);
            $response = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/login.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                http_build_query([
                    'username' => 'admin',
                    'password' => 'admin',
                ])
            );
            $this->stopServer($server['process']);

            $this->assertSame(403, $response['status']);
            $this->assertTrue(str_contains($response['body'], 'Invalid or expired security token.'));
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_login_rate_limit_blocks_second_attempt_for_same_account_from_different_ip(): void
    {
        $runtime = $this->createRuntimeApp(
            static function (PDO $db): void {
                $db->prepare('INSERT INTO users (username, password, api_key, must_change_password) VALUES (?, ?, ?, 0)')
                    ->execute([
                        'owner',
                        password_hash('StrongPass123!', PASSWORD_DEFAULT),
                        'account-rate-limit-key',
                    ]);
            },
            [
                'LOGIN_MAX_ATTEMPTS' => 1,
                'TRUSTED_PROXIES' => ['127.0.0.1/32'],
            ]
        );

        try {
            $server = $this->startRuntimeServer($runtime['docroot']);

            $firstLoginPage = $this->httpRequest(
                $server['port'],
                'GET',
                '/admin/login.php',
                ['X-Forwarded-For' => '198.51.100.10']
            );
            $firstResponse = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/login.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $this->extractCookieHeader($firstLoginPage['headers'], 'cloaksess'),
                    'X-Forwarded-For' => '198.51.100.10',
                ],
                http_build_query([
                    '_csrf' => $this->extractCsrfToken($firstLoginPage['body']),
                    'username' => 'owner',
                    'password' => 'WrongPassword123!',
                ])
            );

            $secondLoginPage = $this->httpRequest(
                $server['port'],
                'GET',
                '/admin/login.php',
                ['X-Forwarded-For' => '198.51.100.11']
            );
            $secondResponse = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/login.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $this->extractCookieHeader($secondLoginPage['headers'], 'cloaksess'),
                    'X-Forwarded-For' => '198.51.100.11',
                ],
                http_build_query([
                    '_csrf' => $this->extractCsrfToken($secondLoginPage['body']),
                    'username' => 'owner',
                    'password' => 'WrongPassword123!',
                ])
            );

            $this->stopServer($server['process']);

            $this->assertSame(200, $firstResponse['status']);
            $this->assertTrue(str_contains($firstResponse['body'], 'Invalid username or password.'));
            $this->assertSame(200, $secondResponse['status']);
            $this->assertTrue(str_contains($secondResponse['body'], 'Too many login attempts.'));
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_admin_login_page_is_not_cacheable_and_sets_session_cookie_attributes(): void
    {
        $runtime = $this->createRuntimeApp(null, [], true);

        try {
            $server = $this->startRuntimeServer($runtime['docroot']);
            $response = $this->httpRequest($server['port'], 'GET', '/admin/login.php');
            $this->stopServer($server['process']);

            $sessionCookie = $this->findSetCookie($response['headers'], 'cloaksess');

            $this->assertSame(200, $response['status']);
            $this->assertTrue(str_contains((string) ($response['headers']['cache-control'] ?? ''), 'no-store'));
            $this->assertTrue(str_contains($sessionCookie, 'HttpOnly'));
            $this->assertTrue(str_contains($sessionCookie, 'SameSite=Lax'));
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_https_admin_session_cookie_is_secure(): void
    {
        $runtime = $this->createRuntimeApp(
            null,
            [
                'TRUSTED_PROXIES' => ['127.0.0.1/32'],
            ],
            true
        );

        try {
            $server = $this->startRuntimeServer($runtime['docroot']);
            $response = $this->httpRequest(
                $server['port'],
                'GET',
                '/admin/login.php',
                ['X-Forwarded-Proto' => 'https']
            );
            $this->stopServer($server['process']);

            $this->assertTrue(str_contains(strtolower($this->findSetCookie($response['headers'], 'cloaksess')), 'secure'));
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_logout_expires_cookie_and_redirects_to_login(): void
    {
        $runtime = $this->createRuntimeApp(
            static function (PDO $db): void {
                $db->prepare('INSERT INTO users (username, password, api_key, must_change_password) VALUES (?, ?, ?, 0)')
                    ->execute([
                        'owner',
                        password_hash('StrongPass123!', PASSWORD_DEFAULT),
                        'logout-api-key',
                    ]);
            }
        );

        try {
            $server = $this->startRuntimeServer($runtime['docroot']);
            $loginPage = $this->httpRequest($server['port'], 'GET', '/admin/login.php');
            $loginResponse = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/login.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $this->extractCookieHeader($loginPage['headers'], 'cloaksess'),
                ],
                http_build_query([
                    '_csrf' => $this->extractCsrfToken($loginPage['body']),
                    'username' => 'owner',
                    'password' => 'StrongPass123!',
                ])
            );

            $postLoginCookie = $this->extractCookieHeader($loginResponse['headers'], 'cloaksess');
            if ($postLoginCookie === '') {
                $postLoginCookie = $this->extractCookieHeader($loginPage['headers'], 'cloaksess');
            }

            $dashboard = $this->httpRequest(
                $server['port'],
                'GET',
                '/admin/dashboard.php',
                ['Cookie' => $postLoginCookie]
            );

            $logoutResponse = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/dashboard.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $postLoginCookie,
                ],
                http_build_query([
                    '_csrf' => $this->extractCsrfToken($dashboard['body']),
                    'action' => 'logout',
                ])
            );

            $this->stopServer($server['process']);

            $this->assertSame(302, $loginResponse['status']);
            $this->assertSame(200, $dashboard['status']);
            $this->assertTrue(str_contains((string) ($dashboard['headers']['cache-control'] ?? ''), 'no-store'));
            $this->assertSame(302, $logoutResponse['status']);
            $this->assertSame('/admin/login.php', $logoutResponse['headers']['location'] ?? null);
            $expiredCookie = $this->findSetCookie($logoutResponse['headers'], 'cloaksess');
            $this->assertTrue(
                str_contains($expiredCookie, 'Max-Age=0') || str_contains($expiredCookie, 'Expires=Thu, 01 Jan 1970'),
                'Logout should expire the session cookie.'
            );
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_install_endpoint_refuses_http_execution(): void
    {
        $runtime = $this->createRuntimeApp(null, [], false);

        try {
            $server = $this->startRuntimeServer($runtime['docroot']);
            $response = $this->httpRequest($server['port'], 'GET', '/install.php');
            $this->stopServer($server['process']);

            $this->assertSame(404, $response['status']);
            $this->assertSame('', $response['body']);
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_api_rejects_malformed_offer_url_host(): void
    {
        $response = $this->requestSeeded(
            'POST',
            '/api/links',
            static function (PDO $db): void {
                $db->prepare('INSERT INTO users (username, password, api_key, must_change_password) VALUES (?, ?, ?, 0)')
                    ->execute(['api-user', password_hash('StrongPass123!', PASSWORD_DEFAULT), 'test-api-key']);
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
        $this->assertTrue(str_contains($response['body'], 'Invalid offer_url'));
    }

    public function test_api_rejects_array_clone_name(): void
    {
        $response = $this->requestSeeded(
            'POST',
            '/api/campaigns/1/clone',
            static function (PDO $db): void {
                $db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
                    ->execute([1, 'api-user', password_hash('StrongPass123!', PASSWORD_DEFAULT), 'test-api-key']);
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
                $db->prepare('INSERT INTO users (username, password, api_key, must_change_password) VALUES (?, ?, ?, 0)')
                    ->execute(['api-user', password_hash('StrongPass123!', PASSWORD_DEFAULT), 'test-api-key']);
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
        $this->assertTrue(str_contains((string) ($response['headers']['cache-control'] ?? ''), 'no-store'));
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

    public function test_admin_campaign_form_create_and_update_persist_all_fields(): void
    {
        $runtime = $this->createRuntimeApp(
            static function (PDO $db): void {
                $db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
                    ->execute([1, 'owner', password_hash('StrongPass123!', PASSWORD_DEFAULT), 'owner-api-key']);
            }
        );

        try {
            $db = new PDO('sqlite:' . $runtime['dbPath']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $server = $this->startRuntimeServer($runtime['docroot']);
            $cookie = $this->loginToAdmin($server['port']);

            $campaignPage = $this->httpRequest($server['port'], 'GET', '/admin/campaigns.php', ['Cookie' => $cookie]);
            $csrf = $this->extractCsrfToken($campaignPage['body']);

            $createResponse = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/campaigns.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $cookie,
                ],
                http_build_query([
                    '_csrf' => $csrf,
                    'action' => 'create',
                    'name' => 'Campaign One',
                    'offer_url' => 'https://offers.example/base',
                    'white_page' => '<p>white</p>',
                    'reject_mode' => 'error',
                    'reject_code' => '451',
                    'redirect_type' => '303',
                    'redirect_delay' => '7',
                    'block_bots' => '1',
                    'block_vpn' => '1',
                    'block_headless' => '1',
                    'allowed_countries' => 'US,CA',
                    'blocked_countries' => 'CN',
                    'allowed_clients' => 'facebook,instagram',
                    'blocked_clients' => 'tiktok',
                    'allowed_devices' => 'mobile',
                    'blocked_devices' => 'desktop',
                    'allowed_os' => 'iOS,Android',
                    'blocked_os' => 'Windows',
                    'os_min_versions' => 'iOS>=14.9,Android>=10',
                    'allowed_languages' => 'en',
                    'blocked_languages' => 'ru',
                    'allowed_referrers' => 'https://facebook.com/*',
                    'blocked_referrers' => 'https://evil.example/*',
                    'required_url_params' => 'utm_source=*',
                    'blocked_url_params' => 'utm_bad=*',
                    'required_url_keywords' => 'cid,fbclid',
                    'allowed_resolutions' => 'iphone',
                    'blocked_resolutions' => 'tablet',
                    'require_screen_info' => '1',
                    'offer_urls' => "https://offers.example/a\nhttps://offers.example/b",
                    'rotation_mode' => 'sequential',
                    'offer_routes' => "US=https://offers.example/us\n*=https://offers.example/world",
                    'offer_method' => 'iframe',
                    'forward_utms' => '1',
                    'fast_mode' => '1',
                    'delay_start' => '33',
                    'allow_geo_override' => '1',
                ])
            );

            $this->assertSame(200, $createResponse['status']);
            $this->assertRowMatches(
                $db->query('SELECT * FROM campaigns WHERE id = 1')->fetch(),
                [
                    'name' => 'Campaign One',
                    'is_active' => 0,
                    'offer_url' => 'https://offers.example/base',
                    'white_page' => '<p>white</p>',
                    'reject_mode' => 'error',
                    'reject_code' => 451,
                    'redirect_type' => '303',
                    'redirect_delay' => 7,
                    'block_bots' => 1,
                    'block_datacenters' => 0,
                    'block_review_infra' => 0,
                    'block_vpn' => 1,
                    'block_tor' => 0,
                    'block_headless' => 1,
                    'block_curl' => 0,
                    'allowed_countries' => 'US,CA',
                    'blocked_countries' => 'CN',
                    'allowed_clients' => 'facebook,instagram',
                    'blocked_clients' => 'tiktok',
                    'allowed_devices' => 'mobile',
                    'blocked_devices' => 'desktop',
                    'allowed_os' => 'iOS,Android',
                    'blocked_os' => 'Windows',
                    'os_min_versions' => 'iOS>=14.9,Android>=10',
                    'allowed_languages' => 'en',
                    'blocked_languages' => 'ru',
                    'allowed_referrers' => 'https://facebook.com/*',
                    'blocked_referrers' => 'https://evil.example/*',
                    'allow_empty_referer' => 0,
                    'required_url_params' => 'utm_source=*',
                    'blocked_url_params' => 'utm_bad=*',
                    'required_url_keywords' => 'cid,fbclid',
                    'allowed_resolutions' => 'iphone',
                    'blocked_resolutions' => 'tablet',
                    'require_screen_info' => 1,
                    'single_visit_only' => 0,
                    'offer_urls' => "https://offers.example/a\nhttps://offers.example/b",
                    'rotation_mode' => 'sequential',
                    'offer_routes' => "US=https://offers.example/us\n*=https://offers.example/world",
                    'offer_method' => 'iframe',
                    'forward_utms' => 1,
                    'no_cache' => 0,
                    'fast_mode' => 1,
                    'delay_start' => 33,
                    'delay_permanent' => 0,
                    'allow_geo_override' => 1,
                ]
            );

            $campaignEditPage = $this->httpRequest($server['port'], 'GET', '/admin/campaigns.php?edit=1', ['Cookie' => $cookie]);
            $updateResponse = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/campaigns.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $cookie,
                ],
                http_build_query([
                    '_csrf' => $this->extractCsrfToken($campaignEditPage['body']),
                    'action' => 'update',
                    'id' => '1',
                    'name' => 'Campaign Updated',
                    'is_active' => '1',
                    'offer_url' => 'https://offers.example/updated',
                    'white_page' => '<div>updated</div>',
                    'reject_mode' => 'white',
                    'reject_code' => '404',
                    'redirect_type' => 'meta',
                    'redirect_delay' => '3',
                    'block_datacenters' => '1',
                    'block_review_infra' => '1',
                    'block_tor' => '1',
                    'block_curl' => '1',
                    'allowed_countries' => 'GB',
                    'blocked_countries' => 'RU,UA',
                    'allowed_clients' => 'threads',
                    'blocked_clients' => 'facebook',
                    'allowed_devices' => 'tablet',
                    'blocked_devices' => 'mobile',
                    'allowed_os' => 'Android',
                    'blocked_os' => 'iOS',
                    'os_min_versions' => 'Android>=14.10',
                    'allowed_languages' => 'fr',
                    'blocked_languages' => 'de',
                    'allowed_referrers' => 'https://threads.net/*',
                    'blocked_referrers' => 'https://blocked.example/*',
                    'allow_empty_referer' => '1',
                    'required_url_params' => 'campaign=*',
                    'blocked_url_params' => 'debug=*',
                    'required_url_keywords' => 'gclid',
                    'allowed_resolutions' => 'tablet',
                    'blocked_resolutions' => 'iphone',
                    'single_visit_only' => '1',
                    'offer_urls' => "https://offers.example/c\nhttps://offers.example/d",
                    'rotation_mode' => 'random',
                    'offer_routes' => "CA=https://offers.example/ca\n*=https://offers.example/fallback",
                    'offer_method' => 'redirect',
                    'no_cache' => '1',
                    'delay_start' => '2',
                    'delay_permanent' => '1',
                ])
            );

            $this->assertSame(200, $updateResponse['status']);
            $this->assertRowMatches(
                $db->query('SELECT * FROM campaigns WHERE id = 1')->fetch(),
                [
                    'name' => 'Campaign Updated',
                    'is_active' => 1,
                    'offer_url' => 'https://offers.example/updated',
                    'white_page' => '<div>updated</div>',
                    'reject_mode' => 'white',
                    'reject_code' => 404,
                    'redirect_type' => 'meta',
                    'redirect_delay' => 3,
                    'block_bots' => 0,
                    'block_datacenters' => 1,
                    'block_review_infra' => 1,
                    'block_vpn' => 0,
                    'block_tor' => 1,
                    'block_headless' => 0,
                    'block_curl' => 1,
                    'allowed_countries' => 'GB',
                    'blocked_countries' => 'RU,UA',
                    'allowed_clients' => 'threads',
                    'blocked_clients' => 'facebook',
                    'allowed_devices' => 'tablet',
                    'blocked_devices' => 'mobile',
                    'allowed_os' => 'Android',
                    'blocked_os' => 'iOS',
                    'os_min_versions' => 'Android>=14.10',
                    'allowed_languages' => 'fr',
                    'blocked_languages' => 'de',
                    'allowed_referrers' => 'https://threads.net/*',
                    'blocked_referrers' => 'https://blocked.example/*',
                    'allow_empty_referer' => 1,
                    'required_url_params' => 'campaign=*',
                    'blocked_url_params' => 'debug=*',
                    'required_url_keywords' => 'gclid',
                    'allowed_resolutions' => 'tablet',
                    'blocked_resolutions' => 'iphone',
                    'require_screen_info' => 0,
                    'single_visit_only' => 1,
                    'offer_urls' => "https://offers.example/c\nhttps://offers.example/d",
                    'rotation_mode' => 'random',
                    'offer_routes' => "CA=https://offers.example/ca\n*=https://offers.example/fallback",
                    'offer_method' => 'redirect',
                    'forward_utms' => 0,
                    'no_cache' => 1,
                    'fast_mode' => 0,
                    'delay_start' => 2,
                    'delay_permanent' => 1,
                    'allow_geo_override' => 0,
                ]
            );

            $this->stopServer($server['process']);
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_admin_link_form_create_and_update_persist_all_fields(): void
    {
        $runtime = $this->createRuntimeApp(
            static function (PDO $db): void {
                $db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
                    ->execute([1, 'owner', password_hash('StrongPass123!', PASSWORD_DEFAULT), 'owner-api-key']);
                $db->prepare("INSERT INTO domains (id, user_id, domain, is_system, is_active) VALUES (1, 1, 'go.example.com', 0, 1)")
                    ->execute();
            }
        );

        try {
            $db = new PDO('sqlite:' . $runtime['dbPath']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $server = $this->startRuntimeServer($runtime['docroot']);
            $cookie = $this->loginToAdmin($server['port']);

            $linksPage = $this->httpRequest($server['port'], 'GET', '/admin/links.php', ['Cookie' => $cookie]);
            $createResponse = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/links.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $cookie,
                ],
                http_build_query([
                    '_csrf' => $this->extractCsrfToken($linksPage['body']),
                    'action' => 'create',
                    'slug' => 'promo',
                    'name' => 'Promo Link',
                    'domain_id' => '1',
                    'offer_url' => 'https://offers.example/link-base',
                    'white_page' => '<p>link white</p>',
                    'redirect_type' => '303',
                    'redirect_delay' => '4',
                    'block_datacenters' => '1',
                    'block_vpn' => '1',
                    'block_tor' => '1',
                    'block_curl' => '1',
                    'allowed_countries' => 'US',
                    'blocked_countries' => 'BR',
                    'allowed_clients' => 'facebook',
                    'blocked_clients' => 'tiktok',
                    'allowed_devices' => 'desktop',
                    'blocked_devices' => 'mobile',
                    'allowed_os' => 'Windows',
                    'blocked_os' => 'Android',
                    'os_min_versions' => 'Windows>=11',
                    'allowed_languages' => 'en',
                    'blocked_languages' => 'es',
                    'allowed_referrers' => 'https://google.com/*',
                    'blocked_referrers' => 'https://evil.example/*',
                    'required_url_params' => 'utm_source=*',
                    'blocked_url_params' => 'utm_bad=*',
                    'required_url_keywords' => 'cid',
                    'allowed_resolutions' => 'pc',
                    'blocked_resolutions' => 'tablet',
                    'single_visit_only' => '1',
                    'offer_urls' => "https://offers.example/link-a\nhttps://offers.example/link-b",
                    'rotation_mode' => 'sequential',
                    'offer_routes' => "US=https://offers.example/us\n*=https://offers.example/world",
                    'offer_method' => 'iframe',
                    'forward_utms' => '1',
                    'no_cache' => '1',
                    'delay_start' => '11',
                    'allow_geo_override' => '1',
                ])
            );

            $this->assertSame(200, $createResponse['status']);
            $this->assertRowMatches(
                $db->query('SELECT * FROM links WHERE id = 1')->fetch(),
                [
                    'slug' => 'promo',
                    'name' => 'Promo Link',
                    'campaign_id' => null,
                    'domain_id' => 1,
                    'offer_url' => 'https://offers.example/link-base',
                    'white_page' => '<p>link white</p>',
                    'is_active' => 0,
                    'redirect_type' => '303',
                    'redirect_delay' => 4,
                    'block_bots' => 0,
                    'block_datacenters' => 1,
                    'block_review_infra' => 0,
                    'block_vpn' => 1,
                    'block_tor' => 1,
                    'block_headless' => 0,
                    'block_curl' => 1,
                    'allowed_countries' => 'US',
                    'blocked_countries' => 'BR',
                    'allowed_clients' => 'facebook',
                    'blocked_clients' => 'tiktok',
                    'allowed_devices' => 'desktop',
                    'blocked_devices' => 'mobile',
                    'allowed_os' => 'Windows',
                    'blocked_os' => 'Android',
                    'os_min_versions' => 'Windows>=11',
                    'allowed_languages' => 'en',
                    'blocked_languages' => 'es',
                    'allowed_referrers' => 'https://google.com/*',
                    'blocked_referrers' => 'https://evil.example/*',
                    'allow_empty_referer' => 0,
                    'required_url_params' => 'utm_source=*',
                    'blocked_url_params' => 'utm_bad=*',
                    'required_url_keywords' => 'cid',
                    'allowed_resolutions' => 'pc',
                    'blocked_resolutions' => 'tablet',
                    'require_screen_info' => 0,
                    'single_visit_only' => 1,
                    'offer_urls' => "https://offers.example/link-a\nhttps://offers.example/link-b",
                    'rotation_mode' => 'sequential',
                    'offer_routes' => "US=https://offers.example/us\n*=https://offers.example/world",
                    'offer_method' => 'iframe',
                    'forward_utms' => 1,
                    'no_cache' => 1,
                    'fast_mode' => 0,
                    'delay_start' => 11,
                    'delay_permanent' => 0,
                    'allow_geo_override' => 1,
                ]
            );

            $linkEditPage = $this->httpRequest($server['port'], 'GET', '/admin/links.php?edit=1', ['Cookie' => $cookie]);
            $updateResponse = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/links.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $cookie,
                ],
                http_build_query([
                    '_csrf' => $this->extractCsrfToken($linkEditPage['body']),
                    'action' => 'update',
                    'id' => '1',
                    'name' => 'Promo Updated',
                    'is_active' => '1',
                    'offer_url' => 'https://offers.example/link-updated',
                    'white_page' => '<div>updated</div>',
                    'redirect_type' => 'meta',
                    'redirect_delay' => '2',
                    'block_bots' => '1',
                    'block_review_infra' => '1',
                    'block_headless' => '1',
                    'allowed_countries' => 'CA',
                    'blocked_countries' => 'MX',
                    'allowed_clients' => 'threads',
                    'blocked_clients' => 'facebook',
                    'allowed_devices' => 'tablet',
                    'blocked_devices' => 'desktop',
                    'allowed_os' => 'iOS',
                    'blocked_os' => 'Windows',
                    'os_min_versions' => 'iOS>=14.10',
                    'allowed_languages' => 'fr',
                    'blocked_languages' => 'de',
                    'allowed_referrers' => 'https://threads.net/*',
                    'blocked_referrers' => 'https://blocked.example/*',
                    'allow_empty_referer' => '1',
                    'required_url_params' => 'campaign=*',
                    'blocked_url_params' => 'debug=*',
                    'required_url_keywords' => 'gclid',
                    'allowed_resolutions' => 'tablet',
                    'blocked_resolutions' => 'iphone',
                    'require_screen_info' => '1',
                    'offer_urls' => "https://offers.example/link-c\nhttps://offers.example/link-d",
                    'rotation_mode' => 'random',
                    'offer_routes' => "CA=https://offers.example/ca\n*=https://offers.example/fallback",
                    'offer_method' => 'redirect',
                    'fast_mode' => '1',
                    'delay_start' => '5',
                    'delay_permanent' => '1',
                ])
            );

            $this->assertSame(200, $updateResponse['status']);
            $this->assertRowMatches(
                $db->query('SELECT * FROM links WHERE id = 1')->fetch(),
                [
                    'slug' => 'promo',
                    'name' => 'Promo Updated',
                    'campaign_id' => null,
                    'domain_id' => null,
                    'offer_url' => 'https://offers.example/link-updated',
                    'white_page' => '<div>updated</div>',
                    'is_active' => 1,
                    'redirect_type' => 'meta',
                    'redirect_delay' => 2,
                    'block_bots' => 1,
                    'block_datacenters' => 0,
                    'block_review_infra' => 1,
                    'block_vpn' => 0,
                    'block_tor' => 0,
                    'block_headless' => 1,
                    'block_curl' => 0,
                    'allowed_countries' => 'CA',
                    'blocked_countries' => 'MX',
                    'allowed_clients' => 'threads',
                    'blocked_clients' => 'facebook',
                    'allowed_devices' => 'tablet',
                    'blocked_devices' => 'desktop',
                    'allowed_os' => 'iOS',
                    'blocked_os' => 'Windows',
                    'os_min_versions' => 'iOS>=14.10',
                    'allowed_languages' => 'fr',
                    'blocked_languages' => 'de',
                    'allowed_referrers' => 'https://threads.net/*',
                    'blocked_referrers' => 'https://blocked.example/*',
                    'allow_empty_referer' => 1,
                    'required_url_params' => 'campaign=*',
                    'blocked_url_params' => 'debug=*',
                    'required_url_keywords' => 'gclid',
                    'allowed_resolutions' => 'tablet',
                    'blocked_resolutions' => 'iphone',
                    'require_screen_info' => 1,
                    'single_visit_only' => 0,
                    'offer_urls' => "https://offers.example/link-c\nhttps://offers.example/link-d",
                    'rotation_mode' => 'random',
                    'offer_routes' => "CA=https://offers.example/ca\n*=https://offers.example/fallback",
                    'offer_method' => 'redirect',
                    'forward_utms' => 0,
                    'no_cache' => 0,
                    'fast_mode' => 1,
                    'delay_start' => 5,
                    'delay_permanent' => 1,
                    'allow_geo_override' => 0,
                ]
            );

            $this->stopServer($server['process']);
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_admin_link_update_rejects_cross_tenant_campaign_and_domain_ids(): void
    {
        $runtime = $this->createRuntimeApp(
            static function (PDO $db): void {
                $db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
                    ->execute([1, 'owner', password_hash('StrongPass123!', PASSWORD_DEFAULT), 'owner-api-key']);
                $db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
                    ->execute([2, 'other', password_hash('StrongPass123!', PASSWORD_DEFAULT), 'other-api-key']);
                $db->prepare("
                    INSERT INTO links (id, user_id, slug, name, offer_url, white_page, is_active, domain_id)
                    VALUES (1, 1, 'promo', 'Promo Link', 'https://offers.example/original', '<p>white</p>', 1, NULL)
                ")->execute();
                $db->prepare("
                    INSERT INTO campaigns (id, user_id, name, is_active, offer_url, white_page, reject_mode, reject_code, redirect_type, redirect_delay)
                    VALUES (9, 2, 'Other Campaign', 1, 'https://offers.example/other', '', 'white', 403, '302', 0)
                ")->execute();
                $db->prepare("INSERT INTO domains (id, user_id, domain, is_system, is_active) VALUES (9, 2, 'other.example.com', 0, 1)")
                    ->execute();
            }
        );

        try {
            $db = new PDO('sqlite:' . $runtime['dbPath']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $server = $this->startRuntimeServer($runtime['docroot']);
            $cookie = $this->loginToAdmin($server['port']);
            $editPage = $this->httpRequest($server['port'], 'GET', '/admin/links.php?edit=1', ['Cookie' => $cookie]);

            $response = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/links.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $cookie,
                ],
                http_build_query([
                    '_csrf' => $this->extractCsrfToken($editPage['body']),
                    'action' => 'update',
                    'id' => '1',
                    'name' => 'Tampered',
                    'campaign_id' => '9',
                    'domain_id' => '9',
                    'offer_url' => 'https://offers.example/tampered',
                ])
            );

            $row = $db->query('SELECT * FROM links WHERE id = 1')->fetch();

            $this->assertSame(400, $response['status']);
            $this->assertSame('Promo Link', (string) $row['name']);
            $this->assertSame(null, $row['campaign_id']);
            $this->assertSame(null, $row['domain_id']);

            $this->stopServer($server['process']);
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_admin_domain_delete_is_blocked_while_links_still_reference_it(): void
    {
        $runtime = $this->createRuntimeApp(
            static function (PDO $db): void {
                $db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
                    ->execute([1, 'owner', password_hash('StrongPass123!', PASSWORD_DEFAULT), 'owner-api-key']);
                $db->prepare("INSERT INTO domains (id, user_id, domain, is_system, is_active) VALUES (1, 1, 'go.example.com', 0, 1)")
                    ->execute();
                $db->prepare("
                    INSERT INTO links (id, user_id, slug, name, offer_url, white_page, is_active, domain_id)
                    VALUES (1, 1, 'promo', 'Promo Link', 'https://offers.example/original', '<p>white</p>', 1, 1)
                ")->execute();
            }
        );

        try {
            $db = new PDO('sqlite:' . $runtime['dbPath']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $server = $this->startRuntimeServer($runtime['docroot']);
            $cookie = $this->loginToAdmin($server['port']);
            $domainsPage = $this->httpRequest($server['port'], 'GET', '/admin/domains.php', ['Cookie' => $cookie]);

            $response = $this->httpRequest(
                $server['port'],
                'POST',
                '/admin/domains.php',
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $cookie,
                ],
                http_build_query([
                    '_csrf' => $this->extractCsrfToken($domainsPage['body']),
                    'action' => 'delete',
                    'id' => '1',
                ])
            );

            $domainCount = (int) $db->query('SELECT COUNT(*) FROM domains WHERE id = 1')->fetchColumn();
            $linkDomainId = $db->query('SELECT domain_id FROM links WHERE id = 1')->fetchColumn();

            $this->assertSame(200, $response['status']);
            $this->assertSame(1, $domainCount);
            $this->assertSame(1, (int) $linkDomainId);

            $this->stopServer($server['process']);
        } finally {
            $this->deleteTree($runtime['root']);
        }
    }

    public function test_api_rejects_malformed_resource_paths_and_wrong_methods(): void
    {
        $response = $this->requestSeeded(
            'GET',
            '/api/links/foo',
            static function (PDO $db): void {
                $db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
                    ->execute([1, 'api-user', password_hash('StrongPass123!', PASSWORD_DEFAULT), 'test-api-key']);
                $db->prepare("
                    INSERT INTO links (id, user_id, slug, name, offer_url, white_page, is_active, domain_id)
                    VALUES (1, 1, 'promo', 'Promo Link', 'https://offers.example/promo', '', 1, NULL)
                ")->execute();
            },
            ['Authorization' => 'Bearer test-api-key']
        );
        $this->assertSame(404, $response['status']);

        $response = $this->requestSeeded(
            'GET',
            '/api/links/1/extra',
            static function (PDO $db): void {
                $db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
                    ->execute([1, 'api-user', password_hash('StrongPass123!', PASSWORD_DEFAULT), 'test-api-key']);
                $db->prepare("
                    INSERT INTO links (id, user_id, slug, name, offer_url, white_page, is_active, domain_id)
                    VALUES (1, 1, 'promo', 'Promo Link', 'https://offers.example/promo', '', 1, NULL)
                ")->execute();
            },
            ['Authorization' => 'Bearer test-api-key']
        );
        $this->assertSame(404, $response['status']);

        $response = $this->requestSeeded(
            'GET',
            '/api/campaigns/1/clone',
            static function (PDO $db): void {
                $db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
                    ->execute([1, 'api-user', password_hash('StrongPass123!', PASSWORD_DEFAULT), 'test-api-key']);
                $db->prepare("
                    INSERT INTO campaigns (id, user_id, name, is_active, offer_url, white_page, reject_mode, reject_code, redirect_type, redirect_delay)
                    VALUES (1, 1, 'Original', 1, 'https://offers.example/original', '', 'white', 403, '302', 0)
                ")->execute();
            },
            ['Authorization' => 'Bearer test-api-key']
        );
        $this->assertSame(405, $response['status']);
    }

    public function test_public_route_supports_303_redirects(): void
    {
        $response = $this->requestSeeded(
            'GET',
            '/promo',
            static function (PDO $db): void {
                $db->prepare("
                    INSERT INTO links (user_id, slug, name, offer_url, white_page, is_active, redirect_type)
                    VALUES (1, 'promo', 'Promo', 'https://offers.example/promo', '', 1, '303')
                ")->execute();
            },
            [
                'User-Agent' => 'Mozilla/5.0',
                'Accept' => 'text/html',
                'Accept-Language' => 'en-US',
            ]
        );

        $this->assertSame(303, $response['status']);
        $this->assertSame('https://offers.example/promo', $response['headers']['location'] ?? '');
    }

    public function test_public_route_falls_back_to_white_page_when_meta_target_is_invalid(): void
    {
        $response = $this->requestSeeded(
            'GET',
            '/promo',
            static function (PDO $db): void {
                $db->prepare("
                    INSERT INTO links (user_id, slug, name, offer_url, white_page, is_active, redirect_type)
                    VALUES (1, 'promo', 'Promo', 'javascript:alert(1)', '<h1>Safe White Page</h1>', 1, 'meta')
                ")->execute();
            },
            [
                'User-Agent' => 'Mozilla/5.0',
                'Accept' => 'text/html',
                'Accept-Language' => 'en-US',
            ]
        );

        $this->assertSame(200, $response['status']);
        $this->assertTrue(str_contains($response['body'], 'Safe White Page'));
        $this->assertFalse(str_contains($response['body'], 'http-equiv="refresh"'));
    }

    private function requestSeeded(
        string $method,
        string $path,
        callable $seed,
        array $headers = [],
        string $body = ''
    ): array {
        $runtime = $this->createRuntimeApp($seed);
        $server = $this->startRuntimeServer($runtime['docroot']);

        try {
            $response = $this->httpRequest($server['port'], $method, $path, $headers, $body);
        } finally {
            $this->stopServer($server['process']);
            $this->deleteTree($runtime['root']);
        }

        return $response;
    }

    /**
     * @param callable(PDO):void|null $seed
     * @param array<string, mixed> $configOverrides
     * @param bool $initializeSchema
     * @return array{root:string,docroot:string,runtimeState:string,dbPath:string}
     */
    private function createRuntimeApp(?callable $seed = null, array $configOverrides = [], bool $initializeSchema = true): array
    {
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
        $this->copyTree(APP_ROOT . '/admin', $docroot . '/admin');
        $this->copyFile(APP_ROOT . '/config.php', $docroot . '/config.php');
        $this->copyFile(APP_ROOT . '/index.php', $docroot . '/index.php');
        $this->copyFile(APP_ROOT . '/dev-router.php', $docroot . '/dev-router.php');
        $this->copyFile(APP_ROOT . '/install.php', $docroot . '/install.php');

        $this->writeConfigOverride(
            $docroot . '/config.local.php',
            $dbPath,
            $logs . DIRECTORY_SEPARATOR,
            $configOverrides
        );
        $this->writeRouterShim($docroot . '/router.php');

        if ($initializeSchema) {
            $this->initializeDatabaseFile($dbPath);
        }

        if ($seed !== null) {
            $db = new PDO('sqlite:' . $dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $seed($db);
            $this->copyFile($dbPath, $docrootData . '/cloaking.db');
        }

        return [
            'root' => $runtime,
            'docroot' => $docroot,
            'runtimeState' => $runtimeState,
            'dbPath' => $dbPath,
        ];
    }

    /**
     * @param array<string, mixed> $extraDefines
     */
    private function writeConfigOverride(string $path, string $dbPath, string $logPath, array $extraDefines = []): void
    {
        $defines = [
            'APP_KEY' => bin2hex(random_bytes(32)),
            'APP_BASE_URL' => 'http://127.0.0.1',
            'SYSTEM_HOSTS' => ['127.0.0.1'],
            'DB_PATH' => $dbPath,
            'LOG_PATH' => $logPath,
        ];

        foreach ($extraDefines as $name => $value) {
            $defines[$name] = $value;
        }

        $lines = ["<?php"];
        foreach ($defines as $name => $value) {
            $lines[] = sprintf("define(%s, %s);", var_export((string) $name, true), var_export($value, true));
        }

        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
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

    private function httpRequest(int $port, string $method, string $path, array $headers = [], string $body = ''): array
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
                'follow_location' => 0,
                'max_redirects' => 0,
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

    private function loginToAdmin(int $port, string $username = 'owner', string $password = 'StrongPass123!'): string
    {
        $loginPage = $this->httpRequest($port, 'GET', '/admin/login.php');
        $response = $this->httpRequest(
            $port,
            'POST',
            '/admin/login.php',
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Cookie' => $this->extractCookieHeader($loginPage['headers'], 'cloaksess'),
            ],
            http_build_query([
                '_csrf' => $this->extractCsrfToken($loginPage['body']),
                'username' => $username,
                'password' => $password,
            ])
        );

        if ($response['status'] !== 302) {
            throw new RuntimeException('Unable to authenticate test administrator.');
        }

        $cookie = $this->extractCookieHeader($response['headers'], 'cloaksess');
        if ($cookie !== '') {
            return $cookie;
        }

        return $this->extractCookieHeader($loginPage['headers'], 'cloaksess');
    }

    private function assertRowMatches(array|false $row, array $expected): void
    {
        if (!is_array($row)) {
            $this->fail('Expected database row, got none.');
        }

        foreach ($expected as $column => $value) {
            if ($value === null) {
                $this->assertSame(null, $row[$column] ?? null, "Column {$column} mismatch");
                continue;
            }

            if (is_int($value)) {
                $this->assertSame($value, (int) ($row[$column] ?? 0), "Column {$column} mismatch");
                continue;
            }

            $this->assertSame($value, (string) ($row[$column] ?? ''), "Column {$column} mismatch");
        }
    }

    /**
     * @return array{port:int,process:resource}
     */
    private function startRuntimeServer(string $docroot): array
    {
        $port = $this->findFreePort();
        $process = $this->startServer($docroot, $port);

        return [
            'port' => $port,
            'process' => $process,
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
     * @param array{root:string,docroot:string,runtimeState:string,dbPath:string} $runtime
     * @param list<string> $arguments
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runInstallCommand(array $runtime, array $arguments): array
    {
        $command = array_merge([PHP_BINARY, $runtime['docroot'] . '/install.php'], $arguments);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $runtime['docroot']);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start install.php for integration test');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit' => $exitCode,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    private function extractCsrfToken(string $body): string
    {
        if (!preg_match('/name="_csrf" value="([^"]+)"/', $body, $matches)) {
            throw new RuntimeException('Unable to locate CSRF token in response body');
        }

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    private function extractCookieHeader(array $headers, string $cookieName): string
    {
        $cookie = $this->findSetCookie($headers, $cookieName);
        if ($cookie === '') {
            return '';
        }

        $parts = explode(';', $cookie, 2);

        return trim($parts[0]);
    }

    private function findSetCookie(array $headers, string $cookieName): string
    {
        $setCookie = $headers['set-cookie'] ?? [];
        $cookies = is_array($setCookie) ? $setCookie : [$setCookie];

        foreach ($cookies as $cookie) {
            if (is_string($cookie) && str_starts_with($cookie, $cookieName . '=')) {
                return $cookie;
            }
        }

        return '';
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
