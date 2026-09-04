<?php
/**
 * Database initialization, migrations, and maintenance.
 */

function getDB(): PDO
{
    static $db = null;
    if ($db === null) {
        $defaultRuntimeDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cloaking-runtime';
        $dbPath = defined('DB_PATH') ? DB_PATH : $defaultRuntimeDir . DIRECTORY_SEPARATOR . 'cloaking.sqlite';
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
    verifyDatabaseSchema($db);

    return $db;
}

function setupDatabase(): PDO
{
    $db = getDB();

    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            api_key TEXT UNIQUE,
            must_change_password INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // ---- Campaigns: reusable rule + action containers -----------------------
    $db->exec("
        CREATE TABLE IF NOT EXISTS campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            is_active INTEGER DEFAULT 1,
            -- Actions
            offer_url TEXT DEFAULT '',
            white_page TEXT,
            reject_mode TEXT DEFAULT 'white',
            reject_code INTEGER DEFAULT 403,
            redirect_type TEXT DEFAULT '302',
            redirect_delay INTEGER DEFAULT 0,
            -- Offer pool / rotation / geo routes
            offer_urls TEXT DEFAULT '',
            rotation_mode TEXT DEFAULT 'single',
            offer_routes TEXT DEFAULT '',
            -- Delivery & launch controls
            offer_method TEXT DEFAULT 'redirect',
            forward_utms INTEGER DEFAULT 0,
            no_cache INTEGER DEFAULT 0,
            fast_mode INTEGER DEFAULT 0,
            delay_start INTEGER DEFAULT 0,
            delay_permanent INTEGER DEFAULT 0,
            blocked_url_params TEXT DEFAULT '',
            required_url_keywords TEXT DEFAULT '',
            allow_geo_override INTEGER DEFAULT 0,
            -- Bot filters
            block_bots INTEGER DEFAULT 1,
            block_datacenters INTEGER DEFAULT 1,
            block_review_infra INTEGER DEFAULT 1,
            block_vpn INTEGER DEFAULT 0,
            block_tor INTEGER DEFAULT 1,
            block_headless INTEGER DEFAULT 1,
            block_curl INTEGER DEFAULT 1,
            -- Geo
            allowed_countries TEXT DEFAULT '',
            blocked_countries TEXT DEFAULT '',
            -- Client / device / OS
            allowed_clients TEXT DEFAULT '',
            blocked_clients TEXT DEFAULT '',
            allowed_devices TEXT DEFAULT '',
            blocked_devices TEXT DEFAULT '',
            allowed_os TEXT DEFAULT '',
            blocked_os TEXT DEFAULT '',
            os_min_versions TEXT DEFAULT '',
            -- Language
            allowed_languages TEXT DEFAULT '',
            blocked_languages TEXT DEFAULT '',
            -- Referrer
            allowed_referrers TEXT DEFAULT '',
            blocked_referrers TEXT DEFAULT '',
            allow_empty_referer INTEGER DEFAULT 1,
            -- URL params
            required_url_params TEXT DEFAULT '',
            -- Fingerprint / frequency
            allowed_resolutions TEXT DEFAULT '',
            blocked_resolutions TEXT DEFAULT '',
            require_screen_info INTEGER DEFAULT 0,
            single_visit_only INTEGER DEFAULT 0,
            -- Stats
            total_hits INTEGER DEFAULT 0,
            offer_shows INTEGER DEFAULT 0,
            white_shows INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // ---- Domains: system + custom short-link domains ------------------------
    $db->exec("
        CREATE TABLE IF NOT EXISTS domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            domain TEXT UNIQUE NOT NULL,
            is_system INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // ---- Links -----------------------------------------------------------------
    $db->exec("
        CREATE TABLE IF NOT EXISTS links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            slug TEXT UNIQUE NOT NULL,
            name TEXT DEFAULT '',
            campaign_id INTEGER,
            domain_id INTEGER,
            offer_url TEXT NOT NULL,
            white_page TEXT,
            is_active INTEGER DEFAULT 1,
            -- Offer pool / rotation / geo routes
            offer_urls TEXT DEFAULT '',
            rotation_mode TEXT DEFAULT 'single',
            offer_routes TEXT DEFAULT '',
            -- Delivery & launch controls
            offer_method TEXT DEFAULT 'redirect',
            forward_utms INTEGER DEFAULT 0,
            no_cache INTEGER DEFAULT 0,
            fast_mode INTEGER DEFAULT 0,
            delay_start INTEGER DEFAULT 0,
            delay_permanent INTEGER DEFAULT 0,
            blocked_url_params TEXT DEFAULT '',
            required_url_keywords TEXT DEFAULT '',
            allow_geo_override INTEGER DEFAULT 0,
            -- Filters (used when campaign_id is NULL)
            block_bots INTEGER DEFAULT 1,
            block_datacenters INTEGER DEFAULT 1,
            block_review_infra INTEGER DEFAULT 1,
            block_vpn INTEGER DEFAULT 0,
            block_tor INTEGER DEFAULT 1,
            block_headless INTEGER DEFAULT 1,
            block_curl INTEGER DEFAULT 1,
            allowed_countries TEXT DEFAULT '',
            blocked_countries TEXT DEFAULT '',
            allowed_clients TEXT DEFAULT '',
            blocked_clients TEXT DEFAULT '',
            allowed_devices TEXT DEFAULT '',
            blocked_devices TEXT DEFAULT '',
            allowed_os TEXT DEFAULT '',
            blocked_os TEXT DEFAULT '',
            os_min_versions TEXT DEFAULT '',
            allowed_languages TEXT DEFAULT '',
            blocked_languages TEXT DEFAULT '',
            blocked_referrers TEXT DEFAULT '',
            allowed_referrers TEXT DEFAULT '',
            allow_empty_referer INTEGER DEFAULT 1,
            required_url_params TEXT DEFAULT '',
            allowed_resolutions TEXT DEFAULT '',
            blocked_resolutions TEXT DEFAULT '',
            require_screen_info INTEGER DEFAULT 0,
            single_visit_only INTEGER DEFAULT 0,
            -- Redirect settings
            redirect_type TEXT DEFAULT '302',
            redirect_delay INTEGER DEFAULT 0,
            -- Stats
            total_hits INTEGER DEFAULT 0,
            offer_shows INTEGER DEFAULT 0,
            white_shows INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            rkey TEXT PRIMARY KEY,
            count INTEGER DEFAULT 0,
            reset_at INTEGER
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS client_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            campaign_id INTEGER NOT NULL,
            credential_hash TEXT UNIQUE NOT NULL,
            scope TEXT NOT NULL DEFAULT 'verify',
            status TEXT NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
        )
    ");

    // First-N visitor tracking for the delay-start filter
    $db->exec("
        CREATE TABLE IF NOT EXISTS delay_ips (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            link_id INTEGER,
            campaign_id INTEGER,
            ip_hash TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // ---- Migrations (existing databases) --------------------------------------
    migrate($db);

    // ---- Indexes (idempotent) ---------------------------------------------------
    $db->exec("CREATE INDEX IF NOT EXISTS idx_hit_log_link_created ON hit_log(link_id, created_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_hit_log_created ON hit_log(created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_hit_log_campaign ON hit_log(campaign_id, created_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_links_user ON links(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_links_domain ON links(domain_id, slug)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_campaigns_user ON campaigns(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_delay_ips ON delay_ips(campaign_id, link_id, ip_hash)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_client_credentials_campaign ON client_credentials(campaign_id, status, expires_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_client_credentials_hash ON client_credentials(credential_hash)");

    return $db;
}

function verifyDatabaseSchema(PDO $db): void
{
    $requiredTables = ['users', 'campaigns', 'domains', 'links', 'settings', 'rate_limits', 'delay_ips', 'hit_log', 'client_credentials'];

    foreach ($requiredTables as $table) {
        $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('Database schema is not initialized.');
        }
    }
}

/**
 * Schema migrations for databases created by earlier versions.
 */
function migrate(PDO $db): void
{
    $columns = function (string $table) use ($db): array {
        $cols = [];
        foreach ($db->query("PRAGMA table_info({$table})")->fetchAll() as $c) {
            $cols[$c['name']] = $c;
        }
        return $cols;
    };

    $addColumn = function (string $table, string $column, string $ddl) use ($columns, $db): void {
        if (!isset($columns($table)[$column])) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$ddl}");
        }
    };

    // Links: campaign/domain binding
    $addColumn('links', 'campaign_id', 'INTEGER');
    $addColumn('links', 'domain_id', 'INTEGER');

    // Links + campaigns: new rule columns
    foreach ([
        'allowed_clients'    => "TEXT DEFAULT ''",
        'blocked_clients'    => "TEXT DEFAULT ''",
        'allowed_devices'    => "TEXT DEFAULT ''",
        'blocked_devices'    => "TEXT DEFAULT ''",
        'allowed_os'         => "TEXT DEFAULT ''",
        'blocked_os'         => "TEXT DEFAULT ''",
        'os_min_versions'    => "TEXT DEFAULT ''",
        'allowed_languages'  => "TEXT DEFAULT ''",
        'blocked_languages'  => "TEXT DEFAULT ''",
        'allow_empty_referer'=> 'INTEGER DEFAULT 1',
        'required_url_params'=> "TEXT DEFAULT ''",
        'allowed_resolutions'=> "TEXT DEFAULT ''",
        'blocked_resolutions'=> "TEXT DEFAULT ''",
        'require_screen_info'=> 'INTEGER DEFAULT 0',
        'single_visit_only'  => 'INTEGER DEFAULT 0',
        'block_review_infra' => 'INTEGER DEFAULT 1',
        'offer_urls'         => "TEXT DEFAULT ''",
        'rotation_mode'      => "TEXT DEFAULT 'single'",
        'offer_routes'       => "TEXT DEFAULT ''",
        'offer_method'       => "TEXT DEFAULT 'redirect'",
        'forward_utms'       => 'INTEGER DEFAULT 0',
        'no_cache'           => 'INTEGER DEFAULT 0',
        'fast_mode'          => 'INTEGER DEFAULT 0',
        'delay_start'        => 'INTEGER DEFAULT 0',
        'delay_permanent'    => 'INTEGER DEFAULT 0',
        'blocked_url_params' => "TEXT DEFAULT ''",
        'required_url_keywords' => "TEXT DEFAULT ''",
        'allow_geo_override' => 'INTEGER DEFAULT 0',
    ] as $col => $ddl) {
        $addColumn('links', $col, $ddl);
        $addColumn('campaigns', $col, $ddl);
    }

    // hit_log: source column (added after the legacy rebuild below)
    // NOTE: must run AFTER the hit_log create/rebuild block.

    // Campaigns: action columns (for DBs created before actions existed)
    $addColumn('campaigns', 'reject_mode', "TEXT DEFAULT 'white'");
    $addColumn('campaigns', 'reject_code', 'INTEGER DEFAULT 403');
    $addColumn('campaigns', 'redirect_type', "TEXT DEFAULT '302'");
    $addColumn('campaigns', 'redirect_delay', 'INTEGER DEFAULT 0');

    // hit_log: ensure the new schema (link_id nullable, reasons, os, client)
    $hitCols = $columns('hit_log');
    if ($hitCols === []) {
        // Fresh install: create the current schema directly
        $db->exec("
            CREATE TABLE IF NOT EXISTS hit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                link_id INTEGER,
                campaign_id INTEGER,
                host TEXT,
                ip TEXT,
                user_agent TEXT,
                referer TEXT,
                language TEXT,
                country TEXT,
                device_type TEXT,
                os_name TEXT,
                os_version TEXT,
                client_type TEXT,
                source TEXT DEFAULT '',
                is_bot INTEGER DEFAULT 0,
                is_vpn INTEGER DEFAULT 0,
                is_datacenter INTEGER DEFAULT 0,
                shown_page TEXT DEFAULT 'white',
                reject_reason TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } elseif (!isset($hitCols['reject_reason'])) {
        // Legacy schema: rebuild with data preserved
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->exec("DROP TABLE IF EXISTS hit_log_new");
            $db->exec("
                CREATE TABLE hit_log_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    link_id INTEGER,
                    campaign_id INTEGER,
                    host TEXT,
                    ip TEXT,
                    user_agent TEXT,
                    referer TEXT,
                    language TEXT,
                    country TEXT,
                    device_type TEXT,
                    os_name TEXT,
                    os_version TEXT,
                    client_type TEXT,
                    source TEXT DEFAULT '',
                    is_bot INTEGER DEFAULT 0,
                    is_vpn INTEGER DEFAULT 0,
                    is_datacenter INTEGER DEFAULT 0,
                    shown_page TEXT DEFAULT 'white',
                    reject_reason TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $db->exec("
                INSERT INTO hit_log_new (id, link_id, ip, user_agent, referer, language, country,
                                         device_type, is_bot, is_vpn, is_datacenter, shown_page, created_at)
                SELECT id, link_id, ip, user_agent, referer, language, country,
                       device_type, is_bot, is_vpn, is_datacenter, shown_page, created_at
                FROM hit_log
            ");
            $db->exec("DROP TABLE hit_log");
            $db->exec("ALTER TABLE hit_log_new RENAME TO hit_log");
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    // hit_log: source column for databases that predate it
    $addColumn('hit_log', 'source', "TEXT DEFAULT ''");

    $clientCredentialTables = $columns('client_credentials');
    if ($clientCredentialTables === []) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS client_credentials (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                campaign_id INTEGER NOT NULL,
                credential_hash TEXT UNIQUE NOT NULL,
                scope TEXT NOT NULL DEFAULT 'verify',
                status TEXT NOT NULL DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                revoked_at DATETIME,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
            )
        ");
    }
}

/**
 * Probabilistic cleanup: prunes old hit-log rows and stale rate-limit rows.
 * Call from a hot path (e.g. index.php); runs ~1% of the time.
 */
function maintenance_tick(PDO $db): void
{
    if (mt_rand(1, 100) !== 1) {
        return;
    }
    try {
        $days = defined('LOG_RETENTION_DAYS') ? (int)LOG_RETENTION_DAYS : 30;
        $db->prepare("DELETE FROM hit_log WHERE created_at < datetime('now', ?)")
           ->execute(["-{$days} days"]);
        $db->prepare("DELETE FROM rate_limits WHERE reset_at < ?")->execute([time()]);
    } catch (Throwable $e) {
        // Maintenance must never break a request
    }
}

function client_credential_hash(string $token): string
{
    $secret = defined('APP_KEY') ? (string) APP_KEY : 'test-app-key';

    return hash_hmac('sha256', $token, $secret);
}

function issue_client_credential(PDO $db, int $userId, int $campaignId, int $ttlSeconds = 2592000): string
{
    $stmt = $db->prepare('SELECT id FROM campaigns WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$campaignId, $userId]);
    if ($stmt->fetchColumn() === false) {
        throw new RuntimeException('Campaign not found for credential issuance.');
    }

    $token = 'cc_' . bin2hex(random_bytes(24));
    $hash = client_credential_hash($token);

    $expiresModifier = ($ttlSeconds >= 0 ? '+' . max(1, $ttlSeconds) : (string) $ttlSeconds) . ' seconds';

    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare("
            UPDATE client_credentials
               SET status = 'revoked',
                   revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP)
             WHERE user_id = ?
               AND campaign_id = ?
               AND scope = 'verify'
               AND status = 'active'
        ")->execute([$userId, $campaignId]);

        $db->prepare("
            INSERT INTO client_credentials (user_id, campaign_id, credential_hash, scope, status, expires_at)
            VALUES (?, ?, ?, 'verify', 'active', datetime('now', ?))
        ")->execute([$userId, $campaignId, $hash, $expiresModifier]);

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }

    return $token;
}

function authenticate_client_credential(PDO $db, string $token): array|false
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }

    $stmt = $db->prepare("
        SELECT *
          FROM client_credentials
         WHERE credential_hash = ?
           AND scope = 'verify'
         LIMIT 1
    ");
    $stmt->execute([client_credential_hash($token)]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return false;
    }

    if ((string) ($row['status'] ?? '') !== 'active') {
        return false;
    }
    if (!empty($row['revoked_at'])) {
        return false;
    }
    if (strtotime((string) $row['expires_at']) <= time()) {
        return false;
    }

    return $row;
}

function credential_allows_campaign(array|false $credential, int $campaignId): bool
{
    return is_array($credential) && (int) ($credential['campaign_id'] ?? 0) === $campaignId;
}

function visitor_scope_key(string $kind, int $id): string
{
    return $kind . ':' . $id;
}

function sign_visitor_token(string $scope, string $visitorToken): string
{
    $secret = defined('APP_KEY') ? (string) APP_KEY : 'test-app-key';
    $scopeB64 = rtrim(strtr(base64_encode($scope), '+/', '-_'), '=');
    $tokenB64 = rtrim(strtr(base64_encode($visitorToken), '+/', '-_'), '=');
    $payload = $scopeB64 . '.' . $tokenB64;
    $signature = hash_hmac('sha256', $payload, $secret);

    return $payload . '.' . $signature;
}

function verify_visitor_token(string $signedToken, string $scope): string|false
{
    $parts = explode('.', $signedToken, 3);
    if (count($parts) !== 3) {
        return false;
    }

    [$scopeB64, $tokenB64, $signature] = $parts;
    $payload = $scopeB64 . '.' . $tokenB64;
    $secret = defined('APP_KEY') ? (string) APP_KEY : 'test-app-key';
    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $signature)) {
        return false;
    }

    $decodedScope = base64_decode(strtr($scopeB64, '-_', '+/') . str_repeat('=', (4 - strlen($scopeB64) % 4) % 4), true);
    $decodedToken = base64_decode(strtr($tokenB64, '-_', '+/') . str_repeat('=', (4 - strlen($tokenB64) % 4) % 4), true);
    if (!is_string($decodedScope) || !is_string($decodedToken)) {
        return false;
    }
    if (!hash_equals($scope, $decodedScope)) {
        return false;
    }

    return $decodedToken;
}
