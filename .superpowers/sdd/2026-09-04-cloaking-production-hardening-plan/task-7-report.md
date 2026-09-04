# Task 7 Report

Date: 2026-09-04
Baseline commit: `392f6c5`

## Scope completed

- Repaired the production Docker packaging so the image now copies an explicit
  allowlist of application files instead of `COPY .`, while the build context
  excludes secrets, runtime state, and test-only inputs.
- Hardened Apache and Nginx request handling to block config files, internal
  PHP/source paths, SQLite files including WAL/SHM, logs, installer/dev-router
  entry points, and operational/documentation artifacts.
- Reworked `docker-compose.yml` so only the Caddy edge proxy publishes ports;
  the PHP service stays on a private internal network with read-only config,
  writable runtime mounts, static `/healthz`, and resource/logging limits.
- Added a Caddy fixture with explicit host allowlisting, automatic HTTPS
  storage in `/data`, and no on-demand TLS.
- Added container integration fixtures and a skip-aware shell runner under the
  existing `tests/Integration/` tree. The brief requested `tests/integration/`,
  but this checkout already has `tests/Integration/` and `core.ignorecase=true`
  on a case-insensitive filesystem, so the new files had to live under the
  existing path.
- Updated `.github/workflows/ci.yml` to append compose validation, a clean
  production image build, and Apache/Nginx/Caddy integration checks without
  replacing the existing PHP lint/test job.

## Files changed

- Modified: `.dockerignore`
- Modified: `Dockerfile`
- Modified: `docker-compose.yml`
- Modified: `.htaccess`
- Modified: `admin/.htaccess`
- Modified: `nginx.conf`
- Modified: `.github/workflows/ci.yml`
- Added: `docker/Caddyfile`
- Added: `docker/config.local.php`
- Added: `tests/Integration/apache/Dockerfile`
- Added: `tests/Integration/nginx/Dockerfile`
- Added: `tests/Integration/web_server_test.sh`

## Verification evidence

Commands run from `/Users/nasir/Documents/GitHub/Cloaking`:

- `php tests/run.php`
  - Result: PASS
  - Coverage note: existing PHP suite passed in full after the packaging and
    web-server changes.
- `docker compose config --quiet`
  - Result: PASS
- `bash -n tests/Integration/web_server_test.sh`
  - Result: PASS
- `php -l docker/config.local.php`
  - Result: PASS
- `git diff --check`
  - Result: PASS
- `tests/Integration/web_server_test.sh all`
  - Result: SKIP
  - Output: `SKIP: Docker daemon unavailable; skipping all integration checks.`
- `docker build --pull --file Dockerfile --tag cloaking:task7-local .`
  - Result: BLOCKED by environment
  - Output: `failed to connect to the docker API at unix:///Users/nasir/.docker/run/docker.sock`

## Pre-fix failure basis

The initial repository state matched the Task 7 failure conditions:

- `.dockerignore` excluded `docker/` and `install.php`, which made the current
  production Docker build impossible because the Dockerfile copied
  `docker/php.ini` and the app required `install.php`.
- `docker-compose.yml` published the PHP container directly on `8080:80`.
- `nginx.conf` used broad `^~` prefix locations plus a generic `location ~
  \.php$`, which left sensitive operational artifacts and non-front-controller
  PHP execution paths insufficiently constrained.

## Remaining concerns

- Actual `docker build --pull`, Apache/Nginx fixture runs, and the Caddy proxy
  integration could not be executed on this machine because the Docker daemon
  socket was unavailable. The CI workflow now contains those checks and should
  provide the first real red/green evidence on a runner with Docker service.
- The compose fixture defaults to `docker/config.local.php` with local host
  allowlisting for `app.localhost` and `cloaks.localhost`. Production operators
  should override `CLOAKING_CONFIG_PATH` with their real read-only config file.

## Fix round 1

Date: 2026-09-04
Parent commit before fix: `22f5620`

### Findings addressed

1. The default Caddy-backed compose deployment did not define any
   `TRUSTED_PROXIES`, so forwarded HTTPS and client IP headers from the edge
   proxy were not explicitly trusted.
2. The production Docker and Caddy references were still mutable tags rather
   than digest-pinned image references.

### Changes made

- Added deterministic compose IPAM for both networks:
  - `app`: `172.23.0.0/24`
  - `edge`: `172.24.0.0/24`
- Assigned fixed service addresses on the internal app network:
  - `edge`: `172.23.0.2`
  - `web`: `172.23.0.10`
- Updated `docker/config.local.php` so the default mounted config trusts only
  the Caddy app-network address via `TRUSTED_PROXIES = ['172.23.0.2/32']`.
- Added focused proxy trust coverage in `tests/Unit/SecurityTest.php`:
  - forwarded HTTPS and forwarded client IP are accepted from `172.23.0.2`
  - another peer (`172.23.0.10`) with spoofed forwarded headers is not trusted
- Extended `tests/Integration/web_server_test.sh` with a Caddy cookie-path
  assertion so HTTPS through the edge must set a `Secure` admin session cookie,
  while a direct spoofed `X-Forwarded-Proto` request to the web container must
  not.
- Pinned image references to immutable OCI index digests:
  - `docker.io/library/php:8.4-apache-bookworm@sha256:25d70665acee86d7231af7bc5464794abd14585f80210f85f22dfb0713ac8ec7`
  - `docker.io/library/php:8.4-fpm-bookworm@sha256:075b11566518bfa979bb9f2fe2e5359148326d659b15a2f414c2c305a0479a4e`
  - `docker.io/library/caddy:2.10-alpine@sha256:4c6e91c6ed0e2fa03efd5b44747b625fec79bc9cd06ac5235a779726618e530d`
- Documented the refresh procedure inline with `docker buildx imagetools inspect`
  comments and added `tests/Integration/check_pinned_images.sh`.
- Updated CI to fail early when pinned image references are not present before
  attempting compose validation or image builds.

### Fresh verification evidence

Commands run after the fix:

- `php tests/run.php`
  - Result: PASS
- `bash tests/Integration/check_pinned_images.sh`
  - Result: PASS
- `php -l docker/config.local.php`
  - Result: PASS
- `bash -n tests/Integration/check_pinned_images.sh`
  - Result: PASS
- `bash -n tests/Integration/web_server_test.sh`
  - Result: PASS
- `docker compose config --quiet`
  - Result: PASS
- `git diff --check`
  - Result: PASS
- `tests/Integration/web_server_test.sh caddy`
  - Result: SKIP
  - Output: `SKIP: Docker daemon unavailable; skipping caddy integration checks.`
- `docker build --pull --file Dockerfile --tag cloaking:task7-fix1 .`
  - Result: BLOCKED by environment
  - Output: `failed to connect to the docker API at unix:///Users/nasir/.docker/run/docker.sock`

### Remaining concern after fix round 1

- The new Caddy runtime assertion is implemented but not exercised on this host
  because Docker daemon access is still unavailable. CI or another Docker host
  still needs to provide the first live execution evidence for that path.
