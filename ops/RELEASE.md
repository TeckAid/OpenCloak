# Release Policy

This release workflow assumes the app runs behind a TLS terminator such as Caddy on a private Docker network and keeps mutable state outside the immutable app image.

## Preconditions

1. Deploy only from a clean checkout whose image digest and git commit are recorded in the release ticket.
2. Confirm `config.local.php`, `app.key`, `cloaking.sqlite`, and log directories live in a root-owned deployment directory with the runtime subdirectories writable only by the PHP service account.
3. Confirm the Hetzner Firewall exposes only `22/tcp`, `80/tcp`, and `443/tcp` to the internet. Do not publish the PHP container port.
4. Keep the previous image digest, the latest verified backup, and the latest successful restore rehearsal together so rollback can happen without guessing.
5. Keep the release blocked until `LEGAL_PLATFORM_REVIEW.md` has an authorized external decision plus a protected `LEGAL_APPROVAL_ATTESTATION`, and until immutable tag/digest metadata exists for the exact commit being released.

## Pre-Deploy Backup

Run the backup before every migration or image change using the exact deployment
config that the app is running with:

```bash
mkdir -p /srv/cloaking/backups/$(date -u +%Y%m%dT%H%M%SZ)
bash ops/backup_sqlite.sh \
  --db=/srv/cloaking/runtime/cloaking.sqlite \
  --app-key=/srv/cloaking/runtime/app.key \
  --config=/srv/cloaking/config/config.local.php \
  --output=/srv/cloaking/backups/$(date -u +%Y%m%dT%H%M%SZ)
```

Treat a backup as valid only when the command exits `0` and writes `cloaking.sqlite`, `app.key`, `config.local.php`, `backup-metadata.json`, and `SHA256SUMS`.
The backup command now fails closed if `--config` is missing, unreadable, or
declares runtime paths that do not match `--db` and `--app-key`.

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
Do not commit raw backup artifacts, SQLite files, app keys, or reusable runtime
config into version control. Commit only redacted evidence such as summaries,
safe smoke-status excerpts, and checksum manifests.

## Staged Smoke Gate

Before creating a release tag or publishing a digest, run the staged smoke suite
against the TLS front door with real admin credentials and a real restart
command:

```bash
bash ops/smoke_test.sh \
  --https-base-url=https://app.example.com \
  --http-base-url=http://app.example.com \
  --admin-username=owner \
  --admin-password='replace-me' \
  --restart-command='docker compose restart web edge'
```

The smoke script verifies:

- HTTP to HTTPS redirect
- Secure `cloaksess` cookie plus admin auth and CSRF rejection
- `401` for `/api/links` without `Authorization`
- denial of runtime/config/source files
- `421` rejection for an unknown `Host`
- parity between a direct cloaked link and a generated client running locally against the staged `/api/verify`
- campaign creation/update persistence
- dashboard visibility for client verify hits
- persistence after the operator-supplied restart command

The script writes temporary campaign/link smoke data, generates a local client
from `/admin/client.php`, and deletes the temporary records before exit. If the
staged URL, credentials, restart command, legal approval, immutable digest, or
tag metadata are missing, stop and report the release as blocked.

## Deployment Sequence

1. Confirm the legal/platform artifact, protected attestation, immutable tag, immutable image digest, and rollback evidence all exist for the exact source commit you intend to release. If any item is absent, the release remains blocked.
2. Pull the exact approved image digest.
3. Start or refresh the private `web` container without publishing its port.
4. Run `php bin/migrate.php --db=/srv/cloaking/runtime/cloaking.sqlite` inside the new container or a one-shot maintenance container attached to the same private volume.
5. Reload the TLS edge only after migrations succeed.
6. Run `bash ops/smoke_test.sh ...` against the staged or freshly cut-over domain and keep its console transcript with the release ticket.
7. Watch container health, edge logs, and application logs for at least one RTO window after cutover.

## Rollback

1. If the new image fails before migrations, redeploy the previous image digest.
2. If `bin/rollback.php --to=<version>` supports the exact target and passes in staging, you may use it for the migration path it explicitly supports.
3. Otherwise restore from the pre-deploy backup instead of attempting an untested destructive downgrade.
4. Re-run `ops/restore_rehearsal.sh` against the replacement backup after the incident so the next release is still covered by fresh evidence.

## Targets

- Target RPO: `<= 300` seconds between the latest committed SQLite state and the captured backup metadata.
- Target RTO: `<= 900` seconds from restore start to successful login/API/link smoke verification.

Escalate the release if the rehearsal exceeds either target or if the on-call operator cannot identify the exact backup, config, and image digest being deployed.
