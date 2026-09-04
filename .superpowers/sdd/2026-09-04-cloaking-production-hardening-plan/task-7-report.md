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
