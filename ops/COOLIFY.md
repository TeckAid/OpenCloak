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

Before any schema change, take a backup on the host first (see `ops/RELEASE.md`),
then run the migration inside the container:

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
