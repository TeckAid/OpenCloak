# Task 4 report — campaign/link persistence, URL actions, and tenant ownership

Date: 2026-09-04
Baseline: `9c481fa`

## Scope completed

Implemented the Task 4 hardening changes in:

- `admin/campaigns.php`
- `admin/links.php`
- `admin/domains.php`
- `api/index.php`
- `includes/rules.php`
- `includes/security.php`
- `index.php`
- `tests/Unit/RulesTest.php`
- `tests/Integration/HttpTest.php`

## What changed

### Shared parsing and persistence

- Added shared named-field parsers in `includes/rules.php`:
  - `parse_campaign_input()`
  - `parse_link_input()`
  - supporting typed scalar/flag/id helpers
- Removed duplicated positional campaign/link persistence logic from admin/API create and update flows.
- Added shared mutable-column lists so create/update/clone paths bind the same named values consistently.

### URL validation and delivery safety

- Enforced strict validation for:
  - primary offer URLs
  - offer pool URLs
  - route-target URLs
- Validation now happens before persistence and again before iframe/meta/header delivery.
- Invalid delivery targets fall back to the white page instead of emitting unsafe redirect/meta output.

### Checkbox and enum correctness

- Added explicit zero-default handling for checkbox-backed fields on create/update.
- Fixed edit rendering to reflect persisted `is_active` state.
- Standardized redirect enum handling, including `303` and `meta`, across admin, API, parsing, and public delivery.

### Ownership and delete safety

- Added user-ownership checks for `campaign_id` and `domain_id` relationships on create/update.
- Cross-tenant references are rejected instead of being silently accepted.
- Domain deletion now blocks while referenced by links.
- Campaign deletion now blocks while referenced by links.
- Deletes no longer silently republish or detach links in a way that changes behavior unexpectedly.

### Rule/runtime hardening

- Switched OS minimum-version comparison to `version_compare()` semantics.
- Preserved dotted OS versions during parsing.
- Reworked delay-start uniqueness logic to use:
  - one transaction
  - `< limit` semantics
  - unique scope/IP behavior for non-permanent rules

### API route handling

- Tightened malformed resource handling in `api/index.php`.
- Invalid resource ids / extra segments now return `404`.
- Wrong methods on supported resource shapes return `405`.

## Test coverage added

Added/expanded tests for:

- campaign create/update persistence across fields
- link create/update persistence across fields
- unchecked checkbox defaults
- invalid primary/pool/route URLs
- `303` redirect handling
- `meta` delivery fallback behavior
- version `14.10` vs `14.9`
- delay-start limit/uniqueness behavior
- cross-tenant campaign/domain references
- safe domain deletion
- malformed API routes and methods

## Verification evidence

Focused Task 4 tests:

- `php -r 'require_once "tests/bootstrap.php"; require_once "tests/Unit/RulesTest.php"; require_once "tests/Integration/HttpTest.php"; $classes=["RulesTest","HttpTest"]; $failed=0; foreach($classes as $class){ $case=new $class(); foreach($case->run() as $result){ $status=$result["passed"]?"PASS":"FAIL"; $line=sprintf("%s::%s %s (%.2fms)",$class,$result["name"],$status,$result["duration_ms"]); if(!$result["passed"]){ $line.=" - ".$result["message"]; $failed++; } echo $line.PHP_EOL; }} exit($failed>0?1:0);'`
  - Result: all `RulesTest` and `HttpTest` cases passed.

Full suite:

- `php /Users/nasir/Documents/GitHub/Cloaking/tests/run.php`
  - Result: full suite passed, including `CredentialsTest`, `HttpTest`, `RulesTest`, and `SecurityTest`.

Syntax/lint:

- `php -l /Users/nasir/Documents/GitHub/Cloaking/admin/campaigns.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/admin/domains.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/admin/links.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/api/index.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/includes/rules.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/includes/security.php`
- `php -l /Users/nasir/Documents/GitHub/Cloaking/index.php`
  - Result: no syntax errors in any modified PHP file.

## Remaining concern

- Delay-start uniqueness is now enforced transactionally within the current file scope, but this task did not add a database-level unique constraint because `includes/database.php` / schema changes were outside the scoped file list. The runtime behavior required by Task 4 is covered by tests and the transactional implementation.

