<?php

require_once __DIR__ . '/../bootstrap.php';

final class RepositorySafetyTest extends TestCase
{
    public function test_git_ignores_runtime_secrets_databases_and_raw_rehearsal_backups(): void
    {
        $ignored = [
            'config.local.php',
            'data/app.key',
            'data/cloaking.sqlite',
            'data/cloaking.sqlite-wal',
            'data/cloaking.sqlite-shm',
            'runtime/app.key',
            'cloaking-runtime/cloaking.sqlite',
            'logs/error.log',
            'ops/rehearsals/example/backup-artifacts/cloaking.sqlite',
            'ops/rehearsals/example/backup-artifacts/app.key',
            'ops/rehearsals/example/backup-artifacts/config.local.php',
            'ops/rehearsals/example/backup-artifacts/backup-metadata.json',
        ];

        foreach ($ignored as $path) {
            $result = $this->runProcess(['git', 'check-ignore', '--no-index', '--quiet', $path]);
            $this->assertSame(0, $result['exit'], "Expected git to ignore {$path}");
        }

        foreach (['data/.gitkeep', 'logs/.gitkeep'] as $path) {
            $result = $this->runProcess(['git', 'check-ignore', '--no-index', '--quiet', $path]);
            $this->assertSame(1, $result['exit'], "Expected git to keep tracking {$path}");
        }
    }

    public function test_production_config_uses_the_canonical_mounted_runtime_tree(): void
    {
        $result = $this->runProcess([
            PHP_BINARY,
            '-r',
            'require $argv[1]; echo json_encode([APP_RUNTIME_DIR, DB_PATH, LOG_PATH]);',
            APP_ROOT . '/ops/config.local.php.example',
        ]);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame(
            ['/srv/cloaking/runtime', '/srv/cloaking/runtime/cloaking.sqlite', '/srv/cloaking/runtime/logs/'],
            json_decode($result['stdout'], true)
        );
    }

    public function test_caddy_example_does_not_redirect_an_unmatched_host(): void
    {
        $config = (string) file_get_contents(APP_ROOT . '/ops/Caddyfile.example');

        $this->assertFalse(
            preg_match('/http:\/\/\s*\{[^}]*redir\s+https:\/\/\{host\}/s', $config) === 1,
            'An unmatched Host must never be reflected into an HTTPS redirect.'
        );
        $this->assertTrue(str_contains($config, 'respond "unknown host" 421'));
    }

    public function test_generated_app_key_is_atomic_valid_and_mode_0600(): void
    {
        $runtime = $this->tempDir('cloaking-key-');
        $script = $runtime . '/load-config.php';

        try {
            file_put_contents($script, sprintf(
                "<?php\ndefine('APP_RUNTIME_DIR', %s);\nrequire %s;\necho APP_KEY, \"\\n\", substr(sprintf('%%o', fileperms(APP_RUNTIME_DIR . '/app.key')), -4), \"\\n\";\n",
                var_export($runtime . '/state', true),
                var_export(APP_ROOT . '/config.php', true)
            ));

            $workers = [];
            for ($index = 0; $index < 6; $index++) {
                $process = proc_open(
                    [PHP_BINARY, $script],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    APP_ROOT
                );
                if (!is_resource($process)) {
                    $this->fail('Unable to start concurrent app-key loader.');
                }
                fclose($pipes[0]);
                $workers[] = [$process, $pipes[1], $pipes[2]];
            }

            $keys = [];
            foreach ($workers as [$process, $stdoutPipe, $stderrPipe]) {
                $stdout = (string) stream_get_contents($stdoutPipe);
                fclose($stdoutPipe);
                $stderr = (string) stream_get_contents($stderrPipe);
                fclose($stderrPipe);
                $this->assertSame(0, proc_close($process), $stderr);
                $lines = preg_split('/\R/', trim($stdout)) ?: [];
                $this->assertTrue(preg_match('/^[a-f0-9]{64}$/', $lines[0] ?? '') === 1);
                $this->assertSame('0600', $lines[1] ?? '');
                $keys[] = $lines[0] ?? '';
            }

            $this->assertSame(1, count(array_unique($keys)), 'Concurrent startup generated different application keys.');
            $second = $this->runProcess([PHP_BINARY, $script]);
            $secondLines = preg_split('/\R/', trim($second['stdout'])) ?: [];
            $this->assertSame(0, $second['exit'], $second['stderr']);
            $this->assertSame($keys[0] ?? '', $secondLines[0] ?? '');
            $this->assertSame('0600', $secondLines[1] ?? '');
        } finally {
            $this->deleteTree($runtime);
        }
    }

    public function test_app_key_generation_fails_closed_when_runtime_cannot_be_created(): void
    {
        $runtime = $this->tempDir('cloaking-key-failure-');
        $blockedPath = $runtime . '/not-a-directory';
        $script = $runtime . '/load-config.php';

        try {
            file_put_contents($blockedPath, 'blocked');
            file_put_contents($script, sprintf(
                "<?php\ndefine('APP_RUNTIME_DIR', %s);\nrequire %s;\n",
                var_export($blockedPath, true),
                var_export(APP_ROOT . '/config.php', true)
            ));

            $result = $this->runProcess([PHP_BINARY, $script]);
            $this->assertSame(1, $result['exit']);
            $this->assertTrue(str_contains($result['stderr'] . $result['stdout'], 'application key'));
        } finally {
            $this->deleteTree($runtime);
        }
    }

    /**
     * @param list<string> $command
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runProcess(array $command): array
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            APP_ROOT
        );
        if (!is_resource($process)) {
            $this->fail('Unable to start repository safety check.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    private function tempDir(string $prefix): string
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create test directory.');
        }

        return $path;
    }

    private function deleteTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
