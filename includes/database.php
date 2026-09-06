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
    migrate($db, false);
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
        4 => [
            'file' => $dir . DIRECTORY_SEPARATOR . '004_ip_rules.sql',
            'down' => static function (PDO $db): void {
                $db->exec('ALTER TABLE links DROP COLUMN block_ipv6');
                $db->exec('ALTER TABLE links DROP COLUMN ip_allowlist');
                $db->exec('ALTER TABLE campaigns DROP COLUMN block_ipv6');
                $db->exec('ALTER TABLE campaigns DROP COLUMN ip_allowlist');
            },
        ],
        5 => [
            'file' => $dir . DIRECTORY_SEPARATOR . '005_filters_and_rules.sql',
            'down' => static function (PDO $db): void {
                foreach (['allowed_browsers', 'blocked_browsers', 'ip_blocklist',
                          'ip_clicks_per_day', 'clicks_before_filtering', 'filter_id'] as $col) {
                    $db->exec("ALTER TABLE links DROP COLUMN {$col}");
                    $db->exec("ALTER TABLE campaigns DROP COLUMN {$col}");
                }
                $db->exec('ALTER TABLE hit_log DROP COLUMN browser');
                $db->exec('DROP TABLE IF EXISTS ip_daily');
                $db->exec('DROP TABLE IF EXISTS filter_lists');
            },
        ],
        6 => [
            'file' => $dir . DIRECTORY_SEPARATOR . '006_soft_delete_and_domain_status.sql',
            'down' => static function (PDO $db): void {
                foreach (['links', 'campaigns'] as $table) {
                    $db->exec("ALTER TABLE {$table} DROP COLUMN is_deleted");
                }
                foreach (['is_deleted', 'dns_status', 'dns_records', 'status_checked_at'] as $col) {
                    $db->exec("ALTER TABLE domains DROP COLUMN {$col}");
                }
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

    database_verify_client_credentials_schema($db);
}

/**
 * Schema migrations for databases created by earlier versions.
 */
function migrate(PDO $db, bool $destructiveBackupVerified = false): void
{
    $migrations = get_database_migrations();

    if (database_destructive_migration_pending($db) && !$destructiveBackupVerified) {
        throw new RuntimeException(
            'Migration 002 is destructive and requires a verified backup manifest bound to this database and app key.'
        );
    }

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

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

function database_destructive_migration_pending(PDO $db): bool
{
    $presentTables = database_list_tables($db);
    if (array_values(array_intersect(database_required_tables(), $presentTables)) === []) {
        return false;
    }

    return !in_array(2, database_get_applied_migration_versions($db), true);
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

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
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

    if ($version === 3 && database_prepare_client_credentials_migration($db)) {
        return;
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

function database_prepare_client_credentials_migration(PDO $db): bool
{
    if (!database_table_exists($db, 'client_credentials')) {
        return false;
    }

    if (database_client_credentials_schema_is_valid($db)) {
        database_create_client_credentials_indexes($db);
        return true;
    }

    $mismatch = (int) $db->query("
        SELECT COUNT(*)
        FROM client_credentials cc
        LEFT JOIN campaigns c
          ON c.id = cc.campaign_id
         AND c.user_id = cc.user_id
        WHERE c.id IS NULL
    ")->fetchColumn();
    if ($mismatch > 0) {
        throw new RuntimeException('Migration 003 cannot enforce client credential campaign ownership until orphaned or cross-tenant credentials are repaired.');
    }

    $db->exec('DROP TABLE IF EXISTS client_credentials_new');
    $db->exec("
        CREATE TABLE client_credentials_new (
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
            FOREIGN KEY (campaign_id, user_id) REFERENCES campaigns(id, user_id) ON DELETE CASCADE
        )
    ");
    $db->exec("
        INSERT INTO client_credentials_new (
            id, user_id, campaign_id, credential_hash, scope, status, created_at, expires_at, revoked_at
        )
        SELECT
            id, user_id, campaign_id, credential_hash, scope, status, created_at, expires_at, revoked_at
        FROM client_credentials
    ");
    $db->exec('DROP TABLE client_credentials');
    $db->exec('ALTER TABLE client_credentials_new RENAME TO client_credentials');
    database_create_client_credentials_indexes($db);

    return true;
}

function database_verify_client_credentials_schema(PDO $db): void
{
    if (!database_client_credentials_schema_is_valid($db)) {
        throw new RuntimeException('Database schema version 3 has an invalid client_credentials constraint shape. Run bin/migrate.php after repairing the legacy table.');
    }
}

function database_client_credentials_schema_is_valid(PDO $db): bool
{
    $columns = database_columns($db, 'client_credentials');
    foreach (['id', 'user_id', 'campaign_id', 'credential_hash', 'scope', 'status', 'created_at', 'expires_at', 'revoked_at'] as $column) {
        if (!isset($columns[$column])) {
            return false;
        }
    }

    $foreignKeys = database_foreign_keys($db, 'client_credentials');
    $campaignOwnershipFk = null;
    $userFk = null;

    foreach ($foreignKeys as $foreignKey) {
        if (($foreignKey['table'] ?? '') === 'campaigns' && ($foreignKey['from'] ?? []) === ['campaign_id', 'user_id']) {
            $campaignOwnershipFk = $foreignKey;
        }
        if (($foreignKey['table'] ?? '') === 'users' && ($foreignKey['from'] ?? []) === ['user_id']) {
            $userFk = $foreignKey;
        }
    }

    if (!is_array($campaignOwnershipFk) || ($campaignOwnershipFk['to'] ?? []) !== ['id', 'user_id'] || strtoupper((string) ($campaignOwnershipFk['on_delete'] ?? '')) !== 'CASCADE') {
        return false;
    }

    if (!is_array($userFk) || ($userFk['to'] ?? []) !== ['id'] || strtoupper((string) ($userFk['on_delete'] ?? '')) !== 'CASCADE') {
        return false;
    }

    return true;
}

function database_create_client_credentials_indexes(PDO $db): void
{
    $db->exec('CREATE INDEX IF NOT EXISTS idx_client_credentials_campaign ON client_credentials(campaign_id, status, expires_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_client_credentials_hash ON client_credentials(credential_hash)');
}

/**
 * @return list<array{table:string,from:list<string>,to:list<string>,on_delete:string,on_update:string}>
 */
function database_foreign_keys(PDO $db, string $table): array
{
    $rows = $db->query("PRAGMA foreign_key_list({$table})")->fetchAll();
    $grouped = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (!isset($grouped[$id])) {
            $grouped[$id] = [
                'table' => (string) $row['table'],
                'from' => [],
                'to' => [],
                'on_delete' => (string) $row['on_delete'],
                'on_update' => (string) $row['on_update'],
            ];
        }

        $grouped[$id]['from'][] = (string) $row['from'];
        $grouped[$id]['to'][] = (string) $row['to'];
    }

    return array_values($grouped);
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

/**
 * Persist one decision and its aggregate counters as a single SQLite unit.
 * A missing aggregate row or any write failure rolls the complete hit back.
 *
 * @param array<string, mixed> $hit
 */
function record_hit(PDO $db, array $hit, bool $showOffer): void
{
    $linkId = isset($hit['link_id']) ? (int) $hit['link_id'] : null;
    $campaignId = isset($hit['campaign_id']) ? (int) $hit['campaign_id'] : null;
    if ($linkId === null && $campaignId === null) {
        throw new InvalidArgumentException('A hit must be bound to a link or campaign.');
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare("
            INSERT INTO hit_log (link_id, campaign_id, host, ip, user_agent, referer, language, country,
                                 device_type, os_name, os_version, client_type, source, browser,
                                 is_bot, is_vpn, is_datacenter, shown_page, reject_reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $linkId,
            $campaignId,
            (string) ($hit['host'] ?? ''),
            (string) ($hit['ip'] ?? ''),
            (string) ($hit['user_agent'] ?? ''),
            (string) ($hit['referer'] ?? ''),
            (string) ($hit['language'] ?? ''),
            (string) ($hit['country'] ?? ''),
            (string) ($hit['device_type'] ?? ''),
            (string) ($hit['os_name'] ?? ''),
            (string) ($hit['os_version'] ?? ''),
            (string) ($hit['client_type'] ?? ''),
            (string) ($hit['source'] ?? ''),
            (string) ($hit['browser'] ?? ''),
            !empty($hit['is_bot']) ? 1 : 0,
            !empty($hit['is_vpn']) ? 1 : 0,
            !empty($hit['is_datacenter']) ? 1 : 0,
            $showOffer ? 'offer' : 'white',
            $showOffer ? null : ($hit['reject_reason'] ?? null),
        ]);

        $counter = $showOffer ? 'offer_shows' : 'white_shows';
        if ($linkId !== null) {
            $update = $db->prepare("UPDATE links SET total_hits = total_hits + 1, {$counter} = {$counter} + 1 WHERE id = ?");
            $update->execute([$linkId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Unable to update link hit counters.');
            }
        }
        if ($campaignId !== null) {
            $update = $db->prepare("UPDATE campaigns SET total_hits = total_hits + 1, {$counter} = {$counter} + 1 WHERE id = ?");
            $update->execute([$campaignId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Unable to update campaign hit counters.');
            }
        }

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
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

function visitor_cookie_name(string $scope): string
{
    return 'cvk_' . substr(hash('sha256', $scope), 0, 16);
}

function visitor_storage_key(string $scope): string
{
    return 'cloak_vtoken_' . substr(hash('sha256', $scope), 0, 16);
}

/**
 * @return array{name:string,value:string,options:array<string,mixed>}|false
 */
function visitor_cookie_issue(string $scope, string $visitorToken, bool $isHttps, bool $allowed = true): array|false
{
    if (!$allowed || !$isHttps || $visitorToken === '') {
        return false;
    }

    return [
        'name' => visitor_cookie_name($scope),
        'value' => sign_visitor_token($scope, $visitorToken),
        'options' => [
            'expires' => time() + 86400 * 365,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ],
    ];
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
