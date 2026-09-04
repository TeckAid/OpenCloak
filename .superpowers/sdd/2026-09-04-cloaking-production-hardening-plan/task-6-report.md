# Task 6 Report

Status: complete

Implemented:
- Replaced request-time schema creation/mutation with numbered migrations in `migrations/001_initial_schema.sql`, `migrations/002_current_columns.sql`, and `migrations/003_client_credentials.sql`.
- Added shared migration/rollback logic in `includes/database.php` plus CLI entrypoints `bin/migrate.php` and `bin/rollback.php`.
- Kept runtime bootstrap on verify-only schema checks via `initDatabase()` while preserving installer/test setup through explicit migration execution.
- Added migration coverage for fresh databases, no-ledger legacy upgrades, idempotence, serialized runners, rollback policy, delay-start uniqueness, ownership FKs, and transactional rollback behavior.
- Updated isolated HTTP fixtures to copy the new `migrations/` directory so installer/runtime test apps can execute the same migration assets as the repository checkout.

Verification:
- `php tests/run.php`
- `rg --files -g '*.php' | xargs -n 1 php -l`

Results:
- Full PHP suite passed, including the new `MigrationTest` cases.
- Repo-wide PHP syntax check passed with no syntax errors.

Concerns:
- `002_current_columns.sql` now enforces composite ownership foreign keys on `links` and unique delay-start scope/IP indexes. Legacy databases with orphaned cross-tenant link references or duplicate `delay_ips` rows will fail migration 002 until those rows are repaired, by design.
- Only migration `003_client_credentials` has a tested down path. Rolling back below version 2 intentionally returns a restore-required error and expects an operator backup restore.

## Fix Round 1 - 2026-09-04

Review findings addressed:
- Migration 003 now upgrades a pre-existing legacy `client_credentials` table by validating campaign/user ownership, rebuilding the table transactionally into the composite `(campaign_id, user_id) -> campaigns(id, user_id)` shape, copying rows, and restoring indexes.
- `verifyDatabaseSchema()` now rejects an incorrect `client_credentials` foreign-key shape even if `schema_migrations` claims version 3 is applied.
- `tests/Unit/MigrationTest.php` now seeds a production-shaped old `client_credentials` table in the legacy no-ledger fixture and asserts both data preservation and the upgraded composite ownership constraint.

Red reproduction:
- Command: `php tests/run.php 2>&1 | rg 'MigrationTest::'`
- Output:
  - `MigrationTest::test_legacy_schema_without_ledger_is_upgraded_with_data_preserved FAIL ... Expected true`
  - `MigrationTest::test_verify_database_schema_rejects_legacy_client_credentials_constraint_shape_even_with_ledger FAIL ... Expected RuntimeException to be thrown`

Fix verification:
- Command: `php tests/run.php 2>&1 | rg 'MigrationTest::'`
- Output: all 9 `MigrationTest` cases passed, including `test_legacy_schema_without_ledger_is_upgraded_with_data_preserved` and `test_verify_database_schema_rejects_legacy_client_credentials_constraint_shape_even_with_ledger`.
- Command: `php tests/run.php`
- Output: full PHP suite passed.
- Command: `rg --files -g '*.php' | xargs -n 1 php -l`
- Output: repo-wide PHP syntax check passed with no syntax errors.

Updated result:
- 70 PHP tests passed after the fix round.
