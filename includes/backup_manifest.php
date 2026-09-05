<?php
/**
 * Cryptographic and provenance validation shared by migration and restore
 * tooling. Backup manifests are authenticated by the deployment app key.
 */

/**
 * @return array<string, mixed>
 */
function backup_manifest_verify_bundle(string $manifestPath, string $appKeyPath): array
{
    if (!is_file($manifestPath) || basename($manifestPath) !== 'backup-metadata.json') {
        throw new RuntimeException('Verified backup manifest is missing or has an invalid filename.');
    }
    if (!is_file($appKeyPath)) {
        throw new RuntimeException('Verified backup manifest app key is missing.');
    }
    $key = trim((string) file_get_contents($appKeyPath));
    if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
        throw new RuntimeException('Verified backup manifest app key is invalid.');
    }

    $raw = file_get_contents($manifestPath);
    $metadata = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($metadata)) {
        throw new RuntimeException('Verified backup manifest is not valid JSON.');
    }
    $providedHmac = $metadata['manifest_hmac'] ?? null;
    if (!is_string($providedHmac) || preg_match('/^[a-f0-9]{64}$/', $providedHmac) !== 1) {
        throw new RuntimeException('Verified backup manifest authentication is missing.');
    }
    unset($metadata['manifest_hmac']);
    $unsigned = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($unsigned) || !hash_equals(hash_hmac('sha256', $unsigned, $key), $providedHmac)) {
        throw new RuntimeException('Verified backup manifest authentication failed.');
    }
    $metadata['manifest_hmac'] = $providedHmac;

    $root = dirname($manifestPath);
    $checksumPath = $root . DIRECTORY_SEPARATOR . 'SHA256SUMS';
    $lines = is_file($checksumPath) ? file($checksumPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
    if (!is_array($lines)) {
        throw new RuntimeException('Verified backup checksum manifest is missing.');
    }
    $checksums = [];
    foreach ($lines as $line) {
        if (preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9._-]+)$/', $line, $matches) !== 1) {
            throw new RuntimeException('Verified backup checksum manifest is malformed.');
        }
        $checksums[$matches[2]] = $matches[1];
    }
    foreach (['cloaking.sqlite', 'app.key', 'config.local.php', 'backup-metadata.json'] as $filename) {
        $path = $root . DIRECTORY_SEPARATOR . $filename;
        if (!isset($checksums[$filename]) || !is_file($path)) {
            throw new RuntimeException("Verified backup artifact is missing: {$filename}.");
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($checksums[$filename], $actual)) {
            throw new RuntimeException("Verified backup checksum mismatch for {$filename}.");
        }
    }

    foreach (['source_commit' => '/^[a-f0-9]{40}$/', 'image_digest' => '/^sha256:[a-f0-9]{64}$/'] as $field => $pattern) {
        $value = $metadata['provenance'][$field] ?? null;
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new RuntimeException("Verified backup provenance {$field} is invalid.");
        }
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($metadata['created_at_utc'] ?? '')) !== 1) {
        throw new RuntimeException('Verified backup manifest timestamp is not canonical UTC.');
    }

    foreach (['database' => 'cloaking.sqlite', 'app_key' => 'app.key', 'config' => 'config.local.php'] as $field => $filename) {
        $expected = $metadata['artifacts'][$field]['sha256'] ?? null;
        $actual = hash_file('sha256', $root . DIRECTORY_SEPARATOR . $filename);
        if (!is_string($expected) || !is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException("Verified backup artifact metadata mismatch for {$filename}.");
        }
    }

    try {
        $backupDb = new PDO('sqlite:' . $root . DIRECTORY_SEPARATOR . 'cloaking.sqlite');
        $backupDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($backupDb->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('Backup integrity check did not return ok.');
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Verified backup database integrity check failed: ' . $e->getMessage(), 0, $e);
    }

    return $metadata;
}

