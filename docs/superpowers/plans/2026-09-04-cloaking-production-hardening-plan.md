# Cloaking Production Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repair the Cloaking application for safe public deployment, prove every security and data contract with automated tests, and produce an immutable, recoverable release artifact.

**Architecture:** Keep the dependency-free PHP application, but centralize request context, validation, credentials, and migration execution. Run the PHP service privately behind an explicitly configured TLS edge, use scoped client credentials, and keep all mutable state in provisioned mounts. CI builds from a clean Git checkout and runs unit, HTTP, web-server, migration, and recovery checks.

**Tech Stack:** PHP 8.4 or newer supported branch, PDO SQLite, PHP CLI test harness, Apache and Nginx container fixtures, Docker Compose, Caddy edge fixture, GitHub Actions, SQLite online backup.

**Spec:** `docs/superpowers/specs/2026-09-04-cloaking-production-hardening-design.md`

## Global Constraints

- No usable default credential may be created by an HTTP request.
- All forwarded IP/scheme/host data is ignored unless the immediate peer is an explicitly configured trusted proxy and the host is allowlisted.
- Campaign and link writes must round-trip every field without positional drift; unchecked booleans must persist as zero.
- Generated clients may call only verify for their assigned campaign; administrator API keys must never be embedded in client artifacts.
- Runtime requests do not run schema DDL or migrations.
- Mutable data is outside the code image and writable only by the intended container UID/GID.
- Production images and releases are addressed by immutable digest/tag.
- Legal/platform approval is represented by an authorized artifact; engineering cannot self-approve it.

---

### Task 1: Establish a real repository and dependency-free test harness

**Files:**
- Create: `tests/TestCase.php`
- Create: `tests/bootstrap.php`
- Create: `tests/run.php`
- Create: `tests/fixtures/HttpFixture.php`
- Create: `tests/fixtures/DatabaseFixture.php`
- Create: `tests/Unit/SecurityTest.php`
- Create: `tests/Unit/RulesTest.php`
- Create: `tests/Unit/CredentialsTest.php`
- Create: `tests/Integration/HttpTest.php`
- Create: `.github/workflows/ci.yml`
- Modify: `.gitignore`

**Interfaces:**
- `TestCase::assertSame`, `assertTrue`, `assertFalse`, `assertThrows`, and `run` provide deterministic CLI assertions without Composer.
- `DatabaseFixture::fresh()` returns an isolated PDO SQLite database using the production schema path.
- `HttpFixture::request($method, $path, $headers, $body)` starts the app in a temporary document root and returns status, headers, and body.
- `php tests/run.php` exits nonzero on any failure and prints one result per test.

- [ ] **Step 1: Write failing tests for the harness and baseline contracts.** Include tests named `test_harness_reports_failure`, `test_unknown_slug_is_404`, `test_api_without_bearer_is_401`, and `test_data_path_is_not_downloadable`.
- [ ] **Step 2: Run `php tests/run.php`; verify the harness fails because the test runner and fixtures do not yet exist.**
- [ ] **Step 3: Implement the minimal isolated test runner and fixtures.** Use `mktemp` directories and never the working tree's `data/` or `logs/` paths.
- [ ] **Step 4: Run `php tests/run.php`; verify the baseline tests pass and the runner exits 0.**
- [ ] **Step 5: Initialize Git only after confirming no runtime data or secrets are present; commit the design, plan, and harness as the first reproducible baseline.**

---

### Task 2: Secure request context, canonical hosts, and strict input validation

**Files:**
- Modify: `config.php`
- Modify: `config.local.example.php`
- Modify: `includes/security.php`
- Modify: `includes/bootstrap.php`
- Modify: `includes/bot_detector.php`
- Modify: `index.php`
- Modify: `api/index.php`
- Test: `tests/Unit/SecurityTest.php`
- Test: `tests/Integration/HttpTest.php`

**Interfaces:**
- `app_base_url()` reads an explicit `APP_BASE_URL` constant and never constructs URLs from an arbitrary Host header.
- `app_is_https()` trusts forwarded scheme only when the peer is trusted.
- `app_normalize_host(string $host): ?string` validates one canonical hostname.
- `app_is_allowed_host(string $host): bool` accepts `SYSTEM_HOSTS` and active registered custom domains only.
- `app_client_ip()` returns the normalized IP selected by a CIDR-aware trusted-proxy resolver.
- `is_valid_offer_url()` rejects whitespace/control characters, malformed hosts, non-http(s) schemes, credentials where disallowed, and overlong values.
- Scalar query helpers reject arrays and excessive values with 400 rather than converting them to warning-producing strings.

