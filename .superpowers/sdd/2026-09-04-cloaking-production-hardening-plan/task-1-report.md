# Task 1 Implementation Report

Date: 2026-09-04

## Summary

Implemented the dependency-free PHP test harness, isolated fixtures, baseline contract tests, and CI workflow requested by Task 1.

The suite now runs with `php tests/run.php`, prints one line per test, and exits nonzero on failure. The fixtures use temporary directories and do not read or write the workspace `data/` or `logs/` paths.

## Files Changed

- Added `tests/TestCase.php`
- Added `tests/bootstrap.php`
- Added `tests/run.php`
- Added `tests/fixtures/DatabaseFixture.php`
- Added `tests/fixtures/HttpFixture.php`
- Added `tests/Unit/SecurityTest.php`
- Added `tests/Unit/RulesTest.php`
- Added `tests/Unit/CredentialsTest.php`
- Added `tests/Integration/HttpTest.php`
- Added `.github/workflows/ci.yml`
- Updated `.gitignore`

## Verification

### Red check

Command:

```bash
php tests/run.php
```

Output:

```text
Could not open input file: tests/run.php
```

### Green check

Command:

```bash
php tests/run.php
```

Output:

```text
CredentialsTest::test_fresh_database_is_isolated PASS (457.79ms)
HttpTest::test_api_without_bearer_is_401 PASS (302.62ms)
HttpTest::test_data_path_is_not_downloadable PASS (295.53ms)
HttpTest::test_unknown_slug_is_404 PASS (301.76ms)
RulesTest::test_parse_os_min_versions_parses_entries PASS (0.04ms)
RulesTest::test_wildcard_match_list_supports_wildcards PASS (0.02ms)
SecurityTest::test_harness_reports_failure PASS (0.02ms)
```

### Lint checks

Command:

```bash
while IFS= read -r file; do php -l "$file"; done < <(git ls-files '*.php')
```

Output: all tracked PHP files reported `No syntax errors detected`.

Command:

```bash
while IFS= read -r file; do php -l "$file"; done < <(rg --files tests | rg '\.php$')
```

Output: all new test harness PHP files reported `No syntax errors detected`.

## Notes

- `DatabaseFixture::fresh()` initializes an isolated SQLite database in a subprocess so each call gets a fresh temp file without reusing the app’s cached DB handle.
- `HttpFixture::request()` boots the app in a temporary document root and points config to temp runtime state, which keeps the workspace `data/` and `logs/` paths untouched.

## Concerns

- None beyond the deliberate use of temp directories and short-lived PHP subprocesses for isolation.
