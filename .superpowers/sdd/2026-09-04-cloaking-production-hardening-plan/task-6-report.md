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
