# Task 3 Report: Remove default credentials and harden authentication/CSRF

Date: 2026-09-04
Baseline commit: `ba2899b`

## Summary

Removed web-time default user creation, made `install.php` the only first-admin provisioning path, serialized the database-backed rate limiter with a write transaction, enforced both IP and account login keys, and added no-store plus explicit session-cookie expiry coverage for sensitive admin/API flows.

## Files changed

- `includes/database.php`
- `includes/security.php`
- `includes/bootstrap.php`
- `includes/auth.php`
- `admin/login.php`
- `admin/settings.php`
- `admin/dashboard.php`
- `install.php`
- `tests/Unit/SecurityTest.php`
- `tests/Integration/HttpTest.php`

## Implementation notes

- `initDatabase()` now creates schema only; it never inserts a default administrator.
- `install.php` now acquires an immediate SQLite transaction, refuses to run after any admin already exists, generates a password only for a real first-install path, and inserts exactly one admin with `must_change_password = 0`.
- `rate_limit()` now runs under `BEGIN IMMEDIATE`, reads the current window while holding the write lock, and upserts the final count/reset atomically before commit.
- `admin/login.php` now consumes both `login:ip:<normalized-ip>` and `login:account:<sha256(lower(username))>` limiter keys.
- `boot_app()` now marks admin and API responses `Cache-Control: no-store`; CSRF 403s also send explicit no-store HTML responses.
- Logout now expires the session cookie explicitly before destroying the session, and the forced-password warning text no longer references default credentials.

## Verification

### Focused red run

Command:

```bash
php -r 'require "tests/bootstrap.php"; require "tests/Unit/SecurityTest.php"; require "tests/Integration/HttpTest.php"; foreach ([new SecurityTest(), new HttpTest()] as $case) { foreach ($case->run() as $result) { $line = get_class($case) . "::" . $result["name"] . " " . ($result["passed"] ? "PASS" : "FAIL"); if (!$result["passed"]) { $line .= " - " . $result["message"]; } echo $line, PHP_EOL; } }'
```

Observed contract failures before implementation:

- `SecurityTest::test_rate_limit_allows_only_one_parallel_attempt_for_single_key` failed with `Round 1 admitted 6 parallel attempts for a single key`.
- `HttpTest::test_api_without_bearer_is_401` failed the new no-store assertion.
- `HttpTest::test_default_admin_credentials_cannot_authenticate_after_custom_install` failed with `Expected 200, got 302`, proving `admin/admin` still authenticated.
- `HttpTest::test_fresh_admin_request_does_not_create_any_users` failed with `Expected 0, got 1`, proving request-time user seeding.
- `HttpTest::test_install_creates_first_administrator_once_without_hidden_default_account` failed with `Installer should create exactly one administrator.`
- `HttpTest::test_login_rate_limit_blocks_second_attempt_for_same_account_from_different_ip` failed the account-limit assertion.
- `HttpTest::test_logout_expires_cookie_and_redirects_to_login` failed with `Logout should expire the session cookie.`

### Focused green run

Same command as above.

Result: all `SecurityTest` and `HttpTest` cases passed, including the new installer, CSRF, no-store, cookie, and rate-limit regressions.

### Full lint

Command:

```bash
rg --files -g '*.php' | xargs -n1 php -l
```

Result: every tracked PHP file reported `No syntax errors detected`.

### Full test suite

Command:

```bash
php tests/run.php
```

Result:

- `CredentialsTest` 1/1 PASS
- `HttpTest` 18/18 PASS
- `RulesTest` 2/2 PASS
- `SecurityTest` 8/8 PASS

## Concerns

- The account-based limiter intentionally counts attempts by lowercased username string before credential verification. That closes the cross-IP brute-force hole, but it also means repeated guesses against a mistyped username can temporarily lock that username string for the configured window.

## Fix round 1

Addressed the review findings against the first Task 3 commit.

### What changed

- `initDatabase()` is now verify-only and throws when the schema has not been set up; the new `setupDatabase()` path owns schema creation and legacy migration work for the installer and test fixtures.
- `install.php` now loads config/database directly and uses `setupDatabase()` instead of request bootstrap, so schema setup stays off the normal HTTP/API path.
- The default mutable runtime state now lives under `APP_RUNTIME_DIR` with `DB_PATH`, `APP_KEY`, and `LOG_PATH` defaulting to `../cloaking-runtime/...`, outside the deployed app tree.
- `config.local.example.php` now documents the default runtime directory and the expectation that the PHP user can write to it.
- HTTP fixtures and isolated database fixtures now pre-initialize schema explicitly through `setupDatabase()` and run requests against the real runtime database code with no request-time shim.
- Added regressions proving an uninitialized admin request returns `500` without creating schema, initialized requests keep the schema stable, the default DB path sits outside the app tree, and `/install.php` refuses direct HTTP execution.

### Focused red run

Command:

```bash
php -r 'require "tests/bootstrap.php"; require "tests/Unit/SecurityTest.php"; require "tests/Integration/HttpTest.php"; foreach ([new SecurityTest(), new HttpTest()] as $case) { foreach ($case->run() as $result) { $line = get_class($case) . "::" . $result["name"] . " " . ($result["passed"] ? "PASS" : "FAIL"); if (!$result["passed"]) { $line .= " - " . $result["message"]; } echo $line, PHP_EOL; } }'
```

Observed failures before the fix:

- `SecurityTest::test_default_db_path_is_outside_application_tree` failed with `Expected false`.
- `HttpTest::test_uninitialized_admin_request_does_not_create_schema` failed with `Expected 500, got 200`.

Notes from the same red pass:

- `HttpTest::test_install_endpoint_refuses_http_execution` already passed, which confirmed the HTTP refusal behavior and locked it in with a direct regression.
- `HttpTest::test_initialized_admin_request_does_not_mutate_schema` already passed; it remains as a guard that initialized requests stay read-only with respect to schema.

### Focused green run

Same command as above.

Result: all focused `SecurityTest` and `HttpTest` cases passed, including the new request-bootstrap, default-path, and HTTP-installer regressions.

### Full lint

Command:

```bash
rg --files -g '*.php' | xargs -n1 php -l
```

Result: every tracked PHP file reported `No syntax errors detected`.

### Full test suite

Command:

```bash
php tests/run.php
```

Result:

- `CredentialsTest` 1/1 PASS
- `HttpTest` 22/22 PASS
- `RulesTest` 2/2 PASS
- `SecurityTest` 9/9 PASS

### Concerns

- `setupDatabase()` is intentionally a transitional setup entrypoint for the installer and fixtures until Task 6 introduces the explicit migration runner. The runtime path is now fail-closed on uninitialized databases.
