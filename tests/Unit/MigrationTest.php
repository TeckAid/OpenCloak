<?php

require_once __DIR__ . '/../bootstrap.php';

final class MigrationTest extends TestCase
{
    public function test_fresh_migrate_cli_applies_all_versions_and_is_idempotent(): void
    {
        $runtime = $this->createTempDir('cloaking-migrate-');
        $dbPath = $runtime . DIRECTORY_SEPARATOR . 'cloaking.sqlite';

        try {
            $first = $this->runMigrationCommand($dbPath);
            $this->assertSame(0, $first['exit']);

            $db = $this->openDatabase($dbPath);
            $versions = $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);

            $this->assertSame([1, 2, 3], array_map('intval', $versions));
            $this->assertSame(3, (int) get_expected_schema_version());
            $this->assertSame(1, (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'client_credentials'")->fetchColumn());

            $second = $this->runMigrationCommand($dbPath);
            $this->assertSame(0, $second['exit']);
            $this->assertSame(3, (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        } finally {
            $this->deleteTree($runtime);
        }
    }

    public function test_init_database_requires_expected_schema_version_without_running_ddl(): void
    {
        $runtime = $this->createTempDir('cloaking-init-');
        $dbPath = $runtime . DIRECTORY_SEPARATOR . 'cloaking.sqlite';
        $scriptPath = $runtime . DIRECTORY_SEPARATOR . 'init-check.php';

        try {
            file_put_contents($scriptPath, sprintf(
                <<<'PHP'
<?php
define('DB_PATH', %s);
require %s;
try {
    initDatabase();
    fwrite(STDOUT, "unexpected success\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
PHP,
                var_export($dbPath, true),
                var_export(APP_ROOT . '/includes/database.php', true)
            ));

            $result = $this->runPhpCommand($scriptPath, []);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(str_contains($result['stderr'], 'schema version'));
            $db = $this->openDatabase($dbPath);
            $this->assertSame(
                false,
                $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn()
            );
        } finally {
            $this->deleteTree($runtime);
        }
    }

    public function test_concurrent_migrate_cli_serializes_without_double_applying_versions(): void
    {
        $runtime = $this->createTempDir('cloaking-concurrent-migrate-');
        $dbPath = $runtime . DIRECTORY_SEPARATOR . 'cloaking.sqlite';
        $lockScript = $runtime . DIRECTORY_SEPARATOR . 'hold-lock.php';

        try {
            file_put_contents($lockScript, sprintf(
                <<<'PHP'
<?php
define('DB_PATH', %s);
require %s;
$db = getDB();
$db->exec('BEGIN IMMEDIATE');
fwrite(STDOUT, "locked\n");
usleep(750000);
$db->exec('COMMIT');
PHP,
                var_export($dbPath, true),
                var_export(APP_ROOT . '/includes/database.php', true)
            ));

            $holder = proc_open(
                [PHP_BINARY, $lockScript],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                APP_ROOT
            );
            if (!is_resource($holder)) {
                $this->fail('Unable to start lock holder process.');
            }

            fclose($pipes[0]);
            $ready = fgets($pipes[1]);
            $this->assertSame("locked\n", $ready);

            $result = $this->runMigrationCommand($dbPath);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            proc_close($holder);

            $this->assertSame(0, $result['exit'], $result['stderr']);
            $this->assertSame('', $stderr);

            $db = $this->openDatabase($dbPath);
            $this->assertSame(3, (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        } finally {
            $this->deleteTree($runtime);
        }
    }

    public function test_rollback_cli_reverts_client_credentials_migration_only(): void
    {
        $runtime = $this->createTempDir('cloaking-rollback-');
        $dbPath = $runtime . DIRECTORY_SEPARATOR . 'cloaking.sqlite';

        try {
            $this->assertSame(0, $this->runMigrationCommand($dbPath)['exit']);

            $db = $this->openDatabase($dbPath);
            $db->prepare("INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (1, 'owner', 'hash', 'key', 0)")
                ->execute();
            $db->prepare("
                INSERT INTO campaigns (id, user_id, name, is_active, offer_url, white_page, reject_mode, reject_code, redirect_type, redirect_delay)
                VALUES (7, 1, 'Campaign', 1, 'https://offers.example/a', '', 'white', 403, '302', 0)
            ")->execute();
            $db->prepare("
                INSERT INTO client_credentials (user_id, campaign_id, credential_hash, scope, status, expires_at)
                VALUES (1, 7, 'hash', 'verify', 'active', datetime('now', '+1 day'))
            ")->execute();

            $rollback = $this->runRollbackCommand($dbPath, 2);

            $this->assertSame(0, $rollback['exit'], $rollback['stderr']);
            $this->assertSame([1, 2], array_map('intval', $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)));
            $this->assertSame(
                false,
                $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'client_credentials'")->fetchColumn()
            );
        } finally {
            $this->deleteTree($runtime);
        }
    }

    public function test_rollback_cli_refuses_unimplemented_destructive_restore_paths(): void
    {
        $runtime = $this->createTempDir('cloaking-rollback-refusal-');
        $dbPath = $runtime . DIRECTORY_SEPARATOR . 'cloaking.sqlite';

        try {
            $this->assertSame(0, $this->runMigrationCommand($dbPath)['exit']);

            $rollback = $this->runRollbackCommand($dbPath, 1);

            $this->assertSame(1, $rollback['exit']);
            $this->assertTrue(str_contains($rollback['stderr'] . $rollback['stdout'], 'restore-required'));
        } finally {
            $this->deleteTree($runtime);
        }
    }

    public function test_legacy_schema_without_ledger_is_upgraded_with_data_preserved(): void
    {
        $runtime = $this->createTempDir('cloaking-legacy-upgrade-');
        $dbPath = $runtime . DIRECTORY_SEPARATOR . 'cloaking.sqlite';

        try {
            $this->createLegacyVersionOneDatabase($dbPath);

            $result = $this->runMigrationCommand($dbPath);
            $this->assertSame(0, $result['exit'], $result['stderr']);

            $db = $this->openDatabase($dbPath);
            $versions = array_map('intval', $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
            $link = $db->query('SELECT * FROM links WHERE id = 11')->fetch();
            $hit = $db->query('SELECT * FROM hit_log WHERE id = 12')->fetch();

            $this->assertSame([1, 2, 3], $versions);
            $this->assertSame('promo', (string) $link['slug']);
            $this->assertSame(7, (int) $link['campaign_id']);
            $this->assertSame(8, (int) $link['domain_id']);
            $this->assertSame('', (string) $hit['source']);
            $this->assertSame('', (string) $hit['host']);
            $this->assertSame(1, (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'client_credentials'")->fetchColumn());
        } finally {
            $this->deleteTree($runtime);
        }
    }

    public function test_migrated_schema_enforces_delay_scope_uniqueness_and_link_ownership(): void
    {
        $runtime = $this->createTempDir('cloaking-schema-constraints-');
        $dbPath = $runtime . DIRECTORY_SEPARATOR . 'cloaking.sqlite';

        try {
            $this->assertSame(0, $this->runMigrationCommand($dbPath)['exit']);

            $db = $this->openDatabase($dbPath);
            $this->seedOwnershipFixture($db);

            $db->prepare("
                INSERT INTO delay_ips (campaign_id, link_id, ip_hash)
                VALUES (7, NULL, 'hash-a')
            ")->execute();
            $this->assertThrows(
                static fn () => $db->prepare("
                    INSERT INTO delay_ips (campaign_id, link_id, ip_hash)
                    VALUES (7, NULL, 'hash-a')
                ")->execute(),
                PDOException::class,
                'UNIQUE'
            );

            $this->assertThrows(
                static fn () => $db->prepare("
                    INSERT INTO links (user_id, slug, name, campaign_id, domain_id, offer_url, white_page, is_active)
                    VALUES (1, 'cross-tenant', 'Cross Tenant', 9, 9, 'https://offers.example/cross', '', 1)
                ")->execute(),
                PDOException::class,
                'FOREIGN KEY'
            );

            $db->prepare("
                INSERT INTO links (id, user_id, slug, name, campaign_id, domain_id, offer_url, white_page, is_active)
                VALUES (21, 1, 'owned', 'Owned', 7, 8, 'https://offers.example/owned', '', 1)
            ")->execute();
            $this->assertThrows(
                static fn () => $db->exec('DELETE FROM campaigns WHERE id = 7'),
                PDOException::class,
                'FOREIGN KEY'
            );
            $this->assertThrows(
                static fn () => $db->exec('DELETE FROM domains WHERE id = 8'),
                PDOException::class,
                'FOREIGN KEY'
            );

            $db->prepare('UPDATE links SET campaign_id = NULL, domain_id = NULL WHERE id = 21')->execute();
            $db->exec('DELETE FROM campaigns WHERE id = 7');
            $db->exec('DELETE FROM domains WHERE id = 8');

            $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM campaigns WHERE id = 7')->fetchColumn());
            $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM domains WHERE id = 8')->fetchColumn());
        } finally {
            $this->deleteTree($runtime);
        }
    }

    public function test_hit_logging_and_counter_updates_can_rollback_as_one_transaction(): void
    {
        $runtime = $this->createTempDir('cloaking-hit-rollback-');
        $dbPath = $runtime . DIRECTORY_SEPARATOR . 'cloaking.sqlite';

        try {
            $this->assertSame(0, $this->runMigrationCommand($dbPath)['exit']);
            $db = $this->openDatabase($dbPath);
            $this->seedOwnershipFixture($db);
            $db->prepare("
                INSERT INTO links (id, user_id, slug, name, campaign_id, domain_id, offer_url, white_page, is_active)
                VALUES (31, 1, 'stats', 'Stats', 7, 8, 'https://offers.example/stats', '', 1)
            ")->execute();

            $db->exec('BEGIN IMMEDIATE');
            $db->prepare("
                INSERT INTO hit_log (link_id, campaign_id, host, ip, user_agent, referer, language, country,
                                     device_type, os_name, os_version, client_type, source,
                                     is_bot, is_vpn, is_datacenter, shown_page, reject_reason)
                VALUES (31, 7, 'go.example.com', '198.51.100.9', 'Mozilla/5.0', '', 'en-US', 'US',
                        'mobile', 'iOS', '17.0', 'facebook', 'ads',
                        0, 0, 0, 'offer', NULL)
            ")->execute();
            $db->prepare("UPDATE links SET total_hits = total_hits + 1, offer_shows = offer_shows + 1 WHERE id = 31")
                ->execute();
            $db->prepare("UPDATE campaigns SET total_hits = total_hits + 1, offer_shows = offer_shows + 1 WHERE id = 7")
                ->execute();
            $db->rollBack();

            $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM hit_log WHERE link_id = 31')->fetchColumn());
            $this->assertSame(0, (int) $db->query('SELECT total_hits FROM links WHERE id = 31')->fetchColumn());
            $this->assertSame(0, (int) $db->query('SELECT offer_shows FROM campaigns WHERE id = 7')->fetchColumn());
        } finally {
            $this->deleteTree($runtime);
        }
    }

    /**
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runMigrationCommand(string $dbPath): array
    {
        return $this->runPhpCommand(APP_ROOT . '/bin/migrate.php', ['--db=' . $dbPath]);
    }

    /**
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runRollbackCommand(string $dbPath, int $toVersion): array
    {
        return $this->runPhpCommand(APP_ROOT . '/bin/rollback.php', ['--db=' . $dbPath, '--to=' . $toVersion]);
    }

    /**
     * @param list<string> $arguments
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runPhpCommand(string $scriptPath, array $arguments): array
    {
        $command = array_merge([PHP_BINARY, $scriptPath], $arguments);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, APP_ROOT);
        if (!is_resource($process)) {
            $this->fail('Unable to start PHP command: ' . $scriptPath);
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

    private function openDatabase(string $dbPath): PDO
    {
        $db = new PDO('sqlite:' . $dbPath);
        database_configure_connection($db);

        return $db;
    }

    private function createTempDir(string $prefix): string
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

    private function deleteTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    private function createLegacyVersionOneDatabase(string $dbPath): void
    {
        $db = $this->openDatabase($dbPath);
        $db->exec(file_get_contents(APP_ROOT . '/migrations/001_initial_schema.sql') ?: '');
        $db->prepare("INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (1, 'owner', 'hash', 'key', 0)")
            ->execute();
        $db->prepare("
            INSERT INTO campaigns (id, user_id, name, is_active, offer_url, white_page)
            VALUES (7, 1, 'Legacy Campaign', 1, 'https://offers.example/legacy', '')
        ")->execute();
        $db->prepare("
            INSERT INTO domains (id, user_id, domain, is_system, is_active)
            VALUES (8, 1, 'go.example.com', 0, 1)
        ")->execute();
        $db->prepare("
            INSERT INTO links (id, user_id, slug, name, offer_url, white_page, is_active)
            VALUES (11, 1, 'promo', 'Legacy Link', 'https://offers.example/legacy-link', '', 1)
        ")->execute();
        $db->prepare("
            INSERT INTO hit_log (id, link_id, ip, user_agent, referer, language, country, device_type, is_bot, is_vpn, is_datacenter, shown_page)
            VALUES (12, 11, '198.51.100.7', 'Mozilla/5.0', '', 'en-US', 'US', 'mobile', 0, 0, 0, 'offer')
        ")->execute();
        $db->prepare("INSERT INTO delay_ips (campaign_id, ip_hash) VALUES (7, 'legacy-hash')")->execute();

        $db->exec("
            ALTER TABLE links ADD COLUMN campaign_id INTEGER;
            ALTER TABLE links ADD COLUMN domain_id INTEGER;
            UPDATE links SET campaign_id = 7, domain_id = 8 WHERE id = 11;
        ");
    }

    private function seedOwnershipFixture(PDO $db): void
    {
        $db->prepare("INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (1, 'owner', 'hash', 'key-1', 0)")
            ->execute();
        $db->prepare("INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (2, 'other', 'hash', 'key-2', 0)")
            ->execute();
        $db->prepare("
            INSERT INTO campaigns (id, user_id, name, is_active, offer_url, white_page, reject_mode, reject_code, redirect_type, redirect_delay)
            VALUES (7, 1, 'Owner Campaign', 1, 'https://offers.example/a', '', 'white', 403, '302', 0)
        ")->execute();
        $db->prepare("
            INSERT INTO campaigns (id, user_id, name, is_active, offer_url, white_page, reject_mode, reject_code, redirect_type, redirect_delay)
            VALUES (9, 2, 'Other Campaign', 1, 'https://offers.example/b', '', 'white', 403, '302', 0)
        ")->execute();
        $db->prepare("INSERT INTO domains (id, user_id, domain, is_system, is_active) VALUES (8, 1, 'go.example.com', 0, 1)")
            ->execute();
        $db->prepare("INSERT INTO domains (id, user_id, domain, is_system, is_active) VALUES (9, 2, 'other.example.com', 0, 1)")
            ->execute();
    }
}
