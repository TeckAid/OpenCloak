# Task 8 Report

## Status

Implemented the recovery hardening scope in this checkout:

- `ops/backup_sqlite.sh`
- `ops/restore_rehearsal.sh`
- `ops/RELEASE.md`
- `ops/Caddyfile.example`
- `ops/config.local.php.example`
- `README.md`
- `tests/Integration/RecoveryTest.php`

## Test Evidence

- `php tests/run.php` passed on September 4, 2026.
- Recovery-specific coverage now verifies:
  - backup artifact creation
  - exact app-key preservation
  - checksum enforcement
  - corrupt-backup rejection after checksum refresh
  - isolated restore rehearsal smoke coverage without mutating the source runtime

## Rehearsal Evidence

Synthetic rehearsal only; no live state was touched.

- Rehearsal root: `ops/rehearsals/20260904T201950Z-v2`
- Backup command log: `ops/rehearsals/20260904T201950Z-v2/backup.stdout.log`
- Restore command log: `ops/rehearsals/20260904T201950Z-v2/restore.stdout.log`
- Summary: `ops/rehearsals/20260904T201950Z-v2/restore-evidence/rehearsal-summary.json`
- Smoke results: `ops/rehearsals/20260904T201950Z-v2/restore-evidence/smoke-results.json`

Observed results:

- integrity check: `ok`
- migration exit: `0`
- login path: `200`
- unauthenticated API path: `401`
- active link path: `302` to `https://offers.example/promo`
- achieved RPO: `0` seconds
- achieved RTO: `0.499` seconds

## Concerns

- The rehearsal validated the scripts and app flow against a synthetic SQLite runtime, not a live Docker or Hetzner deployment.
- `docker-compose.yml` was intentionally not changed here, so the new docs/examples describe the release procedure without altering the current container topology.

## Fix Round 1

- Updated `ops/config.local.php.example` so `TRUSTED_PROXIES` matches the deterministic Caddy edge address `172.23.0.2/32`, and added a unit assertion for that contract.
- Tightened `ops/backup_sqlite.sh` to require a readable `--config` and to reject deployment configs whose declared runtime paths do not match the requested `--db` and `--app-key`.
- Added recovery regressions for omitted config and mismatched config.
- Sanitized committed rehearsal material so the repo keeps evidence only, not reusable backup state or credentials.

### Fix Round 1 Verification

- Focused recovery/security checks passed on September 4, 2026.
- `bash -n ops/backup_sqlite.sh ops/restore_rehearsal.sh` passed.
- PHP lint passed across all tracked `.php` files.
- `php tests/run.php` passed after the fix-round changes.

### Evidence-Only Rehearsal Storage

- Removed committed `app.key`, SQLite backup content, copied runtime config, and full backup metadata from `ops/rehearsals/20260904T201950Z-v2`.
- Retained only checksum evidence, summary data, redacted smoke results, and non-sensitive command logs.