function backup_manifest_verify_for_migration(
    string $manifestPath,
    string $dbPath,
    string $appKeyPath,
    int $migrationTarget,
    int $maxAgeSeconds = 300
): void {
    $metadata = backup_manifest_verify_bundle($manifestPath, $appKeyPath);
    if (($metadata['migration_target'] ?? null) !== $migrationTarget) {
        throw new RuntimeException('Verified backup manifest targets a different migration version.');
    }
    $capturedAt = $metadata['captured_at_epoch'] ?? null;
    if (!is_int($capturedAt) || $maxAgeSeconds < 1 || $capturedAt > time() + 30 || time() - $capturedAt > $maxAgeSeconds) {
        throw new RuntimeException('Verified backup manifest is stale or has an invalid capture time.');
    }

    $canonical = static function (string $path): string {
        $resolved = realpath($path);
        return $resolved === false ? '' : $resolved;
    };
    if ($canonical((string) ($metadata['source']['db_path'] ?? '')) === ''
        || $canonical((string) ($metadata['source']['db_path'] ?? '')) !== $canonical($dbPath)) {
        throw new RuntimeException('Verified backup manifest is bound to a different database.');
    }
    if ($canonical((string) ($metadata['source']['app_key_path'] ?? '')) === ''
        || $canonical((string) ($metadata['source']['app_key_path'] ?? '')) !== $canonical($appKeyPath)) {
        throw new RuntimeException('Verified backup manifest is bound to a different app key.');
    }

    $sourceFiles = $metadata['source']['database_files'] ?? null;
    if (!is_array($sourceFiles) || !isset($sourceFiles['database'])) {
        throw new RuntimeException('Verified backup manifest lacks source database state.');
    }
    $expectedFiles = ['database' => $dbPath];
    if (is_file($dbPath . '-wal') && filesize($dbPath . '-wal') > 0) {
        $expectedFiles['wal'] = $dbPath . '-wal';
    }
    if (array_keys($sourceFiles) !== array_keys($expectedFiles)) {
        throw new RuntimeException('Verified backup manifest does not match the current SQLite file set.');
    }
    foreach ($expectedFiles as $name => $path) {
        $expectedHash = $sourceFiles[$name]['sha256'] ?? null;
        $actualHash = hash_file('sha256', $path);
        if (!is_string($expectedHash) || !is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException("Verified backup manifest does not match current {$name} state.");
        }
    }

    $expectedKeyHash = $metadata['source']['app_key_sha256'] ?? null;
    $actualKeyHash = hash_file('sha256', $appKeyPath);
    if (!is_string($expectedKeyHash) || !is_string($actualKeyHash) || !hash_equals($expectedKeyHash, $actualKeyHash)) {
        throw new RuntimeException('Verified backup manifest does not match the current app key.');
    }
}

/**
 * Authorize migration of an isolated database restored byte-for-byte from the
 * authenticated backup artifact. Unlike a live pre-migration gate, an old
 * restore does not need a wall-clock freshness limit: equality with the
 * checksummed backup artifact proves that the backup is the current state.
 */
function backup_manifest_verify_restored_copy_for_migration(
    string $manifestPath,
    string $dbPath,
    string $appKeyPath,
    int $migrationTarget
): void {
    $metadata = backup_manifest_verify_bundle($manifestPath, $appKeyPath);
    if (($metadata['migration_target'] ?? null) !== $migrationTarget) {
        throw new RuntimeException('Verified restored backup targets a different migration version.');
    }

    foreach (
        [
            'database' => [$dbPath, $metadata['artifacts']['database']['sha256'] ?? null],
            'app key' => [$appKeyPath, $metadata['artifacts']['app_key']['sha256'] ?? null],
        ] as $label => [$path, $expectedHash]
    ) {
        $actualHash = is_file($path) ? hash_file('sha256', $path) : false;
        if (!is_string($expectedHash) || !is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException("Verified restored {$label} is not an exact backup artifact copy.");
        }
    }
}
