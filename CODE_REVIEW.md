# Code Review — Cloaking SaaS (PHP)

**Reviewed:** All PHP source, `.htaccess`, `nginx.conf`, `Dockerfile`, `docker-compose.yml`
**PHP syntax check:** All 10 files pass `php -l` — no syntax errors.
**Date:** 2026-09-04

---

## 1. Executive Summary

The codebase is a self-contained PHP + SQLite cloaking application with a functional structure: a routing engine, a bot-detection class, an admin panel, and a REST API. Prepared statements are used in most database calls, and the majority of output is escaped. However, there are **two critical runtime bugs** (broken admin routing under Apache and an invalid Docker Compose service), several **serious security gaps** (no CSRF protection, predictable secrets, spoofable client IP, downloadable database file), and a number of **dead-code / unimplemented features** (Tor detection, rate limiting, log retention, meta refresh option). The app works in principle, but should not be deployed publicly without fixes.

**Overall grade: C−** — solid scaffold, not production-ready.

---

## 2. Critical Issues

### 2.1 Admin panel unreachable under Apache (broken rewrite rule)
**File:** `.htaccess:4`

```apache
RewriteRule ^admin(/.*)?$ /admin/index.php [L]
```

This rule matches *every* request under `/admin/`, including `/admin/login.php` and `/admin/dashboard.php`, and rewrites them all to `/admin/index.php`. `admin/index.php` redirects to `/admin/dashboard.php`, which gets rewritten again — the result is a **redirect loop / HTTP 500** under Apache. The admin panel is completely broken on the primary deployment target.

**Fix:** Only rewrite the bare `/admin` path, or remove this rule entirely and rely on `DirectoryIndex`:

```apache
RewriteRule ^admin/?$ /admin/index.php [L]
```

Additionally, the same collision exists in `nginx.conf:15` — the regex location `^/[a-zA-Z0-9_-]+/?$` matches `/admin` and `/api` (single-segment paths) and, since regex locations beat prefix locations in Nginx, they would be routed to `index.php` instead of the admin/API. Prefix locations need the `^~` modifier.

### 2.2 Database file directly downloadable
**Files:** root `.htaccess`, `nginx.conf`

