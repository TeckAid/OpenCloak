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
        database_configure_connection($db);
    }

    return $db;
}

function database_configure_connection(PDO $db): void
{
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA foreign_keys=ON');
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
    migrate($db);
    verifyDatabaseSchema($db);

    return $db;
}

function get_expected_schema_version(): int
{
    return max(array_keys(get_database_migrations()));
}

/**
 * @return array<int, array{file:string,down:(callable(PDO):void)|null}>
 */
function get_database_migrations(): array
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'migrations';

    return [
        1 => [
            'file' => $dir . DIRECTORY_SEPARATOR . '001_initial_schema.sql',
            'down' => null,
        ],
        2 => [
            'file' => $dir . DIRECTORY_SEPARATOR . '002_current_columns.sql',
            'down' => null,
        ],
        3 => [
            'file' => $dir . DIRECTORY_SEPARATOR . '003_client_credentials.sql',
            'down' => static function (PDO $db): void {
                $db->exec('DROP INDEX IF EXISTS idx_client_credentials_hash');
                $db->exec('DROP INDEX IF EXISTS idx_client_credentials_campaign');
                $db->exec('DROP TABLE IF EXISTS client_credentials');
            },
        ],
    ];
}

function verifyDatabaseSchema(PDO $db): void
{
    $expectedVersions = array_keys(get_database_migrations());
    if (!database_table_exists($db, 'schema_migrations')) {
        throw new RuntimeException(sprintf(
            'Database schema version is missing. Expected %d. Run bin/migrate.php.',
            get_expected_schema_version()
        ));
    }

    $appliedVersions = database_get_applied_migration_versions($db);
    if ($appliedVersions !== $expectedVersions) {
        throw new RuntimeException(sprintf(
            'Database schema version %s does not match expected %d. Run bin/migrate.php.',
            $appliedVersions === [] ? 'none' : implode(',', $appliedVersions),
            get_expected_schema_version()
        ));
    }

    foreach (database_required_tables() as $table) {
        if (!database_table_exists($db, $table)) {
            throw new RuntimeException(sprintf(
                'Database schema version %d is missing required table %s. Run bin/migrate.php.',
                get_expected_schema_version(),
                $table
            ));
        }
    }
}

/**
 * Schema migrations for databases created by earlier versions.
 */
function migrate(PDO $db): void
{
    $migrations = get_database_migrations();

    $db->exec('BEGIN IMMEDIATE');

    try {
        database_adopt_legacy_schema($db);
        database_create_schema_migrations_table($db);
        $appliedVersions = database_get_applied_migration_versions($db);

        foreach ($migrations as $version => $migration) {
            if (in_array($version, $appliedVersions, true)) {
                continue;
            }

            database_apply_migration($db, $version, $migration['file']);
            $db->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, CURRENT_TIMESTAMP)')
               ->execute([$version]);
            $appliedVersions[] = $version;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function rollbackDatabaseToVersion(PDO $db, int $toVersion): void
{
    $migrations = get_database_migrations();
    $expected = get_expected_schema_version();

    if ($toVersion < 0 || $toVersion > $expected) {
        throw new InvalidArgumentException(sprintf(
            'Rollback target %d is outside the supported range 0-%d.',
            $toVersion,
            $expected
        ));
    }
    if (!database_table_exists($db, 'schema_migrations')) {
        throw new RuntimeException('restore-required: schema_migrations ledger is missing. Restore from backup before rollback.');
    }

    $db->exec('BEGIN IMMEDIATE');

    try {
        $appliedVersions = database_get_applied_migration_versions($db);
        rsort($appliedVersions, SORT_NUMERIC);

        foreach ($appliedVersions as $version) {
            if ($version <= $toVersion) {
                continue;
            }

            $down = $migrations[$version]['down'] ?? null;
            if (!is_callable($down)) {
                throw new RuntimeException(sprintf(
                    'restore-required: migration %03d does not implement a down operation. Restore from backup to reach version %d.',
                    $version,
                    $toVersion
                ));
            }

            $down($db);
            $db->prepare('DELETE FROM schema_migrations WHERE version = ?')->execute([$version]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * @return list<string>
 */
function database_required_tables(): array
{
    return ['users', 'campaigns', 'domains', 'links', 'settings', 'rate_limits', 'delay_ips', 'hit_log', 'client_credentials'];
}

function database_create_schema_migrations_table(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            version INTEGER PRIMARY KEY,
            applied_at TEXT NOT NULL
        )
    ");
}

/**
 * @return list<int>
 */
function database_get_applied_migration_versions(PDO $db): array
{
    if (!database_table_exists($db, 'schema_migrations')) {
        return [];
    }

    return array_map(
        'intval',
        $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)
    );
}

function database_apply_migration(PDO $db, int $version, string $file): void
{
    if ($version === 2) {
        database_prepare_current_columns_migration($db);
        database_validate_current_columns_preconditions($db);
    }

    database_exec_sql_file($db, $file);
}

function database_exec_sql_file(PDO $db, string $file): void
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Unable to read migration file: ' . $file);
    }

    if (trim($sql) !== '') {
        $db->exec($sql);
    }
}

function database_adopt_legacy_schema(PDO $db): void
{
    if (database_table_exists($db, 'schema_migrations')) {
        return;
    }

    $presentTables = database_list_tables($db);
    $legacyTables = array_values(array_intersect(database_required_tables(), $presentTables));
    if ($legacyTables === []) {
        return;
    }

    foreach (['users', 'campaigns', 'domains', 'links', 'settings', 'rate_limits', 'delay_ips', 'hit_log'] as $table) {
        if (!in_array($table, $presentTables, true)) {
            throw new RuntimeException(sprintf(
                'Legacy database is missing required table %s. Restore from backup or repair it before running migrations.',
                $table
            ));
        }
    }

    database_create_schema_migrations_table($db);
    $db->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (1, CURRENT_TIMESTAMP)')->execute();
}

/**
 * @return list<string>
 */
function database_list_tables(PDO $db): array
{
    return $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
}

function database_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);

    return $stmt->fetchColumn() !== false;
}

