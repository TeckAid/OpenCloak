# Task 2 Report: Secure request context, canonical hosts, and strict input validation

Date: 2026-09-04
Baseline commit: `6edcda4`

## Summary

Implemented a shared request-normalization path for host, HTTPS, and client-IP resolution; enforced canonical/system host allowlisting plus active custom-domain checks; rejected malformed scalar inputs with 400; tightened offer URL validation; and updated the public/API callers plus tests to use the normalized request context.

## Files changed

- `config.php`
- `config.local.example.php`
- `includes/security.php`
- `includes/bootstrap.php`
- `includes/bot_detector.php`
- `index.php`
- `api/index.php`
- `tests/Unit/SecurityTest.php`
- `tests/Integration/HttpTest.php`

## Key implementation notes

- Added `APP_BASE_URL` and `SYSTEM_HOSTS` configuration defaults so `app_base_url()` no longer derives from arbitrary `Host` input.
- Added host normalization, trusted-proxy CIDR matching, request-context caching, scalar extraction helpers, and structured 400/421 rejection helpers in `includes/security.php`.
- Updated bootstrap to reject unknown hosts after DB initialization and to map request-validation failures to 400 responses instead of 500s.
- Updated `BotDetector`, `index.php`, and `/api/verify` to use the normalized client IP and strict scalar/query accessors.
- Tightened API create/update handling so malformed `offer_url` and scalar body fields fail closed.
- Added unit coverage for base URL, trusted proxy IP resolution, forwarded proto trust, host normalization, and offer URL validation.
- Added HTTP coverage for malformed query arrays, unknown host 421 behavior, inactive custom-domain rejection, and malformed offer URL API rejection.

## Commands and outputs

### Focused red run

Command:

```bash
php -r 'require "tests/bootstrap.php"; require "tests/Unit/SecurityTest.php"; require "tests/Integration/HttpTest.php"; foreach ([new SecurityTest(), new HttpTest()] as $case) { foreach ($case->run() as $result) { $line = get_class($case) . "::" . $result["name"] . " " . ($result["passed"] ? "PASS" : "FAIL"); if (!$result["passed"]) { $line .= " - " . $result["message"]; } echo $line, PHP_EOL; } }'
```

Expected contract failures observed before implementation:

- `SecurityTest::test_app_base_url_uses_explicit_configuration` failed: host header still influenced base URL.
- `SecurityTest::test_app_client_ip_uses_rightmost_untrusted_ip_from_trusted_chain` failed: app returned proxy IP.
- `SecurityTest::test_app_is_https_rejects_untrusted_forwarded_proto` failed: untrusted forwarded proto was accepted.
- `SecurityTest::test_app_normalize_host_rejects_malformed_values` failed: helper missing.
- `SecurityTest::test_is_valid_offer_url_rejects_credentials_and_control_characters` failed: invalid URLs were accepted.
- `HttpTest::test_api_rejects_malformed_offer_url_host` failed with `201`.
- `HttpTest::test_inactive_custom_host_does_not_serve_system_link` failed with `200`.
- `HttpTest::test_malformed_fingerprint_query_is_400` failed with `500`.
- `HttpTest::test_malformed_utm_source_query_is_400` failed with `500`.
- `HttpTest::test_unknown_host_is_rejected_with_421` failed with `404`.

### Focused green run

Same command as above.

Result: all `SecurityTest` and `HttpTest` cases passed.

### Full lint

Command:

```bash
rg --files -g '*.php' | xargs -n1 php -l
```

Result: no syntax errors detected in all PHP files under the repo, including the modified runtime and test files.

### Full test suite

Command:

```bash
php tests/run.php
```

Result:

- `CredentialsTest::test_fresh_database_is_isolated` PASS
- `HttpTest` 8/8 PASS
- `RulesTest` 2/2 PASS
- `SecurityTest` 6/6 PASS

## Concerns

- The new host gate defaults the system host set from `APP_BASE_URL`, so any deployment or local setup using additional intentional hostnames now needs those names listed explicitly in `SYSTEM_HOSTS`.

## Fix round 1

### Findings addressed

- `POST /api/domains` now routes `domain` through `app_array_get_scalar(...)` and returns a controlled `400` for arrays/non-scalars.
- `POST /api/campaigns/{id}/clone` now routes both form and JSON `name` inputs through `app_array_get_scalar(...)` and returns a controlled `400` for arrays/non-scalars.
- `app_base_url()` now preserves bracketed IPv6 hosts when rebuilding from `APP_BASE_URL`, so `http://[::1]:8080` remains valid.

### Added focused tests

- `SecurityTest::test_app_base_url_preserves_bracketed_ipv6_hosts`
- `HttpTest::test_api_rejects_array_clone_name`
- `HttpTest::test_api_rejects_array_domain_input`

### Focused red run

Command:

```bash
php -r 'require "tests/bootstrap.php"; require "tests/Unit/SecurityTest.php"; require "tests/Integration/HttpTest.php"; foreach ([new SecurityTest(), new HttpTest()] as $case) { foreach ($case->run() as $result) { $line = get_class($case) . "::" . $result["name"] . " " . ($result["passed"] ? "PASS" : "FAIL"); if (!$result["passed"]) { $line .= " - " . $result["message"]; } echo $line, PHP_EOL; } }'
```

Observed failures before the fix:

- `SecurityTest::test_app_base_url_preserves_bracketed_ipv6_hosts` failed: expected `http://[::1]:8080`, got `http://::1:8080`
- `HttpTest::test_api_rejects_array_clone_name` failed with `500`
- `HttpTest::test_api_rejects_array_domain_input` failed with `500`

### Focused green run

Same command as above.

Result: all `SecurityTest` and `HttpTest` cases passed, including the three new regressions.

### Full lint

Command:

```bash
rg --files -g '*.php' | xargs -n1 php -l
```

Result: no syntax errors detected in all PHP files.

### Full test suite

Command:

```bash
php tests/run.php
```

Result:

- `CredentialsTest` 1/1 PASS
- `HttpTest` 10/10 PASS
- `RulesTest` 2/2 PASS
- `SecurityTest` 7/7 PASS
