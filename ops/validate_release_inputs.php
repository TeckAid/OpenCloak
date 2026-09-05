<?php

declare(strict_types=1);

/**
 * Validates release prerequisites that engineering can prove mechanically:
 * a clean git checkout, an injected legal approval attestation bound to the
 * legal review artifact digest, real git tag/commit provenance, and
 * digest-backed published metadata when validating post-push artifacts.
 */

$options = parseArguments($argv);
$failures = [];

$phase = strtolower($options['phase'] ?? 'published');
if (!in_array($phase, ['prepublish', 'published'], true)) {
    failFast(sprintf('Unsupported validation phase: %s', $phase));
}

$repoPath = normalizePath($options['repo'] ?? getcwd() ?: '.');
$legalReviewPath = normalizePath($options['legal-review'] ?? $repoPath . DIRECTORY_SEPARATOR . 'LEGAL_PLATFORM_REVIEW.md');
$metadataPath = normalizePath($options['metadata'] ?? $repoPath . DIRECTORY_SEPARATOR . 'release-artifacts' . DIRECTORY_SEPARATOR . 'release-metadata.json');
$attestationSource = $options['legal-approval-attestation'] ?? getenv('LEGAL_APPROVAL_ATTESTATION') ?: '';

if (!is_dir($repoPath)) {
    failFast(sprintf('Repository path does not exist: %s', $repoPath));
}

validateCleanCheckout($repoPath, $failures);

$review = parseLegalReview($legalReviewPath, $failures);
$attestation = parseLegalAttestation($attestationSource, $failures);
$metadata = null;

if ($phase === 'published') {
    $metadata = parseMetadata($metadataPath, $failures);
}

if ($review !== null) {
    validateLegalReview($review, $failures);
}

if ($review !== null && $attestation !== null) {
    validateLegalAttestation($review, $legalReviewPath, $attestation, $failures);
}

$provenance = resolveGitProvenance($phase, $options, $metadata, $failures);
if ($provenance !== null) {
    validateGitProvenance($repoPath, $provenance, $failures);
}

if ($phase === 'published' && $metadata !== null && $provenance !== null) {
    validateMetadata($metadata, $metadataPath, $failures);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, sprintf("PASS: %s release inputs are clean, attested, and provenance-verified.\n", $phase));
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
 */