/**
 * @return array<string, array<string, mixed>>
 */
function database_columns(PDO $db, string $table): array
{
    $columns = [];
    foreach ($db->query("PRAGMA table_info({$table})")->fetchAll() as $column) {
        $columns[$column['name']] = $column;
    }

    return $columns;
}

function database_prepare_current_columns_migration(PDO $db): void
{
    $addColumn = static function (string $table, string $column, string $ddl) use ($db): void {
        if (!isset(database_columns($db, $table)[$column])) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$ddl}");
        }
    };

    $addColumn('links', 'campaign_id', 'INTEGER');
    $addColumn('links', 'domain_id', 'INTEGER');

    foreach ([
        'reject_mode' => "TEXT DEFAULT 'white'",
        'reject_code' => 'INTEGER DEFAULT 403',
        'redirect_type' => "TEXT DEFAULT '302'",
        'redirect_delay' => 'INTEGER DEFAULT 0',
        'block_bots' => 'INTEGER DEFAULT 1',
        'block_datacenters' => 'INTEGER DEFAULT 1',
        'block_review_infra' => 'INTEGER DEFAULT 1',
        'block_vpn' => 'INTEGER DEFAULT 0',
        'block_tor' => 'INTEGER DEFAULT 1',
        'block_headless' => 'INTEGER DEFAULT 1',
        'block_curl' => 'INTEGER DEFAULT 1',
        'allowed_countries' => "TEXT DEFAULT ''",
        'blocked_countries' => "TEXT DEFAULT ''",
        'allowed_clients' => "TEXT DEFAULT ''",
        'blocked_clients' => "TEXT DEFAULT ''",
        'allowed_devices' => "TEXT DEFAULT ''",
        'blocked_devices' => "TEXT DEFAULT ''",
        'allowed_os' => "TEXT DEFAULT ''",
        'blocked_os' => "TEXT DEFAULT ''",
        'os_min_versions' => "TEXT DEFAULT ''",
        'allowed_languages' => "TEXT DEFAULT ''",
        'blocked_languages' => "TEXT DEFAULT ''",
        'allowed_referrers' => "TEXT DEFAULT ''",
        'blocked_referrers' => "TEXT DEFAULT ''",
        'allow_empty_referer' => 'INTEGER DEFAULT 1',
        'required_url_params' => "TEXT DEFAULT ''",
        'blocked_url_params' => "TEXT DEFAULT ''",
        'required_url_keywords' => "TEXT DEFAULT ''",
        'allowed_resolutions' => "TEXT DEFAULT ''",
        'blocked_resolutions' => "TEXT DEFAULT ''",
        'require_screen_info' => 'INTEGER DEFAULT 0',
        'single_visit_only' => 'INTEGER DEFAULT 0',
        'offer_urls' => "TEXT DEFAULT ''",
        'rotation_mode' => "TEXT DEFAULT 'single'",
        'offer_routes' => "TEXT DEFAULT ''",
        'offer_method' => "TEXT DEFAULT 'redirect'",
        'forward_utms' => 'INTEGER DEFAULT 0',
        'no_cache' => 'INTEGER DEFAULT 0',
        'fast_mode' => 'INTEGER DEFAULT 0',
        'delay_start' => 'INTEGER DEFAULT 0',
        'delay_permanent' => 'INTEGER DEFAULT 0',
        'allow_geo_override' => 'INTEGER DEFAULT 0',
        'total_hits' => 'INTEGER DEFAULT 0',
        'offer_shows' => 'INTEGER DEFAULT 0',
        'white_shows' => 'INTEGER DEFAULT 0',
    ] as $column => $ddl) {
        $addColumn('campaigns', $column, $ddl);
        $addColumn('links', $column, $ddl);
    }

    $hitColumns = database_columns($db, 'hit_log');
    if ($hitColumns === []) {
        throw new RuntimeException('Migration 002 requires hit_log to exist before applying current columns.');
    }

    if (!isset($hitColumns['reject_reason'])) {
        $db->exec("
            DROP TABLE IF EXISTS hit_log_new;
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
            );
            INSERT INTO hit_log_new (
                id, link_id, campaign_id, host, ip, user_agent, referer, language, country,
                device_type, os_name, os_version, client_type, source,
                is_bot, is_vpn, is_datacenter, shown_page, reject_reason, created_at
            )
            SELECT
                id,
                link_id,
                NULL,
                '',
                ip,
                user_agent,
                referer,
                language,
                country,
                device_type,
                '',
                '',
                '',
                '',
                is_bot,
                is_vpn,
                is_datacenter,
                shown_page,
                NULL,
                created_at
            FROM hit_log;
            DROP TABLE hit_log;
            ALTER TABLE hit_log_new RENAME TO hit_log;
        ");
    } else {
        $addColumn('hit_log', 'campaign_id', 'INTEGER');
        $addColumn('hit_log', 'host', "TEXT DEFAULT ''");
        $addColumn('hit_log', 'os_name', "TEXT DEFAULT ''");
        $addColumn('hit_log', 'os_version', "TEXT DEFAULT ''");
        $addColumn('hit_log', 'client_type', "TEXT DEFAULT ''");
        $addColumn('hit_log', 'source', "TEXT DEFAULT ''");
    }
}

