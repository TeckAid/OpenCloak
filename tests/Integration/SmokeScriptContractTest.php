<?php

require_once __DIR__ . '/../bootstrap.php';

final class SmokeScriptContractTest extends TestCase
{
    public function test_smoke_script_help_mentions_explicit_client_scope_inputs(): void
    {
        $result = $this->runProcess(
            ['bash', APP_ROOT . '/ops/smoke_test.sh', '--help'],
            APP_ROOT
        );

        $this->assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        $this->assertTrue(str_contains($result['stdout'], '--client-index-path'));
        $this->assertTrue(str_contains($result['stdout'], '--assigned-campaign-id'));
        $this->assertTrue(str_contains($result['stdout'], '--other-campaign-id'));
        $this->assertTrue(str_contains($result['stdout'], '--admin-password-stdin'));
        $this->assertTrue(str_contains($result['stdout'], '--ca-bundle'));
        $this->assertFalse(str_contains($result['stdout'], "--admin-password='"));
    }

    public function test_smoke_script_rejects_explicit_client_artifact_without_scope_ids(): void
    {
        $fixture = $this->tempDir('cloaking-smoke-contract-');
        $clientIndex = $fixture . '/index.php';
        file_put_contents($clientIndex, "<?php\n");

        try {
            $result = $this->runProcess(
                [
                    'bash',
                    APP_ROOT . '/ops/smoke_test.sh',
                    '--https-base-url=https://app.example.com',
                    '--admin-username=owner',
                    '--admin-password-stdin',
                    '--restart-command=true',
                    '--client-index-path=' . $clientIndex,
                ],
                APP_ROOT,
                "StrongPass123!\n"
            );

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], '--assigned-campaign-id and --other-campaign-id are required when --client-index-path is supplied'),
                'Smoke script should reject an explicit client artifact without explicit campaign scope inputs.'
            );
        } finally {
            $this->deleteTree($fixture);
        }
    }

    public function test_smoke_script_rejects_passwords_in_process_arguments(): void
    {
        $result = $this->runProcess(
            [
                'bash',
                APP_ROOT . '/ops/smoke_test.sh',
                '--https-base-url=https://app.example.com',
                '--admin-username=owner',
                '--admin-password=VisibleSecret123!',
                '--restart-command=true',
            ],
            APP_ROOT
        );

        $this->assertSame(1, $result['exit']);
        $this->assertTrue(str_contains($result['stderr'] . $result['stdout'], 'passwords in process arguments are not supported'));
    }

    public function test_smoke_script_rejects_a_plaintext_credentials_base_url(): void
    {
        $result = $this->runProcess(
            [
                'bash',
                APP_ROOT . '/ops/smoke_test.sh',
                '--https-base-url=http://app.example.com',
                '--admin-username=owner',
                '--admin-password-stdin',
                '--restart-command=true',
            ],
            APP_ROOT,
            "StrongPass123!\n"
        );

        $this->assertSame(1, $result['exit']);
        $this->assertTrue(str_contains($result['stderr'] . $result['stdout'], '--https-base-url must use https://'));
    }

    public function test_smoke_transport_verifies_tls_by_default_and_only_adds_an_explicit_ca_bundle(): void
    {
        $script = (string) file_get_contents(APP_ROOT . '/ops/smoke_test.sh');

        $this->assertFalse(preg_match('/(^|[[:space:]])(-k|--insecure)([[:space:]]|$)/m', $script) === 1);
        $this->assertTrue(str_contains($script, 'CURL_TLS_ARGS=(--cacert "${CA_BUNDLE}")'));
        $this->assertTrue(str_contains($script, 'curl -sS "${CURL_TLS_ARGS[@]}"'));
    }

    public function test_restore_rehearsal_rejects_passwords_in_process_arguments(): void
    {
        $result = $this->runProcess(
            [
                'bash',
                APP_ROOT . '/ops/restore_rehearsal.sh',
                '--admin-password=VisibleSecret123!',
            ],
            APP_ROOT
        );

        $this->assertSame(1, $result['exit']);
        $this->assertTrue(str_contains($result['stderr'] . $result['stdout'], 'Passwords in process arguments are not supported'));
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
            throw new RuntimeException('Unable to start smoke script command.');
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

    private function tempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        do {
            $dir = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        } while (file_exists($dir));

        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create temporary directory: ' . $dir);
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
