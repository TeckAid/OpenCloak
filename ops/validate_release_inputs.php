<?php

declare(strict_types=1);

/**
 * Validates release prerequisites that engineering can prove locally:
 * a clean git checkout, a completed legal/platform review record, and
 * digest-backed release metadata with an SBOM artifact.
 */

$options = parseArguments($argv);
$failures = [];

$repoPath = normalizePath($options['repo'] ?? getcwd() ?: '.');
$legalReviewPath = normalizePath($options['legal-review'] ?? $repoPath . DIRECTORY_SEPARATOR . 'LEGAL_PLATFORM_REVIEW.md');
$metadataPath = normalizePath($options['metadata'] ?? $repoPath . DIRECTORY_SEPARATOR . 'release-artifacts' . DIRECTORY_SEPARATOR . 'release-metadata.json');

if (!is_dir($repoPath)) {
    failFast(sprintf('Repository path does not exist: %s', $repoPath));
}

$gitStatus = runProcess(['git', 'status', '--porcelain', '--untracked-files=all'], $repoPath);
if ($gitStatus['exit'] !== 0) {
    $failures[] = "Unable to read git status for release validation.\n" . trim($gitStatus['stderr'] . $gitStatus['stdout']);
} elseif (trim($gitStatus['stdout']) !== '') {
    $failures[] = 'Release inputs must come from a clean checkout with no tracked or untracked changes.';
}

$review = parseLegalReview($legalReviewPath, $failures);
$metadata = parseMetadata($metadataPath, $failures);

if ($review !== null) {
    validateLegalReview($review, $failures);
}

if ($metadata !== null) {
    validateMetadata($metadata, $repoPath, $failures);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS: release inputs are clean, approved, and digest-pinned.\n");
exit(0);

/**
 * @return array<string, string>
 */
function parseArguments(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            failFast('Expected arguments in --name=value form.');
        }

        $parts = explode('=', substr($argument, 2), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            failFast(sprintf('Invalid argument: %s', $argument));
        }

        $options[$parts[0]] = $parts[1];
    }

    return $options;
}

function normalizePath(string $path): string
{
    if ($path === '') {
        return $path;
    }

    if ($path[0] === DIRECTORY_SEPARATOR) {
        return $path;
    }

    return getcwd() . DIRECTORY_SEPARATOR . $path;
}

/**
 * @param array<int, string> $failures
 * @return array<string, string>|null
 */
function parseLegalReview(string $path, array &$failures): ?array
{
    if (!is_file($path)) {
        $failures[] = sprintf('LEGAL_PLATFORM_REVIEW.md is missing: %s', $path);
        return null;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        $failures[] = sprintf('Unable to read LEGAL_PLATFORM_REVIEW.md: %s', $path);
        return null;
    }

    $fields = [];
    foreach (preg_split('/\R/', $content) ?: [] as $line) {
        if (preg_match('/^(Authorized Reviewer|Scope|Decision|Evidence|Decision Date):\s*(.+)\s*$/', $line, $matches) === 1) {
            $fields[$matches[1]] = trim($matches[2]);
        }
    }

    return $fields;
}

/**
 * @param array<int, string> $failures
 * @return array<string, mixed>|null
 */
function parseMetadata(string $path, array &$failures): ?array
{
    if (!is_file($path)) {
        $failures[] = sprintf('Required release metadata is missing: %s', $path);
        return null;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        $failures[] = sprintf('Release metadata is not valid JSON: %s', $path);
        return null;
    }

    return $decoded;
}

/**
 * @param array<string, string> $review
 * @param array<int, string> $failures
 */
function validateLegalReview(array $review, array &$failures): void
{
    foreach (['Authorized Reviewer', 'Scope', 'Decision', 'Evidence', 'Decision Date'] as $field) {
        $value = $review[$field] ?? '';
        if (isPendingValue($value)) {
            $failures[] = sprintf('LEGAL_PLATFORM_REVIEW.md has not been completed for %s.', $field);
        }
    }

    if (($review['Decision'] ?? '') !== '' && strcasecmp($review['Decision'], 'approved') !== 0) {
        $failures[] = 'LEGAL_PLATFORM_REVIEW.md decision must be approved by an authorized reviewer before release.';
    }
}

/**
 * @param array<string, mixed> $metadata
 * @param array<int, string> $failures
 */
function validateMetadata(array $metadata, string $repoPath, array &$failures): void
{
    $tag = getNestedString($metadata, ['git', 'tag']);
    $commit = getNestedString($metadata, ['git', 'commit']);
    $protected = $metadata['git']['protected'] ?? null;
    $imageRef = getNestedString($metadata, ['image', 'ref']);
    $digest = getNestedString($metadata, ['image', 'digest']);
    $sbomPath = getNestedString($metadata, ['sbom', 'path']);

    if ($tag === '' || !preg_match('/^v[0-9A-Za-z][0-9A-Za-z.\-_]*$/', $tag)) {
        $failures[] = 'Release metadata must include a versioned git tag.';
    }

    if ($commit === '' || !preg_match('/^[a-f0-9]{40}$/', $commit)) {
        $failures[] = 'Release metadata must include the exact 40-character git commit SHA.';
    }

    if ($protected !== true) {
        $failures[] = 'Release metadata must record that the tag was protected when the digest was published.';
    }

    if ($imageRef === '' || preg_match('/@sha256:[a-f0-9]{64}$/', $imageRef) !== 1) {
        $failures[] = 'Release metadata must reference the image by an immutable sha256 digest.';
    }

    if ($digest === '' || preg_match('/^sha256:[a-f0-9]{64}$/', $digest) !== 1) {
        $failures[] = 'Release metadata must include a sha256 image digest.';
    }

    if ($imageRef !== '' && $digest !== '' && !str_ends_with($imageRef, '@' . $digest)) {
        $failures[] = 'Release metadata image.ref must end with the exact image.digest value.';
    }

    if ($sbomPath === '') {
        $failures[] = 'Release metadata must include the generated SBOM artifact path.';
    } elseif (!is_file($repoPath . DIRECTORY_SEPARATOR . $sbomPath)) {
        $failures[] = sprintf('Release metadata SBOM artifact is missing: %s', $sbomPath);
    }
}

function getNestedString(array $payload, array $path): string
{
    $cursor = $payload;
    foreach ($path as $segment) {
        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
            return '';
        }
        $cursor = $cursor[$segment];
    }

    return is_string($cursor) ? trim($cursor) : '';
}

function isPendingValue(string $value): bool
{
    $normalized = strtolower(trim($value));
    return $normalized === ''
        || in_array($normalized, ['pending', 'tbd', 'todo', 'unknown', 'n/a'], true)
        || str_contains($normalized, 'pending');
}

/**
 * @param list<string> $command
 * @return array{exit:int,stdout:string,stderr:string}
 */
function runProcess(array $command, string $cwd): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        failFast('Unable to start release validation process.');
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

function failFast(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}