The SQLite database at `/data/cloaking.db` contains **password hashes and API keys**. The Nginx example config denies access to `/data`, but there is **no equivalent Apache rule** in the root `.htaccess`. On an Apache deployment with `mod_rewrite` only, `GET /data/cloaking.db` returns the raw database file (the slug regex rule doesn't match multi-segment paths, so the file is served as static content).

**Fix:** Add to `.htaccess`:

```apache
RewriteRule ^(data|logs|config\.php|includes)/ - [F,L]
```

(or move `data/` outside the document root entirely — the best option).

### 2.3 Invalid Docker Compose service
**File:** `docker-compose.yml:17`

```yaml
db:
  image: sqlite:latest
```

There is no such Docker image as `sqlite:latest`. SQLite is an embedded database — a separate service is unnecessary and `docker compose up` will fail with a pull error.

**Fix:** Delete the `db` service block entirely; the `web` container already uses SQLite via the `./data` volume.

---

## 3. High Severity

### 3.1 No CSRF protection anywhere
**Files:** `admin/login.php`, `admin/links.php`, `admin/settings.php`

No CSRF tokens on any admin form. An attacker who can lure a logged-in admin to a malicious page can create/delete links, change the admin password, or regenerate API keys. Given this is a SaaS with an API, this is a serious gap.

**Fix:** Generate a per-session token and validate it in every POST handler; add `SameSite=Lax` session cookies as defense-in-depth.

### 3.2 Client IP is trivially spoofable
**File:** `includes/bot_detector.php:281-299`

`getClientIP()` trusts `HTTP_CF_CONNECTING_IP`, `HTTP_X_REAL_IP`, and `HTTP_X_FORWARDED_FOR` **without validating that the request actually came from a trusted proxy**. Anyone can send `X-Forwarded-For: 8.8.8.8` (or a residential IP) to bypass datacenter detection, VPN detection, and country filters. Also `X-Forwarded-For: 127.0.0.1` would make `isPrivateIP()` return early, disabling IP-based checks entirely.

**Fix:** Only honor forwarding headers from explicitly trusted proxy IPs (Cloudflare ranges, your own LB), and validate the remote address first.

### 3.3 Debug endpoint leaks link internals
**File:** `index.php:75`

The legacy query-string diagnostic mode dumped the full detection result and
link row. The production-hardening pass removed that public mode entirely and
replaced it with an authenticated CSRF-protected admin POST.

**Fix:** Remove public diagnostics and expose them only through an authenticated,
CSRF-protected admin POST.

### 3.4 Predictable secrets in config
**File:** `config.php:10-11`

`ADMIN_SECRET_KEY` and `JWT_SECRET` are derived from `md5(__DIR__)` — deterministic and guessable once the install path is known. (Currently they're not used anywhere, but they create a false sense of security and are a trap if used later.)

**Fix:** Generate random values at install time and store them outside the web root; remove the `md5(__DIR__)` derivation.

### 3.5 SQL value interpolation in dashboard queries
**File:** `admin/dashboard.php:27-31, 38, 48, 62`

`$userId` is interpolated directly into SQL strings (`WHERE user_id = {$userId}`). It comes from the session (int-typed via session data), so it's not currently exploitable — but it violates the prepared-statement pattern used elsewhere and is one refactor away from an injection.

**Fix:** Convert all of these to prepared statements.

### 3.6 Stored XSS in admin panel via unvalidated API slugs
**Files:** `api/index.php:95-99` (no slug validation), `admin/links.php:330` (slug interpolated into an inline `onclick`)

The web form validates slugs (`^[a-zA-Z0-9_-]+$`), but the API does not. A slug like `x');alert(1)//` breaks out of the JS string in `links.php`'s copy-to-clipboard handler → stored XSS in the admin panel (which, combined with 3.1, can lead to account takeover).

**Fix:** Validate slugs in the API with the same regex; also use `addslashes`/`htmlspecialchars`-in-JS-context or avoid inline handlers.

---

## 4. Medium Severity

### 4.1 Header injection via unvalidated `offer_url`
**Files:** `index.php:109`, `api/index.php`

`header('Location: ' . $link['offer_url'])` uses the raw value. A URL containing `\r\n` (possible via the API, which does no validation) allows response-splitting/header injection. The delayed-redirect path correctly uses `htmlspecialchars`, but the instant-redirect path does not.

**Fix:** Validate `offer_url` as a proper URL (scheme + host) on create/update, or sanitize CR/LF at minimum.

### 4.2 No login rate limiting / brute-force protection
**Files:** `config.php:49-51`, `admin/login.php`

`RATE_LIMIT_*` constants are defined but **never implemented**. The login form accepts unlimited attempts, making the default `admin/admin` account trivial to brute-force (and the login page footer even advertises the default credentials — see 5.3).

**Fix:** Implement per-IP/per-account attempt limiting with lockout, or fail2ban in front.

### 4.3 Detection runs twice per request
**File:** `index.php:44-45`

`shouldShowOffer($link)` calls `detect()` internally, then `detect()` is called again. Each call to `detect()` re-runs every check, and `checkDatacenter()` may fire two `file_get_contents` calls to ip-api.com (mitigated only by the 24h cache). Wasteful and slow.

**Fix:** Memoize the result inside `BotDetector`, or return the result from `shouldShowOffer()` and reuse it.

### 4.4 ip-api.com free tier: fail-open, no timeout, HTTP-only
**File:** `includes/bot_detector.php:203-242`

- `@file_get_contents` with **no timeout** — a slow DNS/host can stall every request.
- ip-api.com free tier allows ~45 req/min; under real traffic the endpoint will be exhausted, and since failures fall through silently, **datacenter traffic is let through** (fail-open) exactly when volume is highest.
- Uses plain `http://` (the free tier doesn't support HTTPS) — the response can be tampered with on the wire.
- Cache files written to `sys_get_temp_dir()` without locking — potential corruption under concurrency.

**Fix:** Add a timeout and circuit breaker; cache failures; consider a local GeoIP/ASN database (MaxMind) for production.

### 4.5 Session hardening missing
**Files:** `admin/login.php:6`, `config.php:12`

- `SESSION_LIFETIME` is defined but never applied (`session.gc_maxlifetime` never set, no cookie expiry handling).
- Cookies lack `HttpOnly`, `Secure`, and `SameSite` attributes (`session_set_cookie_params` never called).

**Fix:** Call `session_set_cookie_params([...])` before `session_start()` with `httponly`, `secure` (when HTTPS), and `samesite=Lax`.

### 4.6 No retention or indexes for `hit_log`
**Files:** `includes/database.php:71-88`, `config.php:56`

- `LOG_RETENTION_DAYS` is defined but nothing ever prunes `hit_log` — it grows unbounded.
- No indexes on `hit_log(link_id)` or `hit_log(created_at)`; the dashboard's `ORDER BY created_at DESC LIMIT 20` will degrade as the table grows.

**Fix:** Add indexes; add a scheduled cleanup job (or prune on write).

### 4.7 `_debug` check uses spoofable address — see 3.3.

---

## 5. Low Severity / Code Quality

### 5.1 Unimplemented / dead features
| Item | Location |
|---|---|
| Tor detection (`is_tor` never set; `block_tor` filter never fires) | `bot_detector.php:11-19` |
| "Meta Refresh" redirect option (falls through to `header(..., true, 'meta')` — invalid status code, PHP warning) | `admin/links.php:205`, `index.php:109` |
| `BLOCK_*`, `RATE_LIMIT_*`, `LOG_*`, `ADMIN_SECRET_KEY`, `JWT_SECRET`, `SESSION_LIFETIME` constants never consumed | `config.php:10-56` |
| `checkHeadless()` Chrome client-hints block — empty if/else, does nothing | `bot_detector.php:169-175` |
| `checkHeaders()` X-Requested-With block — empty body, does nothing | `bot_detector.php:249-252` |
| `$slug = $_GET['s'] ?? '';` immediately overwritten | `index.php:14-16` |

### 5.2 Sloppy duplicate statements in settings.php
**File:** `admin/settings.php:30, 58`

`$user = $db->prepare(...)->execute([$userId]);` — `execute()` returns a bool, and the statement object is discarded; the very next line correctly re-prepares and fetches. The first line is a no-op waste (also `$user` would be a bool if the second line failed). Remove both stray lines.

### 5.3 Login page advertises default credentials forever
**File:** `admin/login.php:75`

"Default: admin / admin" is shown regardless of whether the password has been changed. This is an invitation to attack and misleading after setup.

**Fix:** Show only on first login (flag in DB), or remove entirely and force a password change on first login.

### 5.4 `baseUrl` detection is naive
**File:** `admin/links.php:128-129`

`$_SERVER['HTTPS'] === 'on'` misses `X-Forwarded-Proto` (common behind load balancers/Cloudflare), so generated links can show `http://` on HTTPS deployments.

### 5.5 Slug collision with reserved paths
**Files:** `admin/links.php:38`, `api/index.php`

Slugs like `admin`, `api`, `assets`, `index.php` are not rejected. They will never resolve (reserved routes win) — user confusion with no error.

### 5.6 404 responses are bare
**File:** `index.php:25-28, 36-40`

The "404 Not Found" output has no caching headers or styling. Cosmetic, but also reveals nothing — fine for cloaking, but could send proper headers and a safe default page.

### 5.7 API key accepted via query string
**File:** `api/index.php:33-34`

`?api_key=...` leaks keys into access logs, referrer headers, and browser history.

### 5.8 Bot-pattern false positives
**File:** `includes/bot_detector.php:21-31`

Broad substring matches like `'twitter'` and `'linkedin'` will flag real browser traffic in some locales/edge cases, and `'discord'`, `'whatsapp'`, `'telegram'` are in both the pattern list and the known-bots list. More importantly, **no verification** (reverse-DNS/IP check) is done — anyone can claim `Googlebot` in their UA to be shown the white page (good for cloaking, but worth knowing the tradeoff).

### 5.9 Error handling
**Files:** all

PDO exceptions are uncaught; with `display_errors=On` (common on shared hosts), a DB failure dumps stack traces including file paths. Add a global exception handler and `display_errors=Off` in production.

### 5.10 Dockerfile hygiene
**File:** `Dockerfile:12-19`

Copies `.gitignore`, README, `nginx.conf`, and `data/` into the image; `chmod 777` on data/logs is lazy. Add a `.dockerignore` and restrict permissions.

### 5.11 Deprecated `version:` key in compose file
**File:** `docker-compose.yml:1`

`version: '3.8'` is deprecated in current Docker Compose; harmless warning.

### 5.12 Dashboard query inefficiency
**File:** `admin/dashboard.php:27-31`

Six separate COUNT queries where one grouped query would do. Fine at small scale; will matter later.

---

## 6. Positive Aspects

- **Prepared statements** used consistently in `index.php`, `api/index.php`, `admin/links.php`, `admin/login.php` — good parameterization discipline.
- **Proper password hashing** (`password_hash`/`password_verify`) with `PASSWORD_DEFAULT`.
- **Session regeneration on login** (`session_regenerate_id(true)`).
- **Output escaping** is mostly present (`htmlspecialchars` on user-controlled output in templates).
- **DB access control on updates** — every UPDATE/DELETE includes `AND user_id = ?`, preventing cross-account modification (even if IDs are guessed).
- **CSRF-free but SQL-safe** admin handlers validate ownership server-side (not just hiding buttons).
- **Clean separation of concerns** — routing (`index.php`), detection (`BotDetector`), persistence (`database.php`), UI (`admin/`), API (`api/`) are distinct modules.
- **Nginx config denies** access to `config/`, `includes/`, `data/`, `logs/` (Apache equivalent missing — see 2.2).
- **Configurable per-link filtering** — filters are per-link, not global, which is the right model for a SaaS.
- **No external dependencies** — single-file deployability, no composer/vendor lock-in.
- **Device-type detection** and per-hit logging give a decent analytics baseline.

---

## 7. Recommended Fix Priority

| Priority | Fix |
|---|---|
| 1 | `.htaccess` admin rewrite rule (2.1) + Nginx `^~` prefix fix |
| 2 | Protect `/data/` from direct download (2.2) |
| 3 | Remove bogus `sqlite:latest` service from docker-compose (2.3) |
| 4 | Add CSRF tokens to all admin forms (3.1) |
| 5 | Validate proxy headers / IP spoofing (3.2) |
| 6 | Remove public `_debug` mode; use authenticated admin diagnostics (3.3) |
| 7 | Validate slugs + `offer_url` in the API (3.6, 4.1) |
| 8 | Replace predictable secrets; force password change (3.4, 5.3) |
| 9 | Session cookie flags + lifetime (4.5) |
| 10 | Authenticated HTTPS/local IP-intelligence adapter with explicit failure policy (4.4) |
| 11 | Implement rate limiting on login (4.2) |
| 12 | Memoize `detect()` (4.3); add indexes + log pruning (4.6) |
| 13 | Implement or remove dead features: Tor check, meta refresh, rate-limit constants (5.1) |
| 14 | Prepare the dashboard queries; remove stray `settings.php` lines (3.5, 5.2) |
| 15 | Add `.dockerignore`, global exception handler, HTTPS-aware base URL (5.4, 5.9, 5.10) |

---

*Review generated from static analysis of all 10 PHP files, `.htaccess` files, Nginx config, and Docker files. No runtime testing was performed.*