function validateCleanCheckout(string $repoPath, array &$failures): void
{
    $gitStatus = runProcess(['git', 'status', '--porcelain', '--untracked-files=all'], $repoPath);
    if ($gitStatus['exit'] !== 0) {
        $failures[] = "Unable to read git status for release validation.\n" . trim($gitStatus['stderr'] . $gitStatus['stdout']);
        return;
    }

    if (trim($gitStatus['stdout']) !== '') {
        $failures[] = 'Release inputs must come from a clean checkout with no tracked or untracked changes.';
    }
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
 * @return array<string, string>|null
 */
function parseLegalAttestation(string $source, array &$failures): ?array
{
    if (trim($source) === '') {
        $failures[] = 'LEGAL_APPROVAL_ATTESTATION is required from a protected external environment before release.';
        return null;
    }

    $decoded = json_decode($source, true);
    if (!is_array($decoded)) {
        $failures[] = 'LEGAL_APPROVAL_ATTESTATION is not valid JSON.';
        return null;
    }

    $attestation = [];
    foreach (['authorized_by', 'decision', 'review_sha256', 'issued_at'] as $field) {
        $value = $decoded[$field] ?? null;
        $attestation[$field] = is_string($value) ? trim($value) : '';
    }

    return $attestation;
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
 * @param array<string, string> $review
 * @param array<string, string> $attestation
 * @param array<int, string> $failures
 */
function validateLegalAttestation(array $review, string $legalReviewPath, array $attestation, array &$failures): void
{
    if (isPendingValue($attestation['authorized_by'] ?? '')) {
        $failures[] = 'LEGAL_APPROVAL_ATTESTATION must identify the external authorized approver or environment.';
    }

    if (strcasecmp($attestation['decision'] ?? '', 'approved') !== 0) {
        $failures[] = 'LEGAL_APPROVAL_ATTESTATION decision must be approved.';
    }

    if (preg_match('/^[a-f0-9]{64}$/', $attestation['review_sha256'] ?? '') !== 1) {
        $failures[] = 'LEGAL_APPROVAL_ATTESTATION must include the sha256 review artifact digest.';
    } else {
        $actualDigest = hash_file('sha256', $legalReviewPath);
        if (!is_string($actualDigest) || $actualDigest !== $attestation['review_sha256']) {
            $failures[] = 'LEGAL_APPROVAL_ATTESTATION review artifact digest does not match LEGAL_PLATFORM_REVIEW.md.';
        }
    }

    if (!isValidIsoTimestamp($attestation['issued_at'] ?? '')) {
        $failures[] = 'LEGAL_APPROVAL_ATTESTATION issued_at must use canonical UTC form YYYY-MM-DDTHH:MM:SSZ.';
    }

    if (($review['Decision'] ?? '') !== '' && strcasecmp($review['Decision'], $attestation['decision'] ?? '') !== 0) {
        $failures[] = 'LEGAL_APPROVAL_ATTESTATION decision does not match LEGAL_PLATFORM_REVIEW.md.';
    }
}

/**
 * @param array<string, string> $options
 * @param array<string, mixed>|null $metadata
 * @param array<int, string> $failures
 * @return array{tag:string,commit:string,protected:bool}|null
 */
function resolveGitProvenance(string $phase, array $options, ?array $metadata, array &$failures): ?array
{
    $tag = trim($options['git-tag'] ?? '');
    $commit = trim($options['git-commit'] ?? '');
    $protectedRaw = trim($options['git-ref-protected'] ?? '');

    if ($phase === 'published' && $metadata !== null) {
        $tag = $tag !== '' ? $tag : getNestedString($metadata, ['git', 'tag']);
        $commit = $commit !== '' ? $commit : getNestedString($metadata, ['git', 'commit']);
        if ($protectedRaw === '') {
            $protectedValue = $metadata['git']['protected'] ?? null;
            $protectedRaw = $protectedValue === true ? 'true' : ($protectedValue === false ? 'false' : '');
        }
    }

    if ($tag === '' || !preg_match('/^v[0-9A-Za-z][0-9A-Za-z.\-_]*$/', $tag)) {
        $failures[] = 'Release provenance must include a versioned git tag.';
    }

    if ($commit === '' || !preg_match('/^[a-f0-9]{40}$/', $commit)) {
        $failures[] = 'Release provenance must include the exact 40-character git commit SHA.';
    }

    if (!in_array($protectedRaw, ['true', 'false'], true)) {
        $failures[] = 'Release provenance must record whether the git ref was protected.';
    }

    if ($failures !== []) {
        return null;
    }

    return [
        'tag' => $tag,
        'commit' => $commit,
        'protected' => $protectedRaw === 'true',
    ];
}

/**
 * @param array{tag:string,commit:string,protected:bool} $provenance
 * @param array<int, string> $failures
 */
function validateGitProvenance(string $repoPath, array $provenance, array &$failures): void
{
    if ($provenance['protected'] !== true) {
        $failures[] = 'Release provenance must record a protected git ref for published releases.';
    }

    $headCommit = gitResolve($repoPath, 'HEAD', $failures, 'current HEAD revision');
    $claimedCommit = gitResolve($repoPath, $provenance['commit'] . '^{commit}', $failures, 'claimed release commit');
    $tagCommit = gitResolve($repoPath, 'refs/tags/' . $provenance['tag'] . '^{commit}', $failures, 'claimed release tag');

    if ($claimedCommit === null || $tagCommit === null || $headCommit === null) {
        return;
    }

    if ($headCommit !== $claimedCommit) {
        $failures[] = 'Claimed release commit does not match the current release revision at HEAD.';
    }

    if ($tagCommit !== $claimedCommit) {
        $failures[] = sprintf('Release tag %s does not point at the claimed release commit.', $provenance['tag']);
    }
}

/**
 * @param array<string, mixed> $metadata
 * @param array<int, string> $failures
 */
function validateMetadata(array $metadata, string $metadataPath, array &$failures): void
{
    $imageRef = getNestedString($metadata, ['image', 'ref']);
    $digest = getNestedString($metadata, ['image', 'digest']);
    $sbomPath = getNestedString($metadata, ['sbom', 'path']);
    $createdAt = getNestedString($metadata, ['created_at']);

    if (!isValidIsoTimestamp($createdAt)) {
        $failures[] = 'Release metadata created_at must use canonical UTC form YYYY-MM-DDTHH:MM:SSZ.';
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
        return;
    }

    $resolvedSbomPath = isAbsolutePath($sbomPath)
        ? $sbomPath
        : dirname($metadataPath) . DIRECTORY_SEPARATOR . $sbomPath;
    if (!is_file($resolvedSbomPath)) {
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

function isValidIsoTimestamp(string $value): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed instanceof DateTimeImmutable
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d\TH:i:s\Z') === $value;
}

function isAbsolutePath(string $path): bool
{
    return $path !== '' && $path[0] === DIRECTORY_SEPARATOR;
}

/**
 * @param array<int, string> $failures
 */
function gitResolve(string $repoPath, string $ref, array &$failures, string $label): ?string
{
    $result = runProcess(['git', 'rev-parse', '--verify', $ref], $repoPath);
    if ($result['exit'] !== 0) {
        $failures[] = sprintf('Unable to resolve %s: %s', $label, trim($result['stderr'] . $result['stdout']));
        return null;
    }

    return trim($result['stdout']);
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
