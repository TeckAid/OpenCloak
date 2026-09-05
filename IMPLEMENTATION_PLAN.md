# Implementation Plan — Production-Ready Cloaking SaaS

Historical plan, superseded by the approved production-hardening design and
plan under `docs/superpowers/`. The security rows below reflect the final
hardening pass where later review changed the original approach.

---

## Phase 1 — Security Hardening

| # | Finding | Change | File(s) |
|---|---|---|---|
| 1 | 3.1 No CSRF protection | Add `csrf_token()` / `csrf_field()` / `csrf_verify()` helpers; embed token in all admin POST forms; convert logout to POST | `includes/security.php`, all `admin/*.php` |
| 2 | 3.2 Spoofable client IP | Only honor `X-Forwarded-For`/`CF-Connecting-IP` when `REMOTE_ADDR` is in an explicit `TRUSTED_PROXIES` list; walk XFF right-to-left | `includes/bot_detector.php`, `config.php` |
| 3 | 3.3 Debug endpoint leaks internals | Remove public query-string diagnostics; expose diagnostics only as authenticated CSRF-protected admin POST | `admin/diagnostics.php`, `index.php`, `admin/links.php` |
| 4 | 3.4 Predictable secrets | Generate `app.key` atomically with mode 0600 outside the docroot; fail closed on persistence errors | `config.php`, `config.local.example.php` |
| 5 | 3.5 SQL interpolation | Convert all dashboard queries to prepared statements | `admin/dashboard.php` |
| 6 | 3.6 Stored XSS via API slug | Validate slug with same regex as web form + reserved-word blocklist in API | `api/index.php` |
| 7 | 4.1 Header injection | Validate `offer_url` (valid URL, http/https, no CR/LF) on create/update; sanitize before `header()` | `api/index.php`, `admin/links.php`, `index.php` |
| 8 | 4.2 No login rate limiting | DB-backed rate limiter (`rate_limits` table); limit login to 10 attempts / 5 min per IP | `includes/security.php`, `includes/database.php`, `admin/login.php` |
| 9 | 4.5 Session hardening | `session_set_cookie_params` (HttpOnly, Secure when HTTPS, SameSite=Lax), strict mode, named session, enforce `SESSION_LIFETIME` | `includes/bootstrap.php` |
| 10 | 5.3 Default creds advertised | Remove footer; create default admin with `must_change_password=1`; force change on first login | `admin/login.php`, `admin/settings.php`, `includes/auth.php` |
| 11 | 5.7 API key in query string | Remove `?api_key` auth; header `Authorization: Bearer` only | `api/index.php` |
| 12 | 5.9 No error handling | Global exception/error handler: log to `logs/error.log`, generic 500 page (JSON for `/api/*`), never leak paths | `includes/bootstrap.php` |
| 13 | 5.4 Naive HTTPS detection | `is_https()` honoring `X-Forwarded-Proto`; use in `base_url()` and cookie secure flag | `includes/security.php`, `includes/bootstrap.php` |
| 14 | — | Security headers on every response: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`; CSP for admin | `includes/bootstrap.php` |

## Phase 2 — Reliability & Correctness

| # | Finding | Change | File(s) |
|---|---|---|---|
| 15 | 2.1 Broken admin rewrite | Fix `.htaccess` rule to only match bare `/admin`; add `^~` prefixes in Nginx | `.htaccess`, `nginx.conf` |
| 16 | 2.2 DB downloadable | Deny `data/`, `logs/`, `includes/`, `config*.php` in Apache; Nginx already covered, extend | `.htaccess`, `nginx.conf` |
| 17 | 2.3 Bogus Docker db service | Remove `sqlite:latest` service; drop deprecated `version:` key | `docker-compose.yml` |
| 18 | 5.5 Slug collision | Reject reserved slugs (`admin`, `api`, `assets`, `index.php`, …) in web + API | `admin/links.php`, `api/index.php` |
| 19 | 5.6 Bare 404 | Reuse default white page for unknown slugs (safer for cloaking) with proper 404 status | `index.php` |

## Phase 3 — Performance

| # | Finding | Change | File(s) |
|---|---|---|---|
| 20 | 4.6 No indexes | `CREATE INDEX IF NOT EXISTS` on `hit_log(link_id, created_at)`, `hit_log(created_at)`, `links(user_id)` | `includes/database.php` |
| 21 | 4.6 Unbounded logs | Probabilistic pruning (1% of requests) honoring `LOG_RETENTION_DAYS`; prune stale rate-limit rows | `includes/database.php`, `index.php` |
| 22 | 4.3 Double detect() | Memoize detection result in `BotDetector`; single call per request | `includes/bot_detector.php`, `index.php` |
| 23 | 4.4 Untrusted IP intelligence | Authenticated HTTPS/local adapter, strict response binding, explicit fail-closed default, and reviewed data-processing scope | `includes/security.php`, `includes/bot_detector.php`, `docs/IP_INTELLIGENCE.md` |
| 24 | — | SQLite `busy_timeout=5000`, `synchronous=NORMAL` with WAL | `includes/database.php` |
| 25 | — | Opcache + production `php.ini` in Docker image; asset caching headers | `docker/php.ini`, `Dockerfile`, `.htaccess` |
| 26 | — | No session started on public cloaked requests (session only in admin) | `includes/bootstrap.php` |

## Phase 4 — Feature Completion

| # | Finding | Change | File(s) |
|---|---|---|---|
| 27 | 5.1 Tor detection missing | Implement TorDNSEL DNSBL lookup (exitlist.torproject.org → 127.0.0.2), 6h cache, skip for private IPs | `includes/bot_detector.php` |
| 28 | 5.1 Meta refresh broken | Implement `meta` redirect type; merge with delayed redirect | `index.php` |
| 29 | 5.1 Rate-limit constants unused | Implement per-IP limiter (see #8); drop unused `BLOCK_*` globals in favor of per-link settings | `config.php` |
| 30 | 5.2 Stray statements | Remove dead `prepare()->execute()` lines | `admin/settings.php` |
| 31 | 5.1 Dead code | Remove empty `checkHeadless`/`checkHeaders` blocks, dead `$_GET['s']` line | `includes/bot_detector.php`, `index.php` |
| 32 | — | Configurable `TRUSTED_PROXIES`, `ENABLE_TOR_CHECK` in `config.local.php` | `config.php`, `config.local.example.php` |

## Phase 5 — Ops, Tooling & Docs

| # | Finding | Change | File(s) |
|---|---|---|---|
| 33 | — | `install.php` CLI: create admin user with custom password, print security checklist | `install.php` |
| 34 | — | `dev-router.php` for `php -S` local dev | `dev-router.php` |
| 35 | 5.10 Docker hygiene | `.dockerignore`, correct permissions, production php.ini, AllowOverride fix | `.dockerignore`, `Dockerfile` |
| 36 | — | Rewrite README: installer-only first account, authenticated diagnostics, recovery workflow, security checklist | `README.md` |

## Verification

1. `php -l` on every PHP file.
2. End-to-end smoke test in a temp copy of the project using PHP built-in server:
   - `/` → 404; unknown slug → 404 + safe page
   - `/admin/login.php` → 200, contains CSRF field, no default-cred footer
   - Installer-created administrator login POST with CSRF → redirects
   - `/api/links` with header auth → create link (valid + invalid slug/URL rejected)
   - Slug hit with curl UA → white page; with browser UA → 302 offer
   - authenticated diagnostics GET → 405; CSRF-protected POST → JSON
   - `/data/cloaking.db` → denied (Apache-specific, noted in docs)
3. `docker compose config` validation.

---

## Completion Matrix

| # | Change | Status | Verified |
|---|---|---|---|
| 1 | CSRF on all admin forms + POST logout | ✅ | 403 on token-less POST |
| 2 | Proxy-safe client IP (TRUSTED_PROXIES) | ✅ | code review + unit path |
| 3 | Authenticated diagnostics | ✅ | public diagnostic query inert; admin GET 405; protected POST succeeds |
| 4 | Random runtime `app.key` secrets + config.local.php | ✅ | key generated atomically with mode 0600 or startup fails closed |
| 5 | Dashboard prepared statements | ✅ | lint + runtime |
| 6 | API slug validation + reserved words | ✅ | XSS slug & `admin` slug → 400 |
| 7 | offer_url validation (no CR/LF, http/https) | ✅ | `javascript:` URL → 400 |
| 8 | Login rate limiting (10/5min/IP) | ✅ | 11th attempt blocked |
| 9 | Session hardening (HttpOnly/Secure/SameSite/strict) | ✅ | headers + code |
| 10 | Forced password change; no default-cred footer | ✅ | banner shown; flag clears after change |
| 11 | API: header-only auth | ✅ | `?api_key=` → 401 |
| 12 | Global error handler (JSON for API, no path leaks) | ✅ | implemented in bootstrap |
| 13 | HTTPS-aware base URL (`X-Forwarded-Proto`) | ✅ | `app_is_https()` |
| 14 | Security headers + admin CSP | ✅ | verified on login page |
| 15 | Fixed `.htaccess` admin rule | ✅ | bare `/admin` only |
| 16 | Deny `data/logs/includes/config` in Apache + Nginx | ✅ | rules in both configs |
| 17 | Removed bogus `sqlite:latest` service | ✅ | `docker compose config` OK |
| 18 | Reserved slug blocklist | ✅ | web + API |
| 19 | Safe 404 with default white page | ✅ | unknown slug → 404 + safe page |
| 20 | Indexes on `hit_log` and `links` | ✅ | schema + runtime |
| 21 | Probabilistic log pruning + rate-limit cleanup | ✅ | `maintenance_tick()` |
| 22 | Memoized detection (single run/request) | ✅ | `getResult()` reused |
| 23 | Authenticated IP intelligence | ✅ | HTTPS bearer transport, subject binding, explicit unavailable result |
| 24 | SQLite `busy_timeout`, `synchronous=NORMAL` | ✅ | PRAGMAs set |
| 25 | Opcache + prod php.ini + asset caching | ✅ | `docker/php.ini`, `mod_expires` |
| 26 | No session on public cloaked requests | ✅ | `boot_app(false)` |
| 27 | TorDNSEL Tor exit detection | ✅ | implemented (DNS-dependent, cached) |
| 28 | Meta-refresh redirect implemented | ✅ | verified: `content="3;url=…"` |
| 29 | Rate-limit constants wired up | ✅ | login limiter |
| 30 | Removed stray statements in settings.php | ✅ | rewritten |
| 31 | Dead code removed | ✅ | bot_detector + index.php cleaned |
| 32 | `config.local.example.php` with TRUSTED_PROXIES etc. | ✅ | documented |
| 33 | `install.php` CLI | ✅ | creates/updates admin user |
| 34 | `dev-router.php` for `php -S` | ✅ | used for all smoke tests |
| 35 | `.dockerignore`, Dockerfile perms + AllowOverride | ✅ | image builds clean config |
| 36 | README rewritten | ✅ | new setup + security docs |

## Smoke Test Evidence

- Root/unknown slug → `404`; login page → `200` with `_csrf` field and no default-cred footer
- Security headers present: `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, CSP on admin
- Installer-created administrator login → 302 → dashboard
- Password change → `must_change_password=0`, dashboard 200 afterwards
- API: no auth → 401; query-string key → 401; create → 201; XSS slug/reserved slug/bad URL → 400
- Cloaking: curl UA → white page; Googlebot → white page; HeadlessChrome → white page;
  real browser headers → 302 to offer; meta-refresh link → `content="3;url=https://example.com/landing"`
- Rate limit: 10 attempts allowed, 11th blocked
- CSRF: token-less POST → 403
- Stats: `hits=7 offers=1 white=6` tracked correctly