- [ ] **Step 1: Add failing tests** for untrusted `X-Forwarded-For`, trusted CIDR right-to-left selection, untrusted `X-Forwarded-Proto`, Host header poisoning, unknown-host 421, malformed `?_fph[]=x`, malformed `?utm_source[]=x`, and malformed offer hosts.
- [ ] **Step 2: Run the focused security tests and verify each failure is caused by the missing contract.**
- [ ] **Step 3: Implement canonical configuration, CIDR matching, request-context caching, strict scalar extraction, and host rejection.** Use the resolved IP consistently in detection, login limits, delay-start, logs, and API fallback.
- [ ] **Step 4: Run focused tests and the full PHP lint/test command; verify all pass.**
- [ ] **Step 5: Add tests that system links are not served for arbitrary or inactive custom hosts.**

---

### Task 3: Remove default credentials and harden authentication/CSRF

**Files:**
- Modify: `includes/database.php`
- Modify: `includes/auth.php`
- Modify: `includes/bootstrap.php`
- Modify: `includes/security.php`
- Modify: `admin/login.php`
- Modify: `admin/settings.php`
- Modify: `admin/dashboard.php`
- Modify: `install.php`
- Test: `tests/Unit/SecurityTest.php`
- Test: `tests/Integration/HttpTest.php`

**Interfaces:**
- `initDatabase()` creates schema only and never inserts a user.
- `install.php` creates the first administrator exactly once, reads a generated password safely, and refuses to run over HTTP.
- `rate_limit()` performs an atomic upsert inside a transaction and applies both normalized-IP and account keys.
- Session cookies are HttpOnly, SameSite=Lax, Secure under HTTPS, and use the configured lifetime.
- Admin/API sensitive responses send `Cache-Control: no-store`.

- [ ] **Step 1: Write failing tests** proving a fresh HTTP request has no users, installer creates one administrator, default `admin/admin` cannot authenticate, CSRF-less POST returns 403, concurrent login attempts cannot bypass the limit, and logout expires the cookie.
- [ ] **Step 2: Run tests and confirm the current default-user and non-atomic behavior fails them.**
- [ ] **Step 3: Remove web-time default creation; implement installer-only provisioning, atomic limits, session lifetime, and response cache headers.**
- [ ] **Step 4: Run focused auth tests and the full suite; verify pass counts and clean output.**
- [ ] **Step 5: Add a test that installing a different username does not leave a hidden default account.**

---

### Task 4: Repair campaign/link persistence, URL actions, and tenant ownership

**Files:**
- Modify: `admin/campaigns.php`
- Modify: `admin/links.php`
- Modify: `admin/domains.php`
- Modify: `api/index.php`
- Modify: `includes/rules.php`
- Modify: `includes/security.php`
- Modify: `index.php`
- Test: `tests/Unit/RulesTest.php`
- Test: `tests/Integration/HttpTest.php`

**Interfaces:**
- Shared typed parsers map named request fields to named SQL columns; no positional campaign array is duplicated between create/update paths.
- `parse_campaign_input()` and `parse_link_input()` validate all primary/pool/route URLs before persistence and before iframe/meta/header delivery.
- Checkbox fields use explicit zero defaults and preserve checked/unchecked state on create and update.
- Relationship updates require user-owned campaign/domain rows; dangling references are rejected.
- Domain deletion blocks while referenced or requires explicit reassignment; it never silently republishes a link.
- Redirect handling supports the documented enum consistently in direct and client modes.
- OS versions use `version_compare()` semantics; delay-start uses `< limit`, a unique scope/IP key, and one transaction.

- [ ] **Step 1: Write failing persistence tests** for every campaign field, every checkbox state, link create/update, invalid primary/pool/route URLs, `303`/`meta`, version `14.10` versus `14.9`, delay-start limit, cross-tenant IDs, and domain deletion.
- [ ] **Step 2: Run these tests and capture the current positional corruption and checkbox failures.**
- [ ] **Step 3: Implement shared named-field parsing, strict URL validation, boolean handling, ownership checks, transactional deletes, redirect parity, version comparison, and delay-start uniqueness.**
- [ ] **Step 4: Run the focused suite and verify database rows exactly equal submitted values.**
- [ ] **Step 5: Add malformed API route tests (`/api/links/foo`, extra segments) and assert 404/405 rather than silently listing resources.**

---

### Task 5: Implement scoped client credentials and direct/client parity

**Files:**
- Modify: `includes/database.php`
- Modify: `api/index.php`
- Modify: `admin/client.php`
- Modify: `includes/client_template.php.txt`
- Modify: `assets/js/tracker.js`
- Modify: `admin/dashboard.php`
- Test: `tests/Unit/CredentialsTest.php`
- Test: `tests/Integration/HttpTest.php`

