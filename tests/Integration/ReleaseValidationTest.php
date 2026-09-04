<?php

require_once __DIR__ . '/../bootstrap.php';

final class ReleaseValidationTest extends TestCase
{
    public function test_release_validator_rejects_dirty_git_inputs(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            $release = $this->prepareApprovedTaggedRelease($fixture);
            file_put_contents($fixture['repo'] . '/README.md', "dirty working tree\n", FILE_APPEND);

            $result = $this->runValidator($fixture, [
                '--phase=prepublish',
                '--git-tag=' . $release['tag'],
                '--git-commit=' . $release['commit'],
                '--git-ref-protected=true',
                '--legal-approval-attestation=' . $release['attestation'],
            ]);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'clean checkout'),
                'Validator should reject release inputs from a dirty checkout.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_rejects_pending_legal_review_even_with_attestation(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            $tag = 'v1.2.3';
            $commit = $this->gitOutput($fixture['repo'], ['git', 'rev-parse', 'HEAD']);
            $this->runProcess(['git', 'tag', $tag, $commit], $fixture['repo']);
            $pendingAttestation = $this->buildAttestation($fixture['legalPath'], 'External Reviewer');

            $result = $this->runValidator($fixture, [
                '--phase=prepublish',
                '--git-tag=' . $tag,
                '--git-commit=' . $commit,
                '--git-ref-protected=true',
                '--legal-approval-attestation=' . $pendingAttestation,
            ]);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'LEGAL_PLATFORM_REVIEW.md has not been completed'),
                'Validator should reject a pending legal/platform review record even if an attestation is injected.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_rejects_self_authored_approval_without_attestation(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            $release = $this->prepareApprovedTaggedRelease($fixture);

            $result = $this->runValidator($fixture, [
                '--phase=prepublish',
                '--git-tag=' . $release['tag'],
                '--git-commit=' . $release['commit'],
                '--git-ref-protected=true',
            ]);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'LEGAL_APPROVAL_ATTESTATION'),
                'Validator should require an injected legal approval attestation.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_rejects_mismatched_legal_attestation_digest(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            $release = $this->prepareApprovedTaggedRelease($fixture);
            $attestation = json_encode([
                'authorized_by' => 'Protected Legal Environment',
                'decision' => 'approved',
                'review_sha256' => str_repeat('c', 64),
                'issued_at' => '2026-09-04T20:00:00Z',
            ], JSON_UNESCAPED_SLASHES);

            $result = $this->runValidator($fixture, [
                '--phase=prepublish',
                '--git-tag=' . $release['tag'],
                '--git-commit=' . $release['commit'],
                '--git-ref-protected=true',
                '--legal-approval-attestation=' . $attestation,
            ]);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'review artifact digest'),
                'Validator should reject an attestation whose digest does not match the review artifact.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_rejects_missing_digest_metadata(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            $release = $this->prepareApprovedTaggedRelease($fixture);

            $result = $this->runValidator($fixture, [
                '--phase=published',
                '--legal-approval-attestation=' . $release['attestation'],
            ]);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'release metadata'),
                'Validator should reject missing digest metadata.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_rejects_mutable_image_references(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            $release = $this->prepareApprovedTaggedRelease($fixture);
            $this->writeMetadata($fixture, $release['tag'], $release['commit'], [
                'ref' => 'ghcr.io/example/cloaking:' . $release['tag'],
                'digest' => 'sha256:' . str_repeat('b', 64),
            ]);

            $result = $this->runValidator($fixture, [
                '--phase=published',
                '--legal-approval-attestation=' . $release['attestation'],
            ]);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'immutable sha256 digest'),
                'Validator should reject mutable image references.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_rejects_tag_that_does_not_point_at_claimed_release_commit(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            $release = $this->prepareApprovedTaggedRelease($fixture);
            file_put_contents($fixture['repo'] . '/README.md', "# Fixture\nrelease moved\n");
            $this->commitAll($fixture['repo'], 'Advance head after tagging');
            $headCommit = $this->gitOutput($fixture['repo'], ['git', 'rev-parse', 'HEAD']);

            $result = $this->runValidator($fixture, [
                '--phase=prepublish',
                '--git-tag=' . $release['tag'],
                '--git-commit=' . $headCommit,
                '--git-ref-protected=true',
                '--legal-approval-attestation=' . $this->buildAttestation($fixture['legalPath'], 'Protected Legal Environment'),
            ]);

            $this->assertSame(1, $result['exit']);
            $this->assertTrue(
                str_contains($result['stderr'] . $result['stdout'], 'does not point at the claimed release commit'),
                'Validator should reject tags that do not resolve to the claimed release commit.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_accepts_prepublish_gate_only_with_injected_attestation(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            $release = $this->prepareApprovedTaggedRelease($fixture);

            $result = $this->runValidator($fixture, [
                '--phase=prepublish',
                '--git-tag=' . $release['tag'],
                '--git-commit=' . $release['commit'],
                '--git-ref-protected=true',
                '--legal-approval-attestation=' . $release['attestation'],
            ]);

            $this->assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
            $this->assertTrue(
                str_contains($result['stdout'], 'PASS'),
                'Validator should accept a prepublish gate only when the approval attestation is injected.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    public function test_release_validator_accepts_published_inputs_with_real_tag_commit_and_attestation(): void
    {
        $fixture = $this->createReleaseFixture();

        try {
            $release = $this->prepareApprovedTaggedRelease($fixture);
            $this->writeMetadata($fixture, $release['tag'], $release['commit'], [
                'ref' => 'ghcr.io/example/cloaking:' . $release['tag'] . '@sha256:' . str_repeat('b', 64),
                'digest' => 'sha256:' . str_repeat('b', 64),
            ]);

            $result = $this->runValidator($fixture, [
                '--phase=published',
                '--legal-approval-attestation=' . $release['attestation'],
            ]);

            $this->assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
            $this->assertTrue(
                str_contains($result['stdout'], 'PASS'),
                'Validator should accept published inputs only when the attestation and real tag/commit provenance match.'
            );
        } finally {
            $this->deleteTree($fixture['root']);
        }
    }

    /**
     * @return array{root:string,repo:string,legalPath:string,metadataPath:string,sbomPath:string}
     */
    private function createReleaseFixture(): array
    {
        $root = $this->tempDir('cloaking-release-');
        $repo = $root . DIRECTORY_SEPARATOR . 'repo';
        $artifacts = $root . DIRECTORY_SEPARATOR . 'release-artifacts';
        mkdir($repo, 0700, true);
        mkdir($artifacts, 0700, true);

        file_put_contents($repo . '/README.md', "# Fixture\n");
        $legalPath = $repo . '/LEGAL_PLATFORM_REVIEW.md';
        $metadataPath = $artifacts . '/release-metadata.json';
        $sbomPath = $artifacts . '/sbom.spdx.json';

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
            'sbomPath' => $sbomPath,
        ];
    }

    /**
     * @param array{repo:string,legalPath:string} $fixture
     * @return array{tag:string,commit:string,attestation:string}
     */
    private function prepareApprovedTaggedRelease(array $fixture): array
    {
        file_put_contents($fixture['legalPath'], <<<MD
# Legal Platform Review

Authorized Reviewer: Alex Counsel
Scope: Meta, Google, TikTok review status for release v1.2.3
Decision: approved
Evidence: Ticket LEG-123 with platform screenshots
Decision Date: 2026-09-04
MD
        );
        $this->commitAll($fixture['repo'], 'Record approved legal review fixture');

        $tag = 'v1.2.3';
        $commit = $this->gitOutput($fixture['repo'], ['git', 'rev-parse', 'HEAD']);
        $tagResult = $this->runProcess(['git', 'tag', $tag, $commit], $fixture['repo']);
        if ($tagResult['exit'] !== 0) {
            throw new RuntimeException('Failed to create release tag: ' . $tagResult['stderr'] . $tagResult['stdout']);
        }

        return [
            'tag' => $tag,
            'commit' => $commit,
            'attestation' => $this->buildAttestation($fixture['legalPath'], 'Protected Legal Environment'),
        ];
    }

    /**
     * @param array{metadataPath:string,sbomPath:string} $fixture
     * @param array{ref:string,digest:string} $image
     */
    private function writeMetadata(array $fixture, string $tag, string $commit, array $image): void
    {
        file_put_contents($fixture['sbomPath'], "{\"spdxVersion\":\"SPDX-2.3\"}\n");
        file_put_contents($fixture['metadataPath'], json_encode([
            'git' => [
                'tag' => $tag,
                'commit' => $commit,
                'protected' => true,
            ],
            'image' => [
                'ref' => $image['ref'],
                'digest' => $image['digest'],
            ],
            'sbom' => [
                'path' => $fixture['sbomPath'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function buildAttestation(string $legalPath, string $authorizedBy): string
    {
        $attestation = json_encode([
            'authorized_by' => $authorizedBy,
            'decision' => 'approved',
            'review_sha256' => hash_file('sha256', $legalPath),
            'issued_at' => '2026-09-04T20:00:00Z',
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($attestation) || $attestation === '') {
            throw new RuntimeException('Failed to encode legal approval attestation.');
        }

        return $attestation;
    }

    /**
     * @param array{repo:string,legalPath:string,metadataPath:string} $fixture
     * @param list<string> $extraArgs
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runValidator(array $fixture, array $extraArgs): array
    {
        return $this->runProcess(array_merge([
            PHP_BINARY,
            APP_ROOT . '/ops/validate_release_inputs.php',
            '--repo=' . $fixture['repo'],
            '--legal-review=' . $fixture['legalPath'],
            '--metadata=' . $fixture['metadataPath'],
        ], $extraArgs), APP_ROOT);
    }

    /**
     * @param list<string> $command
     */
    private function gitOutput(string $repo, array $command): string
    {
        $result = $this->runProcess($command, $repo);
        if ($result['exit'] !== 0) {
            throw new RuntimeException('Git command failed: ' . $result['stderr'] . $result['stdout']);
        }

        return trim($result['stdout']);
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
