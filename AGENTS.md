# AGENTS.md — Workspace and production operating rules

Cloaking SaaS: self-hosted PHP (SQLite) visitor-filtering application. Detection
engine, campaigns, short links, multi-domain, client-deployment mode, admin
panel, and REST API. PHP 8.2+, Docker Compose (Caddy edge + Apache web) for
production.

## Verification commands (always run before declaring work done)

```bash
php tests/run.php                                    # full test suite (exit 0)
find . -name '*.php' -not -path './runtime/*' -not -path './.git/*' -print0 \
  | xargs -0 -n1 php -l                            # PHP lint
find . -name '*.sh' -not -path './.git/*' -print0 \
  | xargs -0 -n1 bash -n                           # shell lint
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"
docker compose config --quiet                      # when Docker is available
git diff --check
```

## Hard rules (never break these)

- Never commit or print secrets: `config.local.php`, `app.key`, API keys,
  SQLite files, backup artifacts. Use `--admin-password-stdin` (never put
  passwords in command arguments).
- Never edit `app.key` or the live database by hand. Migrations go through
  `bin/migrate.php`; destructive migration 002 additionally requires a
  verified `--backup-manifest` and `--app-key` — do not bypass that gate.
- Never skip the pre-change backup on production. `ops/backup_sqlite.sh`
  exiting `0` is the precondition for any deploy, migration, or restore.
- Do not enable `IP_INTELLIGENCE_ENDPOINT`/`IP_INTELLIGENCE_API_KEY` until
  `docs/IP_INTELLIGENCE.md` and `LEGAL_PLATFORM_REVIEW.md` have an authorized
  decision. `IP_INTELLIGENCE_FAILURE_MODE` stays `closed`.
- `TRUST_CLOUDFLARE=true` requires an origin firewall locked to Cloudflare IP
  ranges. Do not enable it otherwise.
- Never publish the PHP container port; only Caddy's 80/443 are exposed.
- Never weaken the smoke or restore-rehearsal gates to make a deploy pass.

## Production server (when running under /srv/cloaking, or as root/deploy user)

Environment detection: you are on production when `docker compose ps` succeeds
and `/srv/cloaking` exists. In that context:

### Allowed commands

- Read-only state: `docker compose ps`, `docker compose logs --tail=200 web edge`,
  `curl -fsS http://127.0.0.1/healthz` (inside web), log tails under the runtime
  dir, `df -h`, `docker system df`
- Deploys: `sudo bash ops/deploy_web.sh ...` (see below) — this is the ONLY
  supported change path
- Backups: `sudo bash ops/backup_sqlite.sh ...` per `ops/RELEASE.md`
- Migration: `sudo php bin/migrate.php --db=... --backup-manifest=... --app-key=...`
- Rehearsal: `sudo bash ops/restore_rehearsal.sh ...` per `ops/RELEASE.md`
- Health checks: `docker compose ps --format json`, container health status

### Forbidden commands

- `docker compose down`, `docker system prune`, `rm -rf` under `/srv`,
  editing `config.local.php`/`app.key` directly, `git push` from the server,
  installing packages, changing firewall rules, anything that modifies
  `.github/workflows/ci.yml` without a review

### Change workflow (every session)

1. Confirm clean checkout: `git status --porcelain` is empty, on `main`,
   `git rev-parse HEAD` matches the approved release commit.
2. Run the deploy script; it internally performs: pre-deploy backup →
   migration (with manifest) → build → `up -d` → health wait → smoke test.
   ```bash
   sudo bash ops/deploy_web.sh \
     --repo-dir=/srv/cloaking/app \
     --config=/srv/cloaking/config/config.local.php \
     --backup-dir=/srv/cloaking/backups \
     --https-base-url=https://app.example.com \
     --admin-username=owner \
     --admin-password-stdin
   ```
3. If any step fails, the script aborts before changing state where possible.
   Do not continue manually — fix the failing gate and rerun from step 1.
4. Record the new commit + image digest + backup directory in the release
   ticket. Keep the previous digest and latest verified backup together for
   rollback.
5. Rollback = redeploy the previous pinned image against the previous verified
   backup. Never roll back by editing the live database.

### Session hygiene

- Log every session (`script` or tee to the ops evidence directory).
- State every destructive step and wait for confirmation before running it.
- If uncertain whether you are on staging or production, stop and ask.

## Release gates (do not declare production ready)

Release stays blocked until, per `ops/RELEASE.md` and the SDD release record:
immutable tag + image digest + SBOM exist for the exact commit;
`LEGAL_PLATFORM_REVIEW.md` has an authorized decision and a protected
`LEGAL_APPROVAL_ATTESTATION`; and a live staged TLS smoke plus backup/restore
rehearsal have produced real evidence.
