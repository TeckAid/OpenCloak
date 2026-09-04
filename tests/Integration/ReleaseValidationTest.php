<?php

require_once __DIR__ . '/../bootstrap.php';

final class ReleaseValidationTest extends TestCase
{
    public function test_release_validator_rejects_dirty_git_inputs(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            file_put_contents($fixture['repo'] . '/README.md', "dirty working tree\n", FILE_APPEND);

            $result = $this->runValidator($fixture);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'clean checkout'),
                'Validator should reject release inputs from a dirty checkout.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_rejects_pending_legal_review(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            $result = $this->runValidator($fixture);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'LEGAL_PLATFORM_REVIEW.md'),
                'Validator should reject a pending legal/platform review record.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_rejects_mutable_image_references(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            file_put_contents($fixture['metadataPath'], json_encode([
                'git' => [
                    'tag' => 'v1.2.3',
                    'commit' => str_repeat('a', 40),
                    'protected' => true,
                ],
                'image' => [
                    'ref' => 'ghcr.io/example/cloaking:v1.2.3',
                    'digest' => 'sha256:' . str_repeat('b', 64),
                ],
                'sbom' => [
                    'path' => 'release-artifacts/sbom.spdx.json',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            $this->commitAll($fixture['repo'], 'Use mutable image reference in metadata');

            $result = $this->runValidator($fixture);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'immutable sha256 digest'),
                'Validator should reject mutable image references.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_rejects_missing_digest_metadata(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            file_put_contents($fixture['legalPath'], <<<MD
# Legal Platform Review

Authorized Reviewer: Alex Counsel
Scope: Meta, Google, TikTok review status for release v1.2.3
Decision: approved
Evidence: Ticket LEG-123 with platform screenshots
Decision Date: 2026-09-04
MD
            );
            $this->commitAll($fixture['repo'], 'Approve legal review without metadata');

            $result = $this->runValidator($fixture);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'release metadata'),
                'Validator should reject missing digest metadata.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_accepts_clean_approved_digest_pinned_release_inputs(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            file_put_contents($fixture['legalPath'], <<<MD
# Legal Platform Review

Authorized Reviewer: Alex Counsel
Scope: Meta, Google, TikTok review status for release v1.2.3
Decision: approved
Evidence: Ticket LEG-123 with platform screenshots
Decision Date: 2026-09-04
MD
            );
            file_put_contents($fixture['metadataPath'], json_encode([
                'git' => [
                    'tag' => 'v1.2.3',
                    'commit' => str_repeat('a', 40),
                    'protected' => true,
                ],
                'image' => [
                    'ref' => 'ghcr.io/example/cloaking:v1.2.3@sha256:' . str_repeat('b', 64),
                    'digest' => 'sha256:' . str_repeat('b', 64),
                ],
                'sbom' => [
                    'path' => 'release-artifacts/sbom.spdx.json',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            file_put_contents($fixture['repo'] . '/release-artifacts/sbom.spdx.json', "{\"spdxVersion\":\"SPDX-2.3\"}\n");
            $this->commitAll($fixture['repo'], 'Approve legal review and add release metadata');

            $result = $this->runValidator($fixture);

            $this->assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
            $this->assertTrue(
                str_contains($result['stdout'], 'PASS'),
                'Validator should accept clean, approved, digest-pinned release inputs.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    /**
     * @return array{root:string,repo:string,legalPath:string,metadataPath:string}
     */
    private function createReleaseFixture(): array
    {
        $root = $this->tempDir('cloaking-release-');
        $repo = $root . DIRECTORY_SEPARATOR . 'repo';
        mkdir($repo, 0700, true);
        mkdir($repo . '/ops', 0700, true);
        mkdir($repo . '/release-artifacts', 0700, true);

        file_put_contents($repo . '/README.md', "# Fixture\n");
        $legalPath = $repo . '/LEGAL_PLATFORM_REVIEW.md';
        $metadataPath = $repo . '/release-artifacts/release-metadata.json';

        file_put_contents($legalPath, <<<MD
# Legal Platform Review

Authorized Reviewer: PENDING
Scope: PENDING
Decision: pending
Evidence: PENDING
Decision Date: PENDING
MD
        );

        $this->runProcess(['git', 'init', '-b', 'main'], $repo);
        $this->runProcess(['git', 'config', 'user.name', 'Codex Test'], $repo);
        $this->runProcess(['git', 'config', 'user.email', 'codex@example.test'], $repo);
        $this->commitAll($repo, 'Initial release fixture');

        return [
            'root' => $root,
            'repo' => $repo,
            'legalPath' => $legalPath,
            'metadataPath' => $metadataPath,
        ];
    }

    /**
     * @param array{repo:string,legalPath:string,metadataPath:string} $fixture
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runValidator(array $fixture): array
    {
        return $this->runProcess([
            PHP_BINARY,
            APP_ROOT . '/ops/validate_release_inputs.php',
            '--repo=' . $fixture['repo'],
            '--legal-review=' . $fixture['legalPath'],
            '--metadata=' . $fixture['metadataPath'],
        ], APP_ROOT);
    }

    private function commitAll(string $repo, string $message): void
    {
        $add = $this->runProcess(['git', 'add', '.'], $repo);
        if ($add['exit'] !== 0) {
            throw new RuntimeException('Failed to stage release fixture files: ' . $add['stderr'] . $add['stdout']);
        }

        $commit = $this->runProcess(['git', 'commit', '-m', $message], $repo);
        if ($commit['exit'] !== 0) {
            throw new RuntimeException('Failed to commit release fixture files: ' . $commit['stderr'] . $commit['stdout']);
        }
    }

    /**
     * @param list<string> $command
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runProcess(array $command, string $cwd): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start release validation command.');
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