## Round 1 follow-up — atomic safe deletes

Date: 2026-09-04

### Finding addressed

- `delete_campaign_safely()` and `delete_domain_safely()` previously performed a reference count followed by delete without a transaction.
- Under a concurrent uncommitted link write, that sequence could miss the in-flight reference and then raise a SQLite lock error instead of failing closed.

### Change made

- Wrapped the reference-check and delete path in `BEGIN IMMEDIATE` via a shared helper in `includes/rules.php`.
- If the write lock cannot be acquired or the delete path encounters a SQLite lock, the helpers now return a safe retry message and leave the campaign/domain untouched.
- Added focused regression coverage in `tests/Unit/RulesTest.php` using two PDO handles against the same SQLite database to hold an uncommitted referencing link write open during delete.

### Commands and output

Red regression before the fix:

```text
$ php -r 'require_once "tests/bootstrap.php"; require_once "tests/Unit/RulesTest.php"; $case=new RulesTest(); foreach($case->run() as $result){ $status=$result["passed"]?"PASS":"FAIL"; $line=sprintf("%s::%s %s (%.2fms)","RulesTest",$result["name"],$status,$result["duration_ms"]); if(!$result["passed"]){ $line.=" - ".$result["message"]; } echo $line.PHP_EOL; }'
RulesTest::test_delay_start_check_blocks_only_first_n_unique_ips_for_nonpermanent_rules PASS (53.62ms)
RulesTest::test_delete_campaign_safely_fails_closed_when_concurrent_reference_write_is_open FAIL (297.73ms) - SQLSTATE[HY000]: General error: 5 database is locked
RulesTest::test_delete_domain_safely_fails_closed_when_concurrent_reference_write_is_open FAIL (339.11ms) - SQLSTATE[HY000]: General error: 5 database is locked
RulesTest::test_parse_campaign_input_rejects_invalid_offer_pool_and_routes PASS (0.62ms)
RulesTest::test_parse_link_input_sets_unchecked_form_flags_to_zero_and_accepts_303_redirect PASS (0.02ms)
RulesTest::test_parse_os_min_versions_preserves_dotted_versions PASS (0.01ms)
RulesTest::test_version_at_least_uses_version_compare_semantics PASS (0.01ms)
RulesTest::test_wildcard_match_list_supports_wildcards PASS (0.01ms)
```

Focused regression after the fix:

```text
$ php -r 'require_once "tests/bootstrap.php"; require_once "tests/Unit/RulesTest.php"; $case=new RulesTest(); $failed=0; foreach($case->run() as $result){ $status=$result["passed"]?"PASS":"FAIL"; $line=sprintf("%s::%s %s (%.2fms)","RulesTest",$result["name"],$status,$result["duration_ms"]); if(!$result["passed"]){ $line.=" - ".$result["message"]; $failed++; } echo $line.PHP_EOL; } exit($failed>0?1:0);'
RulesTest::test_delay_start_check_blocks_only_first_n_unique_ips_for_nonpermanent_rules PASS (55.81ms)
RulesTest::test_delete_campaign_safely_fails_closed_when_concurrent_reference_write_is_open PASS (291.81ms)
RulesTest::test_delete_domain_safely_fails_closed_when_concurrent_reference_write_is_open PASS (296.82ms)
RulesTest::test_parse_campaign_input_rejects_invalid_offer_pool_and_routes PASS (0.81ms)
RulesTest::test_parse_link_input_sets_unchecked_form_flags_to_zero_and_accepts_303_redirect PASS (0.03ms)
RulesTest::test_parse_os_min_versions_preserves_dotted_versions PASS (0.02ms)
RulesTest::test_version_at_least_uses_version_compare_semantics PASS (0.01ms)
RulesTest::test_wildcard_match_list_supports_wildcards PASS (0.02ms)
```

Full suite after the fix:

