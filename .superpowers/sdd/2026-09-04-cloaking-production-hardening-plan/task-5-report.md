# Task 5 Report: Scoped Client Credentials and Direct/Client Parity

Date: 2026-09-04
Baseline: `8897e70`

## Scope completed

- Added a `client_credentials` table plus issuance, rotation, verification, expiry, revocation, and signed visitor-token helpers in `includes/database.php`.
- Split API authentication in `api/index.php` so `/api/verify` accepts only scoped client credentials, verifies the credential campaign match, rejects management resources when a client credential is used, and returns a signed campaign-scoped visitor cookie.
- Updated `admin/client.php` to rotate a campaign-scoped verify credential whenever a client export is generated, and to stop embedding the tenant admin API key in generated output.
- Reworked `includes/client_template.php.txt` to use the scoped credential, fail closed on invalid verify responses, mirror direct-mode iframe/meta/header redirect behavior, persist only signed visitor cookies, and reject local money-page traversal targets.
- Hardened the shared tracker token generation in `assets/js/tracker.js` to prefer cryptographic randomness when available.
- Updated `admin/dashboard.php` owner scoping to include campaign-only `/api/verify` traffic where `hit_log.link_id` is `NULL`.
- Updated `index.php` so direct mode also treats persistent visitor cookies as signed, scope-bound state rather than trusting cookie presence alone.

## Tests added

### `tests/Unit/CredentialsTest.php`

- `test_issue_client_credential_creates_scoped_verify_only_row`
- `test_issue_client_credential_rotates_prior_campaign_token`
- `test_client_credentials_reject_revoked_expired_and_cross_campaign_use`
- `test_signed_visitor_tokens_are_scope_bound_and_tamper_evident`

### `tests/Integration/HttpTest.php`

- `test_generated_client_does_not_embed_admin_api_key`
- `test_client_credentials_only_verify_assigned_campaign_and_are_forbidden_for_management`
- `test_revoked_and_expired_client_credentials_are_rejected`
- `test_generated_client_matches_direct_redirect_output`
- `test_generated_client_matches_direct_iframe_output`
- `test_generated_client_matches_direct_meta_refresh_output`
- `test_generated_client_matches_direct_deny_output`
- `test_dashboard_includes_campaign_only_verify_hits`
- `test_generated_client_fails_closed_on_invalid_verify_response`
- `test_generated_client_blocks_local_money_page_path_traversal`

## Verification evidence

Focused credential/client suite:

```text
php -r 'require "tests/bootstrap.php"; require "tests/Unit/CredentialsTest.php"; require "tests/Integration/HttpTest.php"; ...'
```

All targeted Task 5 tests passed, including scoped credential issuance, revoke/expiry, admin-key removal, direct/client parity, campaign-only dashboard hits, invalid verify fail-closed behavior, and traversal blocking.

Full suite:

```text
php tests/run.php
```

Result: all tests passed.

Lint:

```text
php -l includes/database.php
php -l api/index.php
php -l admin/client.php
php -l includes/client_template.php.txt
php -l assets/js/tracker.js
php -l admin/dashboard.php
php -l index.php
```

Result: no syntax errors.

## Notes / residual concerns

- Generating a new client export intentionally revokes the previously active credential for that campaign. If an operator refreshes the export page, older exported clients stop verifying until the new artifact is redeployed.
- The new `client_credentials` table is still created through the current request-time schema path because versioned migrations are Task 6 scope. Task 6 should extract this table into `migrations/003_client_credentials.sql`.

## Follow-up fix: untrusted forwarded proto in client artifact

Date: 2026-09-04
Base commit: `d69e8b5`

- Root cause: `includes/client_template.php.txt` trusted `X-Forwarded-Proto` even though the standalone generated client has no trusted-proxy allowlist, so any caller could make the artifact set a `Secure` visitor cookie over plain HTTP.
- Fix: `cloak_is_https()` now relies only on direct PHP HTTPS state and ignores forwarded headers in the generated artifact.
- Regression test added: `HttpTest::test_generated_client_ignores_untrusted_forwarded_proto_for_secure_cookie`

Verification:

```text
php -l includes/client_template.php.txt
php -l tests/Integration/HttpTest.php
php -r 'require "tests/bootstrap.php"; require "tests/Integration/HttpTest.php"; ...test_generated_client_ignores_untrusted_forwarded_proto_for_secure_cookie...'
php tests/run.php
```

Observed results:

- The new regression test failed before the fix with `Expected false`.
- After the fix, the regression test passed.
- The full suite passed after the fix.
