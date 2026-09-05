# Coolify Deployment (cloak.teckaid.com)

Production runs as a Coolify application, not via `docker-compose.yml`
(Coolify's Traefik owns ports 80/443 on the host). The repo compose stack and
`ops/deploy_web.sh` remain for self-managed hosts.

## Topology

- Cloudflare (proxied) -> Traefik `coolify-proxy` (Let's Encrypt, HTTP-01)
  -> container `intavgn5d5ojfwkjeata1opu` (Apache/PHP, port 80, never published).
- Coolify project `cloaking`, environment `production`, application `cloaking-web`.
- Build pack: Dockerfile at `/Dockerfile`, branch `main`.
- Git source: bare repo on the host, `git@host.docker.internal:/srv/git/cloaking.git`
  (restricted `git` user, git-shell). Locally this is the `hetzner` remote.

## Host paths

| Path | Purpose |
| --- | --- |
| `/srv/cloaking/runtime` | `app.key`, `cloaking.sqlite`, `logs/` (bind-mounted, www-data 0770) |
| `/srv/cloaking/config/config.local.php` | symlink to the Coolify file mount that becomes `/var/www/html/config.local.php` |
| `/srv/cloaking/backups` | backup captures (`ops/backup_sqlite.sh`) |
| `/data/coolify/applications/intavgn5d5ojfwkjeata1opu/` | Coolify-generated compose + file mounts |

Edit production config through the Coolify UI (Storages -> file mount) or by
editing the mounted file on the host, then restart the application.

## Release

```bash
git push hetzner main
curl -s -X POST -H "Authorization: Bearer $COOLIFY_TOKEN" \
  'https://ubuntu-coolify.taild91939.ts.net/api/v1/deploy?uuid=intavgn5d5ojfwkjeata1opu'
```

### Backup (before any schema change)

`.dockerignore` keeps `ops/` out of the image, so copy the backup script into
the container and run it there. It uses PHP `VACUUM INTO` (no `sqlite3` CLI
needed) and writes onto the bind-mounted runtime dir, which is then moved into
the root-owned backups tree:

```bash
C=intavgn5d5ojfwkjeata1opu
TS=$(date -u +%Y%m%dT%H%M%SZ)
DIG=$(docker image inspect --format '{{.Id}}' "$(docker inspect --format '{{.Config.Image}}' $C)")
SHA=$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' $C | sed -n 's/^SOURCE_COMMIT=//p')
git --git-dir=/srv/git/cloaking.git show HEAD:ops/backup_sqlite.sh > /tmp/backup_sqlite.sh
docker cp /tmp/backup_sqlite.sh $C:/tmp/backup_sqlite.sh
docker exec -u www-data $C bash /tmp/backup_sqlite.sh \
  --output=/srv/cloaking/runtime/_bkp_$TS \
  --config=/var/www/html/config.local.php \
  --db=/srv/cloaking/runtime/cloaking.sqlite \
  --app-key=/srv/cloaking/runtime/app.key \
  --migration-target=3 --source-commit=$SHA --image-digest=$DIG
mkdir -p /srv/cloaking/backups/$TS
cp -a /srv/cloaking/runtime/_bkp_$TS/. /srv/cloaking/backups/$TS/ && rm -rf /srv/cloaking/runtime/_bkp_$TS
chown -R root:root /srv/cloaking/backups/$TS && chmod 0700 /srv/cloaking/backups/$TS
( cd /srv/cloaking/backups/$TS && sha256sum -c SHA256SUMS )
```

A valid capture writes `cloaking.sqlite`, `app.key`, `config.local.php`,
`backup-metadata.json` and `SHA256SUMS`, and the checksum verify passes.

Then run the migration inside the container:

```bash
docker exec -u www-data intavgn5d5ojfwkjeata1opu \
  php bin/migrate.php --db=/srv/cloaking/runtime/cloaking.sqlite
```

Admin accounts are created interactively over SSH (password via stdin only):

```bash
docker exec -i -u www-data intavgn5d5ojfwkjeata1opu \
  php install.php --username=<name> --password-stdin
```

## Known gaps

- Coolify's `custom_docker_run_options` parser cannot express
  `--security-opt no-new-privileges`, `--read-only`, `--tmpfs` or `--pids-limit`,
  so those compose hardening flags are not applied here. Memory is capped at 512m.
- `TRUST_CLOUDFLARE` stays `false` until the Hetzner firewall restricts 80/443
  to Cloudflare's published ranges. Client IPs are still resolved correctly via
  `TRUSTED_PROXIES` (Coolify network + Cloudflare ranges).
- Cloudflare SSL mode should be Full (strict); the origin serves a valid
  Let's Encrypt certificate.
