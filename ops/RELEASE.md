# Release Policy

This release workflow assumes the app runs behind a TLS terminator such as Caddy on a private Docker network and keeps mutable state outside the immutable app image.

## Preconditions

1. Deploy only from a clean checkout whose image digest and git commit are recorded in the release ticket.
2. Confirm `config.local.php`, `app.key`, `cloaking.sqlite`, and log directories live in a root-owned deployment directory with the runtime subdirectories writable only by the PHP service account.
3. Confirm the Hetzner Firewall exposes only `22/tcp`, `80/tcp`, and `443/tcp` to the internet. Do not publish the PHP container port.
4. Keep the previous image digest, the latest verified backup, and the latest successful restore rehearsal together so rollback can happen without guessing.

## Pre-Deploy Backup

Run the backup from the host before every migration or image change:

```bash
mkdir -p /srv/cloaking/backups/$(date -u +%Y%m%dT%H%M%SZ)
bash ops/backup_sqlite.sh \
  --db=/srv/cloaking/runtime/cloaking.sqlite \
  --app-key=/srv/cloaking/runtime/app.key \
  --config=/srv/cloaking/config/config.local.php \
  --output=/srv/cloaking/backups/$(date -u +%Y%m%dT%H%M%SZ)
```

Treat a backup as valid only when the command exits `0` and writes `cloaking.sqlite`, `app.key`, `config.local.php`, `backup-metadata.json`, and `SHA256SUMS`.

## Restore Rehearsal Gate

Before production rollout, rehearse the exact backup you plan to trust:

```bash
mkdir -p ops/rehearsals/$(date -u +%Y%m%dT%H%M%SZ)
bash ops/restore_rehearsal.sh \
  --backup=/srv/cloaking/backups/20260904T000000Z \
  --evidence-dir=ops/rehearsals/20260904T000000Z
```

Review:

- `rehearsal-summary.json` for `integrity_check`, `migration_exit`, `rpo_seconds`, and `rto_seconds`
- `smoke-results.json` for the restored login, unauthenticated API, and sample link path checks
- `migration.stdout.log` and `migration.stderr.log` for schema drift

If any checksum, integrity, migration, or smoke step fails, stop the release and create a fresh backup after fixing the issue.

## Deployment Sequence

1. Pull the exact approved image digest.
2. Start or refresh the private `web` container without publishing its port.
3. Run `php bin/migrate.php --db=/srv/cloaking/runtime/cloaking.sqlite` inside the new container or a one-shot maintenance container attached to the same private volume.
4. Reload the TLS edge only after migrations succeed.
5. Run smoke checks against the live domain:
   - `GET /admin/login.php` returns `200`
   - `GET /api/links` without `Authorization` returns `401`
   - A known active short link returns the expected redirect or safe page
6. Watch container health, edge logs, and application logs for at least one RTO window after cutover.

## Rollback

1. If the new image fails before migrations, redeploy the previous image digest.
2. If `bin/rollback.php --to=<version>` supports the exact target and passes in staging, you may use it for the migration path it explicitly supports.
3. Otherwise restore from the pre-deploy backup instead of attempting an untested destructive downgrade.
4. Re-run `ops/restore_rehearsal.sh` against the replacement backup after the incident so the next release is still covered by fresh evidence.

## Targets

- Target RPO: `<= 300` seconds between the latest committed SQLite state and the captured backup metadata.
- Target RTO: `<= 900` seconds from restore start to successful login/API/link smoke verification.

Escalate the release if the rehearsal exceeds either target or if the on-call operator cannot identify the exact backup, config, and image digest being deployed.