```text
$ php /Users/nasir/Documents/GitHub/Cloaking/tests/run.php
CredentialsTest::test_fresh_database_is_isolated PASS (97.52ms)
HttpTest::test_admin_campaign_form_create_and_update_persist_all_fields PASS (503.35ms)
HttpTest::test_admin_domain_delete_is_blocked_while_links_still_reference_it PASS (512.77ms)
HttpTest::test_admin_link_form_create_and_update_persist_all_fields PASS (496.29ms)
HttpTest::test_admin_link_update_rejects_cross_tenant_campaign_and_domain_ids PASS (681.09ms)
HttpTest::test_admin_login_page_is_not_cacheable_and_sets_session_cookie_attributes PASS (113.72ms)
HttpTest::test_admin_login_rejects_post_without_csrf_token PASS (112.59ms)
HttpTest::test_api_rejects_array_clone_name PASS (297.33ms)
HttpTest::test_api_rejects_array_domain_input PASS (299.23ms)
HttpTest::test_api_rejects_malformed_offer_url_host PASS (396.97ms)
HttpTest::test_api_rejects_malformed_resource_paths_and_wrong_methods PASS (905.31ms)
HttpTest::test_api_without_bearer_is_401 PASS (159.17ms)
HttpTest::test_data_path_is_not_downloadable PASS (162.16ms)
HttpTest::test_default_admin_credentials_cannot_authenticate_after_custom_install PASS (299.77ms)
HttpTest::test_fresh_admin_request_does_not_create_any_users PASS (119.28ms)
HttpTest::test_https_admin_session_cookie_is_secure PASS (121.65ms)
HttpTest::test_inactive_custom_host_does_not_serve_system_link PASS (114.32ms)
HttpTest::test_initialized_admin_request_does_not_mutate_schema PASS (112.49ms)
HttpTest::test_install_creates_first_administrator_once_without_hidden_default_account PASS (464.23ms)
HttpTest::test_install_endpoint_refuses_http_execution PASS (58.76ms)
HttpTest::test_login_rate_limit_blocks_second_attempt_for_same_account_from_different_ip PASS (490.49ms)
HttpTest::test_logout_expires_cookie_and_redirects_to_login PASS (518.05ms)
HttpTest::test_malformed_fingerprint_query_is_400 PASS (115.81ms)
HttpTest::test_malformed_utm_source_query_is_400 PASS (115.57ms)
HttpTest::test_public_route_falls_back_to_white_page_when_meta_target_is_invalid PASS (115.70ms)
HttpTest::test_public_route_supports_303_redirects PASS (114.21ms)
HttpTest::test_uninitialized_admin_request_does_not_create_schema PASS (65.23ms)
HttpTest::test_unknown_host_is_rejected_with_421 PASS (159.05ms)
HttpTest::test_unknown_slug_is_404 PASS (158.50ms)
RulesTest::test_delay_start_check_blocks_only_first_n_unique_ips_for_nonpermanent_rules PASS (54.20ms)
RulesTest::test_delete_campaign_safely_fails_closed_when_concurrent_reference_write_is_open PASS (294.13ms)
RulesTest::test_delete_domain_safely_fails_closed_when_concurrent_reference_write_is_open PASS (301.58ms)
RulesTest::test_parse_campaign_input_rejects_invalid_offer_pool_and_routes PASS (0.24ms)
RulesTest::test_parse_link_input_sets_unchecked_form_flags_to_zero_and_accepts_303_redirect PASS (0.03ms)
RulesTest::test_parse_os_min_versions_preserves_dotted_versions PASS (0.01ms)
RulesTest::test_version_at_least_uses_version_compare_semantics PASS (0.05ms)
RulesTest::test_wildcard_match_list_supports_wildcards PASS (0.06ms)
SecurityTest::test_app_base_url_preserves_bracketed_ipv6_hosts PASS (48.88ms)
SecurityTest::test_app_base_url_uses_explicit_configuration PASS (52.33ms)
SecurityTest::test_app_client_ip_ignores_untrusted_forwarded_for PASS (43.84ms)
SecurityTest::test_app_client_ip_uses_rightmost_untrusted_ip_from_trusted_chain PASS (43.59ms)
SecurityTest::test_app_is_https_rejects_untrusted_forwarded_proto PASS (45.64ms)
SecurityTest::test_app_normalize_host_rejects_malformed_values PASS (44.04ms)
SecurityTest::test_default_db_path_is_outside_application_tree PASS (45.96ms)
SecurityTest::test_is_valid_offer_url_rejects_credentials_and_control_characters PASS (48.16ms)
SecurityTest::test_rate_limit_allows_only_one_parallel_attempt_for_single_key PASS (3473.01ms)
```
