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