function database_validate_current_columns_preconditions(PDO $db): void
{
    $danglingCampaign = (int) $db->query("
        SELECT COUNT(*)
        FROM links l
        LEFT JOIN campaigns c
          ON c.id = l.campaign_id
         AND c.user_id = l.user_id
        WHERE l.campaign_id IS NOT NULL
          AND c.id IS NULL
    ")->fetchColumn();
    if ($danglingCampaign > 0) {
        throw new RuntimeException('Migration 002 cannot add campaign ownership foreign keys while links contain orphaned or cross-tenant campaign references.');
    }

    $danglingDomain = (int) $db->query("
        SELECT COUNT(*)
        FROM links l
        LEFT JOIN domains d
          ON d.id = l.domain_id
         AND d.user_id = l.user_id
        WHERE l.domain_id IS NOT NULL
          AND d.id IS NULL
    ")->fetchColumn();
    if ($danglingDomain > 0) {
        throw new RuntimeException('Migration 002 cannot add domain ownership foreign keys while links contain orphaned or cross-tenant domain references.');
    }

    $invalidDelayScope = (int) $db->query("
        SELECT COUNT(*)
        FROM delay_ips
        WHERE ip_hash IS NULL
           OR ip_hash = ''
           OR ((link_id IS NULL) = (campaign_id IS NULL))
    ")->fetchColumn();
    if ($invalidDelayScope > 0) {
        throw new RuntimeException('Migration 002 cannot enforce delay-start scope uniqueness while delay_ips rows have invalid scope values.');
    }

    $duplicateCampaignScope = $db->query("
        SELECT campaign_id, ip_hash
        FROM delay_ips
        WHERE campaign_id IS NOT NULL
        GROUP BY campaign_id, ip_hash
        HAVING COUNT(*) > 1
        LIMIT 1
    ")->fetch();
    if (is_array($duplicateCampaignScope)) {
        throw new RuntimeException('Migration 002 cannot enforce campaign delay-start uniqueness until duplicate campaign/ip_hash rows are repaired.');
    }

    $duplicateLinkScope = $db->query("
        SELECT link_id, ip_hash
        FROM delay_ips
        WHERE link_id IS NOT NULL
        GROUP BY link_id, ip_hash
        HAVING COUNT(*) > 1
        LIMIT 1
    ")->fetch();
    if (is_array($duplicateLinkScope)) {
        throw new RuntimeException('Migration 002 cannot enforce link delay-start uniqueness until duplicate link/ip_hash rows are repaired.');
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
