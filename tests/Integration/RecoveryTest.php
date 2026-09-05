<?php

require_once __DIR__ . '/../bootstrap.php';

final class RecoveryTest extends TestCase
{
    public function test_backup_script_creates_database_key_metadata_and_checksums(): void
    {
        $fixture = $this->createRecoveryFixture();
        $backupDir = $this->tempDir('cloaking-backup-');

        try {
            $result = $this->runBackupCommand($fixture, $backupDir);

            $this->assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
            $this->assertTrue(is_file($backupDir . '/cloaking.sqlite'), 'Backup database was not created.');
            $this->assertTrue(is_file($backupDir . '/app.key'), 'Backup app key was not copied.');
            $this->assertTrue(is_file($backupDir . '/config.local.php'), 'Backup config metadata file was not copied.');
            $this->assertTrue(is_file($backupDir . '/backup-metadata.json'), 'Backup metadata was not written.');
            $this->assertTrue(is_file($backupDir . '/SHA256SUMS'), 'Backup checksums were not written.');

            $metadata = $this->readJsonFile($backupDir . '/backup-metadata.json');
            $this->assertSame($fixture['dbPath'], $metadata['source']['db_path'] ?? null);
            $this->assertSame('https://app.example.test', $metadata['config']['app_base_url'] ?? null);
            $this->assertSame(['127.0.0.1', 'app.example.test'], $metadata['config']['system_hosts'] ?? null);
            $this->assertSame(['172.23.0.2/32'], $metadata['config']['trusted_proxies'] ?? null);
            $this->assertSame(get_expected_schema_version(), $metadata['migration_target'] ?? null);
            $this->assertSame($this->currentSourceCommit(), $metadata['provenance']['source_commit'] ?? null);
            $this->assertSame('sha256:' . str_repeat('b', 64), $metadata['provenance']['image_digest'] ?? null);
            $this->assertTrue(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($metadata['created_at_utc'] ?? '')) === 1);
            $this->assertTrue(preg_match('/^[a-f0-9]{64}$/', (string) ($metadata['manifest_hmac'] ?? '')) === 1);
            $this->assertSame(hash_file('sha256', $fixture['dbPath']), $metadata['source']['database_files']['database']['sha256'] ?? null);
        } finally {
            $this->deleteTree($fixture['root']);
            $this->deleteTree($backupDir);
        }
    }

    public function test_backup_script_preserves_matching_app_key_bytes(): void
    {
        $fixture = $this->createRecoveryFixture();
        $backupDir = $this->tempDir('cloaking-backup-key-');

        try {
            $result = $this->runBackupCommand($fixture, $backupDir);

            $this->assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
            $this->assertSame(
                trim((string) file_get_contents($fixture['appKeyPath'])),
                trim((string) file_get_contents($backupDir . '/app.key')),
                'Backup app key does not match the live runtime key.'
            );

            $metadata = $this->readJsonFile($backupDir . '/backup-metadata.json');
            $this->assertSame(
                hash_file('sha256', $fixture['appKeyPath']),
                $metadata['artifacts']['app_key']['sha256'] ?? null
            );
        } finally {
            $this->deleteTree($fixture['root']);
            $this->deleteTree($backupDir);
        }
    }

    public function test_backup_script_enforces_private_directory_and_secret_file_modes(): void
    {
        $fixture = $this->createRecoveryFixture();
        $backupDir = $this->tempDir('cloaking-backup-modes-');
        chmod($backupDir, 0755);

        try {
            $result = $this->runBackupCommand($fixture, $backupDir);
            $this->assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
            clearstatcache(true, $backupDir);
            $this->assertSame('0700', substr(sprintf('%o', fileperms($backupDir)), -4));
            foreach (['cloaking.sqlite', 'app.key', 'config.local.php', 'backup-metadata.json', 'SHA256SUMS'] as $file) {
                $path = $backupDir . DIRECTORY_SEPARATOR . $file;
                clearstatcache(true, $path);
                $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4), "Unsafe mode for {$file}");
            }
        } finally {
            $this->deleteTree($fixture['root']);
            $this->deleteTree($backupDir);
        }
    }

    public function test_backup_script_rejects_missing_config_argument(): void
    {
        $fixture = $this->createRecoveryFixture();
        $backupDir = $this->tempDir('cloaking-backup-missing-config-');

        try {
            $result = $this->runBackupCommand($fixture, $backupDir, null, false);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], '--config is required'),
                'Backup should fail closed when the deployment config is omitted.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
            $this->deleteTree($backupDir);
        }
    }

    public function test_backup_script_rejects_config_that_does_not_match_runtime_paths(): void
    {
        $fixture = $this->createRecoveryFixture();
        $backupDir = $this->tempDir('cloaking-backup-wrong-config-');
        $wrongConfigPath = $fixture['root'] . DIRECTORY_SEPARATOR . 'wrong-config.local.php';

        try {
            file_put_contents($wrongConfigPath, sprintf(
                <<<'PHP'
<?php
define('APP_RUNTIME_DIR', %s);
define('DB_PATH', %s);
define('LOG_PATH', %s);
define('APP_BASE_URL', 'https://wrong.example.test');
define('SYSTEM_HOSTS', ['wrong.example.test']);
define('TRUSTED_PROXIES', ['172.23.0.2/32']);
PHP,
                var_export($fixture['root'] . DIRECTORY_SEPARATOR . 'other-runtime', true),
                var_export($fixture['root'] . DIRECTORY_SEPARATOR . 'other-runtime' . DIRECTORY_SEPARATOR . 'cloaking.sqlite', true),
                var_export($fixture['root'] . DIRECTORY_SEPARATOR . 'other-runtime' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR, true)
            ));

            $result = $this->runBackupCommand($fixture, $backupDir, $wrongConfigPath);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'does not match --db'),
                'Backup should reject a deployment config that points at different runtime paths.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
            $this->deleteTree($backupDir);
        }
    }

    public function test_restore_rehearsal_rejects_tampered_backup_checksums(): void
    {
        $fixture = $this->createRecoveryFixture();
        $backupDir = $this->tempDir('cloaking-backup-tamper-');
        $evidenceDir = $this->tempDir('cloaking-evidence-tamper-');

        try {
            $backup = $this->runBackupCommand($fixture, $backupDir);
            $this->assertSame(0, $backup['exit'], $backup['stderr'] . $backup['stdout']);

            file_put_contents($backupDir . '/app.key', "tampered\n");

            $restore = $this->runRestoreCommand($backupDir, $evidenceDir);

            $this->assertSame(1, $restore['exit']);
            $this->assertTrue(
                str_contains($restore['stderr'] . $restore['stdout'], 'Checksum mismatch'),
                'Restore rehearsal should reject a tampered backup.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
            $this->deleteTree($backupDir);
            $this->deleteTree($evidenceDir);
        }
    }

    public function test_restore_rehearsal_rejects_corrupt_database_backups_even_with_fresh_checksums(): void
    {
        $fixture = $this->createRecoveryFixture();
        $backupDir = $this->tempDir('cloaking-backup-corrupt-');
        $evidenceDir = $this->tempDir('cloaking-evidence-corrupt-');

        try {
            $backup = $this->runBackupCommand($fixture, $backupDir);
            $this->assertSame(0, $backup['exit'], $backup['stderr'] . $backup['stdout']);

            file_put_contents($backupDir . '/cloaking.sqlite', "not a sqlite backup\n");
            $this->rewriteChecksums($backupDir);

            $restore = $this->runRestoreCommand($backupDir, $evidenceDir);

            $this->assertSame(1, $restore['exit']);
            $this->assertTrue(
                str_contains($restore['stderr'] . $restore['stdout'], 'integrity'),
                'Restore rehearsal should fail the integrity check for corrupt backups.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
            $this->deleteTree($backupDir);
            $this->deleteTree($evidenceDir);
        }
    }

    public function test_restore_rehearsal_records_smoke_results_without_mutating_source_runtime(): void
    {
        $fixture = $this->createRecoveryFixture();
        $backupDir = $this->tempDir('cloaking-backup-restore-');
        $evidenceDir = $this->tempDir('cloaking-evidence-restore-');

        try {
            $sourceChecksum = hash_file('sha256', $fixture['dbPath']);
            $backup = $this->runBackupCommand($fixture, $backupDir);
            $this->assertSame(0, $backup['exit'], $backup['stderr'] . $backup['stdout']);

            $restore = $this->runRestoreCommand($backupDir, $evidenceDir);

            $this->assertSame(0, $restore['exit'], $restore['stderr'] . $restore['stdout']);
            $this->assertSame($sourceChecksum, hash_file('sha256', $fixture['dbPath']), 'Restore rehearsal should not mutate the source database.');
            $this->assertTrue(is_file($evidenceDir . '/rehearsal-summary.json'), 'Restore rehearsal summary was not written.');
            $this->assertTrue(is_file($evidenceDir . '/smoke-results.json'), 'Restore smoke results were not written.');

            $summary = $this->readJsonFile($evidenceDir . '/rehearsal-summary.json');
            $smoke = $this->readJsonFile($evidenceDir . '/smoke-results.json');

            $this->assertSame('ok', $summary['integrity_check'] ?? null);
            $this->assertSame(0, $summary['migration_exit'] ?? null);
            $this->assertTrue(is_numeric($summary['rpo_seconds'] ?? null), 'RPO should be recorded in the rehearsal summary.');
            $this->assertTrue(is_numeric($summary['rto_seconds'] ?? null), 'RTO should be recorded in the rehearsal summary.');
            $this->assertSame(302, $smoke['login']['status'] ?? null);
            $this->assertSame(200, $smoke['dashboard']['status'] ?? null);
            $this->assertSame(401, $smoke['api']['status'] ?? null);
            $this->assertSame(302, $smoke['link']['status'] ?? null);
            $this->assertSame(1, $smoke['logical_data']['admin_users'] ?? null);
            $this->assertSame(1, $smoke['logical_data']['active_links'] ?? null);
            $this->assertSame($this->currentSourceCommit(), $summary['provenance']['source_commit'] ?? null);
            $this->assertSame('sha256:' . str_repeat('b', 64), $summary['provenance']['image_digest'] ?? null);
        } finally {
            $this->deleteTree($fixture['root']);
            $this->deleteTree($backupDir);
            $this->deleteTree($evidenceDir);
        }
    }

    public function test_restore_rehearsal_migrates_a_verified_legacy_backup(): void
    {
        $fixture = $this->createRecoveryFixture(true);
        $backupDir = $this->tempDir('cloaking-backup-legacy-');
        $evidenceDir = $this->tempDir('cloaking-evidence-legacy-');

        try {
            $backup = $this->runBackupCommand($fixture, $backupDir);
            $this->assertSame(0, $backup['exit'], $backup['stderr'] . $backup['stdout']);

            $restore = $this->runRestoreCommand($backupDir, $evidenceDir);

            $this->assertSame(0, $restore['exit'], $restore['stderr'] . $restore['stdout']);
            $this->assertTrue(str_contains(
                (string) file_get_contents($evidenceDir . '/migration.stdout.log'),
                sprintf('Applied schema version %d (%s).', get_expected_schema_version(), implode(',', range(1, get_expected_schema_version())))
            ));
            $smoke = $this->readJsonFile($evidenceDir . '/smoke-results.json');
            $this->assertSame(302, $smoke['login']['status'] ?? null);
            $this->assertSame(200, $smoke['dashboard']['status'] ?? null);
            $this->assertSame(1, $smoke['logical_data']['active_links'] ?? null);
        } finally {
            $this->deleteTree($fixture['root']);
            $this->deleteTree($backupDir);
            $this->deleteTree($evidenceDir);
        }
    }

    public function test_restore_rehearsal_rejects_logically_empty_database(): void
    {
        $fixture = $this->createRecoveryFixture();
        $backupDir = $this->tempDir('cloaking-backup-empty-');
        $evidenceDir = $this->tempDir('cloaking-evidence-empty-');

        try {
            $db = new PDO('sqlite:' . $fixture['dbPath']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('DELETE FROM links; DELETE FROM users');

            $backup = $this->runBackupCommand($fixture, $backupDir);
            $this->assertSame(0, $backup['exit'], $backup['stderr'] . $backup['stdout']);
            $restore = $this->runRestoreCommand($backupDir, $evidenceDir);

            $this->assertSame(1, $restore['exit']);
            $this->assertTrue(str_contains($restore['stderr'] . $restore['stdout'], 'logical recovery'));
        } finally {
            $this->deleteTree($fixture['root']);
            $this->deleteTree($backupDir);
            $this->deleteTree($evidenceDir);
        }
    }

    /**
     * @return array{root:string,runtimeDir:string,logsDir:string,dbPath:string,appKeyPath:string,configPath:string}
     */
    private function createRecoveryFixture(bool $legacy = false): array
    {
        $root = $this->tempDir('cloaking-recovery-');
        $runtimeDir = $root . DIRECTORY_SEPARATOR . 'runtime';
        $logsDir = $runtimeDir . DIRECTORY_SEPARATOR . 'logs';
        $dbPath = $runtimeDir . DIRECTORY_SEPARATOR . 'cloaking.sqlite';
        $appKeyPath = $runtimeDir . DIRECTORY_SEPARATOR . 'app.key';
        $configPath = $root . DIRECTORY_SEPARATOR . 'config.local.php';

        mkdir($runtimeDir, 0700, true);
        mkdir($logsDir, 0700, true);

        file_put_contents($appKeyPath, str_repeat('ab', 32) . PHP_EOL);
        $config = <<<'PHP'
<?php
define('APP_RUNTIME_DIR', %s);
define('DB_PATH', %s);
define('LOG_PATH', %s);
define('APP_BASE_URL', 'https://app.example.test');
define('SYSTEM_HOSTS', ['127.0.0.1', 'app.example.test']);
define('TRUSTED_PROXIES', ['172.23.0.2/32']);
PHP;
        file_put_contents($configPath, sprintf(
            $config,
            var_export($runtimeDir, true),
            var_export($dbPath, true),
            var_export($logsDir . DIRECTORY_SEPARATOR, true)
        ));

        $setupScript = $root . DIRECTORY_SEPARATOR . 'setup-recovery-fixture.php';
        $script = $legacy ? <<<'PHP'
<?php
define('DB_PATH', %s);
require %s;
$db = new PDO('sqlite:' . DB_PATH);
database_configure_connection($db);
$db->exec((string) file_get_contents(%s));
$db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
    ->execute([1, 'owner', %s, 'recovery-admin-api-key']);
$db->prepare('INSERT INTO links (user_id, slug, name, offer_url) VALUES (?, ?, ?, ?)')
    ->execute([1, 'promo', 'Promo', 'https://offers.example/promo']);
PHP
            : <<<'PHP'
<?php
define('DB_PATH', %s);
require %s;
setupDatabase();
$db = new PDO('sqlite:' . DB_PATH);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->prepare('INSERT INTO users (id, username, password, api_key, must_change_password) VALUES (?, ?, ?, ?, 0)')
    ->execute([1, 'owner', %s, 'recovery-admin-api-key']);
$db->prepare('INSERT INTO links (user_id, slug, name, offer_url) VALUES (?, ?, ?, ?)')
    ->execute([1, 'promo', 'Promo', 'https://offers.example/promo']);
PHP;
        $arguments = [
            var_export($dbPath, true),
            var_export(APP_ROOT . '/includes/database.php', true),
        ];
        if ($legacy) {
            $arguments[] = var_export(APP_ROOT . '/migrations/001_initial_schema.sql', true);
        }
        $arguments[] = var_export(password_hash('StrongPass123!', PASSWORD_DEFAULT), true);
        file_put_contents($setupScript, vsprintf($script, $arguments));

        $result = $this->runProcess([PHP_BINARY, $setupScript], APP_ROOT);
        if ($result['exit'] !== 0) {
            throw new RuntimeException('Failed to create recovery fixture: ' . $result['stderr'] . $result['stdout']);
        }

        return [
            'root' => $root,
            'runtimeDir' => $runtimeDir,
            'logsDir' => $logsDir,
            'dbPath' => $dbPath,
            'appKeyPath' => $appKeyPath,
            'configPath' => $configPath,
        ];
    }

    /**
     * @param array{root:string,runtimeDir:string,logsDir:string,dbPath:string,appKeyPath:string,configPath:string} $fixture
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runBackupCommand(array $fixture, string $backupDir, ?string $configPath = null, bool $includeConfig = true): array
    {
        $command = [
            'bash',
            APP_ROOT . '/ops/backup_sqlite.sh',
            '--app-root=' . APP_ROOT,
            '--db=' . $fixture['dbPath'],
            '--app-key=' . $fixture['appKeyPath'],
            '--output=' . $backupDir,
            '--migration-target=' . get_expected_schema_version(),
            '--source-commit=' . $this->currentSourceCommit(),
            '--image-digest=sha256:' . str_repeat('b', 64),
        ];

        if ($includeConfig) {
            $command[] = '--config=' . ($configPath ?? $fixture['configPath']);
        }

        return $this->runProcess($command, APP_ROOT);
    }

    /**
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runRestoreCommand(string $backupDir, string $evidenceDir): array
    {
        return $this->runProcess([
            'bash',
            APP_ROOT . '/ops/restore_rehearsal.sh',
            '--app-root=' . APP_ROOT,
            '--backup=' . $backupDir,
            '--evidence-dir=' . $evidenceDir,
            '--admin-username=owner',
            '--admin-password-stdin',
            '--expected-source-commit=' . $this->currentSourceCommit(),
            '--expected-image-digest=sha256:' . str_repeat('b', 64),
        ], APP_ROOT, "StrongPass123!\n");
    }

    private function rewriteChecksums(string $backupDir): void
    {
        $lines = [];
        foreach (['cloaking.sqlite', 'app.key', 'config.local.php', 'backup-metadata.json'] as $filename) {
            $path = $backupDir . DIRECTORY_SEPARATOR . $filename;
            $lines[] = hash_file('sha256', $path) . '  ' . $filename;
        }

        file_put_contents($backupDir . '/SHA256SUMS', implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function currentSourceCommit(): string
    {
        $result = $this->runProcess(['git', 'rev-parse', 'HEAD'], APP_ROOT);
        if ($result['exit'] !== 0 || preg_match('/^[a-f0-9]{40}$/', trim($result['stdout'])) !== 1) {
            throw new RuntimeException('Unable to resolve recovery test source commit.');
        }

        return trim($result['stdout']);
    }

    /**
     * @param list<string> $command
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runProcess(array $command, string $cwd, string $stdin = ''): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start recovery command.');
        }

        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
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

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON file: ' . $path);
        }

        return $decoded;
    }

    private function tempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        do {
            $dir = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        } while (file_exists($dir));

        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create temporary directory: {$dir}");
        }

        return $dir;
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
}