**Interfaces:**
- New `client_credentials` rows contain a hash, campaign ID, owner ID, status, created/expiry/revoked timestamps, and a verify-only scope.
- `/api/verify` authenticates a scoped credential and rejects all management resources with 403.
- Client generation creates or rotates one credential for the selected campaign; administrator API keys never appear in generated text.
- Direct and client decision renderers share the same normalized result and redirect rules.
- Persistent visitor tokens are signed, campaign-scoped, Secure/HttpOnly/SameSite, and never trusted solely because a cookie exists.

- [ ] **Step 1: Write failing tests** proving generated clients contain no admin API key, a client credential verifies only its campaign, revocation/expiry works, other campaigns/tenants fail, and direct/client outputs match for allow/deny/iframe/meta cases.
- [ ] **Step 2: Run the tests and confirm the current full-key and parity failures.**
- [ ] **Step 3: Implement scoped credential issuance/authentication, signed tokens, shared decision rendering, secure cookies, and dashboard queries that include standalone campaign hits.**
- [ ] **Step 4: Run the focused suite and a temporary HTTP client deployment; verify no secrets appear in generated artifacts or URLs.**
- [ ] **Step 5: Add tests for missing/invalid verification responses and local money-page handling without path traversal.**

---

### Task 6: Replace request-time schema changes with explicit versioned migrations

**Files:**
- Create: `migrations/001_initial_schema.sql`
- Create: `migrations/002_current_columns.sql`
- Create: `migrations/003_client_credentials.sql`
- Create: `bin/migrate.php`
- Create: `bin/rollback.php`
- Modify: `includes/database.php`
- Modify: `includes/bootstrap.php`
- Test: `tests/Unit/MigrationTest.php`
- Test: `tests/Integration/HttpTest.php`

**Interfaces:**
- `schema_migrations(version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)` records each migration.
- `bin/migrate.php` applies pending migrations once under `BEGIN IMMEDIATE`, validates preconditions, and exits nonzero on failure.
- `bin/rollback.php --to=<version>` performs only migrations with a tested down operation or exits with a clear restore-required message.
- `initDatabase()` opens/configures SQLite and verifies the expected schema version without running DDL.

- [ ] **Step 1: Write failing tests** for fresh migration, repeated migration idempotence, concurrent runner serialization, expected schema version, rollback of the client credential migration, and refusal to destructive-rollback an unimplemented down migration.
- [ ] **Step 2: Run migration tests and verify current request-time behavior violates them.**
- [ ] **Step 3: Extract the current schema into numbered migrations, add migration ledger/runner/rollback policy, and remove DDL from normal bootstrap.**
- [ ] **Step 4: Run migration tests against fresh and legacy SQLite fixtures; verify data preservation and clean rollback behavior.**
- [ ] **Step 5: Add transactional tests for hit logging/counters and domain/campaign delete/reassignment operations.**

---

### Task 7: Repair container packaging and Apache/Nginx security integration

**Files:**
- Modify: `.dockerignore`
- Modify: `Dockerfile`
- Modify: `docker-compose.yml`
- Modify: `.htaccess`
- Modify: `admin/.htaccess`
- Modify: `nginx.conf`
- Create: `tests/integration/apache/Dockerfile`
- Create: `tests/integration/nginx/Dockerfile`
- Create: `tests/integration/web_server_test.sh`
- Test: `.github/workflows/ci.yml`

**Interfaces:**
- Clean Docker context contains `docker/php.ini` and `install.php`, excludes `config.local.php`, runtime state, Git metadata, and test-only files as appropriate.
- PHP service exposes no host port; only the edge proxy publishes 80/443.
- Runtime directories are created/provisioned for UID/GID 33 and code remains non-writable by the web worker.
- Nginx and Apache execute PHP through FastCGI/mod_php and deny PHP source, SQLite DB/WAL/SHM, config, logs, installer, dev router, README, plan, review, and Docker files.
- Health checks use an installed binary and do not create application state.

- [ ] **Step 1: Write failing container/integration checks** that build from a clean context, request all sensitive paths through Apache and Nginx, and assert source/DB/config/internal docs are not returned.
- [ ] **Step 2: Run checks and confirm they fail because the current Docker context and Nginx `^~` locations are unsafe.**
- [ ] **Step 3: Implement corrected Docker context, permissions, private networking, exact FastCGI routing, deny rules, healthcheck, resource/log limits, and read-only config mount.**
- [ ] **Step 4: Run `docker build --pull`, both web-server integration suites, and `docker compose config --quiet` from a clean checkout.**
- [ ] **Step 5: Add a Caddy fixture with explicit host allowlisting, automatic HTTPS storage, and no unrestricted On-Demand TLS.**

---

### Task 8: Add backup/restore rehearsal and operational release policy

**Files:**
- Create: `ops/backup_sqlite.sh`
- Create: `ops/restore_rehearsal.sh`
- Create: `ops/RELEASE.md`
- Create: `ops/Caddyfile.example`
- Create: `ops/config.local.php.example`
- Modify: `README.md`
- Test: `tests/Integration/RecoveryTest.php`

