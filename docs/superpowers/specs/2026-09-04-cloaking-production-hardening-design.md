# Cloaking Production Hardening Design

**Date:** 2026-09-04  
**Status:** Approved in chat; implementation pending plan execution

## Goal

Make the PHP cloaking application safe to release on a public Hetzner VPS by
repairing critical correctness and security defects, adding reproducible tests
and CI, making schema changes explicit and reversible, proving web-server
source protection, and rehearsing backup/restore.

## Scope and constraints

- Keep the current dependency-light PHP application architecture; do not add a
  framework or require Composer for the test runner.
- Preserve direct-link and client-deployment behavior, but fail closed when
  configuration or verification is invalid.
- Never create a usable default credential from a web request.
- Treat the application key, API credentials, visitor identifiers, IPs, and
  referers as sensitive data.
- Keep production state under mounted `data/` and `logs/` paths; application
  code and operational files must not be writable by the web worker.
- Deployment must use an immutable image digest and a versioned release tag.
- Legal/platform approval is an external gate. The repository may provide a
  review record and checklist, but cannot claim approval without an authorized
  artifact.

## Architecture

### Request context and security

Introduce a single request-context path that validates scalar inputs, resolves
the client IP only when the immediate peer matches a CIDR-aware trusted-proxy
list, and enforces a configured canonical base URL/system host allowlist.
Forwarded scheme and host headers are never trusted directly. Admin and API
traffic require HTTPS at the edge, and unknown hosts are rejected.

### Credentials

The first administrator is created only by the CLI installer. Generated client
deployments receive a separate campaign-scoped verify credential that cannot
manage account resources. Credentials are stored as hashes where practical,
are revocable independently, and never appear in URLs.

### Persistence and migrations

Move schema evolution into numbered, idempotent migration files with a schema
version table and a CLI runner. Runtime requests only open the database. Each
destructive migration has a documented precondition and a tested rollback or
restore procedure. Multi-step deletes and counter/log updates use transactions
where atomicity matters.

### Packaging and edge

The Docker build context includes every file referenced by the Dockerfile and
excludes only secrets/runtime state. Compose keeps the PHP service private to
the internal network, mounts configuration read-only, provisions writable
state with the container UID/GID, and applies health/log/resource hardening.
Apache and Nginx integration fixtures prove that PHP files, SQLite state,
configuration, logs, and internal documents cannot be downloaded. TLS is
terminated by an explicitly configured edge proxy; custom domains are
allowlisted in that proxy rather than dynamically trusted from Host headers.

### Verification and recovery

Add a dependency-free test runner with unit/contract tests and containerized
HTTP integration tests. CI performs syntax checks, tests, clean Docker builds,
Apache/Nginx source-protection checks, and artifact scanning. A backup script
creates WAL-safe SQLite backups plus the matching application key, verifies
integrity, and restores into an isolated staging instance before release.

## Test contract

Tests must cover:

1. Campaign create/update persistence for every field, including the exact SQL
   order and all boolean checked/unchecked/create/update states.
2. Proxy IP resolution, forwarded scheme, canonical Host/system-host rejection,
   and consistent IP use in detection, logs, rate limits, and delay-start.
3. Strict HTTP(S) URL validation, malformed scalar/query inputs, API bearer
   authentication, and CSRF enforcement.
4. Verify-only, campaign-scoped client credentials and revocation.
5. Direct-link/client-mode decision and redirect parity.
6. Numbered migration application, idempotence, backup-before-destructive
   migration, and rollback/restore behavior.
7. Tenant ownership for links, campaigns, domains, client credentials, stats,
   and deletion/reassignment operations.
8. Apache and Nginx attempts to download PHP source, SQLite DB, config, logs,
   installer/dev files, and internal documentation.

## Release gates

The release is not deployable until all tests pass from a clean checkout, the
image is addressed by digest, a restore rehearsal is recorded, and an external
legal/platform approval artifact is attached or the release remains blocked.