**Interfaces:**
- `ops/backup_sqlite.sh` takes a WAL-safe online backup, copies the matching app key/config metadata, writes checksums, and exits nonzero on integrity failure.
- `ops/restore_rehearsal.sh` restores into an isolated temporary state, runs `PRAGMA integrity_check`, migrates, starts the app on a non-public port, and records status/RTO.
- `ops/RELEASE.md` defines pre-deploy backup, migration, smoke, rollback, monitoring, and RPO/RTO steps.
- README deployment instructions use Hetzner Firewall, private app networking, explicit TLS edge configuration, correct state ownership, and no public 8080.

- [ ] **Step 1: Write failing recovery tests** for backup creation, matching app-key preservation, checksum verification, corrupt-backup rejection, and isolated restore smoke.
- [ ] **Step 2: Run recovery tests and verify no recovery workflow exists yet.**
- [ ] **Step 3: Implement backup/restore scripts and release policy without deleting the live state during rehearsal.**
- [ ] **Step 4: Run one complete rehearsal, record the command output and achieved RPO/RTO under `ops/rehearsals/`, and verify the restored login/API/link paths.**
- [ ] **Step 5: Update README with the corrected Docker/Caddy/Hetzner procedure and explicit custom-domain certificate process.**

---

### Task 9: Add CI, artifact provenance, and release checks

**Files:**
- Modify: `.github/workflows/ci.yml`
- Create: `.github/dependabot.yml`
- Create: `SECURITY.md`
- Create: `LEGAL_PLATFORM_REVIEW.md`
- Modify: `README.md`

**Interfaces:**
- CI checks out a clean Git revision, runs PHP lint/tests, builds the image without cached workspace state, runs Apache/Nginx/Caddy integration tests, scans the image, and publishes a digest only on a protected release tag.
- `SECURITY.md` documents reporting and secret handling.
- `LEGAL_PLATFORM_REVIEW.md` is an approval record with named owner, scope, decision, evidence, and date; an empty/pending record fails the release gate.

- [ ] **Step 1: Write failing CI validation tests** that reject dirty/untracked release inputs, missing legal approval, mutable image tags, and missing digest metadata.
- [ ] **Step 2: Run the validator locally and verify it fails until CI/release metadata is present.**
- [ ] **Step 3: Implement GitHub Actions with pinned action versions, clean checkout, test/build/integration jobs, image digest output, and protected release conditions.**
- [ ] **Step 4: Run the workflow-equivalent commands locally and inspect every artifact and exit code.**
- [ ] **Step 5: Populate the legal/platform review template only with evidence supplied by an authorized reviewer; otherwise keep the release blocked.**

---

### Task 10: Release tag, digest deployment, and final verification

**Files:**
- Create: `RELEASE_NOTES.md`
- Modify: `ops/RELEASE.md`
- Create: `ops/smoke_test.sh`

**Interfaces:**
- `ops/smoke_test.sh` validates HTTPS redirect, Secure cookie, auth/CSRF, API 401, sensitive-file denial, host rejection, direct/client parity, campaign persistence, dashboard client hits, and restart persistence.
- Release notes contain the exact Git tag, source commit, image digest, migration version, backup checksum, restore rehearsal result, and approval artifact references.

- [ ] **Step 1: Run the complete clean-checkout test/build/integration/recovery suite and verify zero failures.**
- [ ] **Step 2: Run `ops/smoke_test.sh` against the staged deployment and verify all expected status codes and headers.**
- [ ] **Step 3: Create an annotated immutable Git tag only after the legal/platform artifact is present and all release gates pass.**
- [ ] **Step 4: Deploy the exact image digest, rerun smoke tests, and preserve the previous digest plus verified backup for rollback.**
- [ ] **Step 5: If any external approval or infrastructure check is absent, report the release as blocked rather than claiming deployment completion.**

## Plan self-review

- Critical findings are covered by Tasks 2–5 and 7: default credentials, Docker build, Nginx source disclosure, campaign corruption, full administrative client key, and TLS exposure.
- Important findings are covered by Tasks 2–10: proxy/Host handling, permissions, secret persistence, URL validation, checkbox state, ownership, domain deletion, dashboard coverage, fingerprint/client parity, IP intelligence/Tor policy, rate limiting, privacy/logging, malformed inputs, migrations, backups, delay/version logic, PHP/container hardening, reproducibility, and internal-file exposure.
- Every requested test category appears in Tasks 1–7 and the final smoke/recovery tasks.
- No task depends on a function name that is not defined in an earlier task or this plan.
- Legal/platform approval is intentionally a hard external gate and is not represented as an engineering-only checkbox.
