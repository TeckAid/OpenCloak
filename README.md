# Cloaking SaaS

A self-hosted PHP cloaking solution with bot detection, campaigns, multi-domain short links, client-side deployment, fingerprinting, admin panel, and REST API. Requires **PHP 8.4+** with the PDO SQLite extension.

## Features

- **Bot Detection**: crawlers, social bots, HTTP clients, automation tools
- **Datacenter / VPN / Tor / Headless detection**
- **In-app client detection**: Facebook, Instagram, Threads, TikTok/Douyin, Twitter/X, LinkedIn, LINE, KakaoTalk, WeChat, Telegram, Snapchat
- **OS + version rules**: Windows, macOS, iOS, Android, Linux, Chrome OS with minimum-version support
- **Device rules**: desktop, mobile, tablet, smarttv, console, wearable
- **Language rules**, **country rules**
- **Referrer rules** with `*` wildcards and allow-empty-referer toggle
- **URL parameter rules** (require `utm_source=*`, `click_id=*` …)
- **JS fingerprinting**: screen-resolution tiers (iPhone / Android S/M/L / tablet / PC), require-screen-info, single-visit-per-device tokens
- **Campaigns**: reusable rule+action containers with presets (Facebook mobile, TikTok mobile, Google desktop), cloning, enable/disable
- **Multi-domain**: bind links to custom domains (CNAME) — links resolve as `https://s.example.com/slug`
- **Client-deployment mode**: generated `index.php` + `tracker.min.js` for your own landing server, verified against our API server-side
- **Offer rotation / A-B testing**: offer pools with random or sequential rotation
- **Multi-geo routing**: route visitors to different offers by country (`US=https://…`, `*` fallback)
- **Iframe delivery**: show the offer in a full-page iframe (or classic redirect)
- **UTM forwarding**: pass original UTM/query params through to the offer
- **Delay-start**: block the first N unique IPs (launch protection), permanent or warm-up mode
- **Blocked URL params** (`utm_city=Paris`) + **required keywords** (`poker,betting`)
- **utm_allow_geo override**: override country rules per click via `?utm_allow_geo=FR`
- **No-cache mode**: force proxies/browsers to bypass caches
- **Fast mode**: skip IP network lookups for minimum latency
- **Platform review-infra blocking**: denies traffic from ad-platform networks (Meta AS32934, Google AS15169/36040, ByteDance AS396986, Microsoft, Apple, X, Pinterest, Yahoo)
- **Reverse-DNS crawler verification**: a claimed Googlebot/facebookexternalhit must resolve to the platform's crawler domains, otherwise flagged as an impersonator
- **In-app referer exemption**: Meta/TikTok in-app clicks are exempt from empty-referer denials
- **Request log** with stored reject reasons, per-source breakdown, campaign/source/reason filters, block-rate chart
- **Reject actions**: white page or HTTP error (403/404/410/429/451)
- **Security**: CSRF, login rate limiting, hardened sessions, authenticated diagnostics, spoof-proof client IP, and fail-closed secret persistence

## Installation

### Quick start (Apache / PHP 8.4+)

1. Copy files to your web root
2. Move mutable state out of the deployed app tree or set `APP_RUNTIME_DIR` to a root-owned runtime path such as `/srv/cloaking/runtime`
3. Ensure the runtime directory and logs are writable by the PHP user only
4. Set a real admin password (recommended before going live):
   ```bash
   php install.php --username=admin --password-stdin < /secure/admin-password
   ```
5. Access the admin panel over TLS at `https://yoursite.com/admin/`

There is no default administrator. The CLI installer creates the first account
exactly once; an uninitialized web request never creates credentials.

### Docker

```bash
cp ops/config.local.php.example /srv/cloaking/config/config.local.php
export CLOAKING_RUNTIME_PATH=/srv/cloaking/runtime
export CLOAKING_CONFIG_PATH=/srv/cloaking/config/config.local.php
docker compose up -d
docker compose exec web php bin/migrate.php --db=/srv/cloaking/runtime/cloaking.sqlite
docker compose exec -T web php install.php --username=admin --password-stdin < /secure/admin-password
```

The `web` container is private by default. Do not publish port `8080` or `80` from the PHP container directly. Terminate TLS at the edge proxy and attach it to the private app network only.

Recommended host layout on Hetzner:

1. Create `/srv/cloaking/runtime`, `/srv/cloaking/backups`, and `/srv/cloaking/config`
2. Copy [ops/config.local.php.example](/Users/nasir/Documents/GitHub/Cloaking/ops/config.local.php.example) to `/srv/cloaking/config/config.local.php` and set the real hostname
3. Keep the runtime paths in that config aligned with the paths your operational scripts use; the backup workflow now rejects missing or mismatched deployment config
4. Expose only the Caddy edge on `80/tcp` and `443/tcp`
5. Keep the Docker network between Caddy and PHP private

Before every deploy or migration, run:

```bash
bash ops/backup_sqlite.sh \
  --db=/srv/cloaking/runtime/cloaking.sqlite \
  --app-key=/srv/cloaking/runtime/app.key \
  --config=/srv/cloaking/config/config.local.php \
  --output=/srv/cloaking/backups/$(date -u +%Y%m%dT%H%M%SZ) \
  --migration-target=3 \
  --source-commit="$SOURCE_COMMIT" \
  --image-digest="$IMAGE_DIGEST"
```

The backup command fails closed if `--config` is omitted, unreadable, or points
at different runtime paths than the database and app key you asked it to back
up.

Then rehearse that backup with:

```bash
bash ops/restore_rehearsal.sh \
  --backup=/srv/cloaking/backups/20260904T000000Z \
  --evidence-dir=ops/rehearsals/20260904T000000Z \
  --admin-username=owner \
  --admin-password-stdin \
  --expected-source-commit="$SOURCE_COMMIT" \
  --expected-image-digest="$IMAGE_DIGEST" < /secure/admin-password
```

Before cutting a release tag, validate the release inputs from a clean checkout:

```bash
php ops/validate_release_inputs.php
```

The checked-in
[LEGAL_PLATFORM_REVIEW.md](/Users/nasir/Documents/GitHub/Cloaking/LEGAL_PLATFORM_REVIEW.md)
stays pending by default. Release mechanics only pass when a protected CI
environment injects `LEGAL_APPROVAL_ATTESTATION` that matches the review file
digest. Editing the markdown alone is not enough.

The published-phase validator also fails closed until release metadata exists,
the referenced SBOM artifact exists, and the claimed git tag resolves to the
claimed current release commit.

### Nginx

Use `nginx.conf` as a template for your server block.

### Hetzner + Caddy

Use [ops/Caddyfile.example](/Users/nasir/Documents/GitHub/Cloaking/ops/Caddyfile.example) as the TLS edge template. On a Hetzner host:

1. Put the PHP app on a private Docker network or bind it to `127.0.0.1:18080`
2. Configure the Hetzner Firewall to allow inbound `22/tcp`, `80/tcp`, and `443/tcp` only
3. Point your app hostname and custom short-link hostnames at the server
4. Start Caddy with the public hostnames already present in DNS so it can request certificates
5. Reload Caddy after adding each new custom domain so it can complete ACME for that hostname

Custom-domain certificates are not automatic unless the hostname is present in the active Caddy config and already resolves to the server. Add the hostname to Caddy first, confirm DNS, then reload Caddy to mint the certificate before sending traffic.

When you store restore evidence in git, keep it evidence-only: summaries,
redacted smoke outputs, and checksum manifests are fine; committed SQLite files,
app keys, and reusable runtime configs are not.

### Local development

```bash
php -S 127.0.0.1:8080 dev-router.php
```

## Concepts

- **Link** — a cloaked URL entry point (`/slug`), optionally bound to a campaign and/or custom domain
- **Campaign** — a reusable container of rules + actions (offer URL, white page, reject mode). Multiple links can share one campaign; edit once, applies everywhere
- **Domain** — the hostname links resolve on. System domain (your main host) or custom domains via CNAME
- **Client** — a generated `index.php` you upload to your own landing server; it calls `/api/verify` and shows the offer/safe page per the campaign rules

## Usage

### Creating a cloaked link

1. Admin → **Links**
2. Slug (optional), name, domain (optional)
3. Either bind a **Campaign** (rules + actions live there) or fill in link-local offer URL, white page, and rules
4. Filters are grouped in collapsible sections: bots, countries, clients, devices/OS, languages, referrers, URL params, fingerprint

### Campaigns

1. Admin → **Campaigns** → New Campaign
2. Pick a **preset** (Facebook mobile / TikTok mobile / Google desktop) to prefill rules, or configure manually
3. Set the offer URL, reject action (white page or HTTP error), redirect type
4. Bind links to the campaign in the Links page

### Testing

Each link has an authenticated **Diagnostics** action. It submits a CSRF-protected
POST from the admin Links page; public link URLs do not accept diagnostic tokens.

### Client-deployment mode

1. Admin → **Client Mode** → pick a campaign → **Generate Client**
2. Download `index.php` and `tracker.min.js`, upload both to your landing server
3. Visitors are verified against `/api/verify`; the client delivers a validated HTTP(S) offer URL or shows the safe page / error per the campaign

The client's API key is embedded in a **server-side PHP file** — never expose it in public frontend code.

### Multi-domain (system + custom domains)

The **system domain** is your app's own hostname; links without a domain resolve there.

For custom domains:

1. Admin → **Domains** → add e.g. `s.example.com`
2. At your DNS provider, CNAME `s.example.com` → your server hostname (or A record → server IP)
3. Make your web server accept the hostname:
   - **Nginx**: one server block per custom domain reusing `nginx.conf`, or a `*.yourdomain.com` wildcard (see comments in the file)
   - **Caddy/Traefik**: add the hostname to the edge config, confirm DNS points to the server, then reload the proxy so ACME can issue the certificate
   - **Apache**: add a `ServerAlias` to your vhost
4. Create links and select the domain — they resolve as `https://s.example.com/slug`

Note: links without a domain selected only resolve on the system domain; links on a custom domain only resolve on that domain.

### API

Authenticate with `Authorization: Bearer {api_key}` (header only).

```bash
# Links
curl -H "Authorization: Bearer KEY" https://yoursite.com/api/links
curl -X POST -H "Authorization: Bearer KEY" -H "Content-Type: application/json" \
  -d '{"name":"My Link","offer_url":"https://example.com/offer","slug":"my-link","campaign_id":1,"domain_id":2}' \
  https://yoursite.com/api/links

# Campaigns (CRUD + clone)
curl -H "Authorization: Bearer KEY" https://yoursite.com/api/campaigns
curl -X POST -H "Authorization: Bearer KEY" -H "Content-Type: application/json" \
  -d '{"name":"TikTok mobile","offer_url":"https://example.com/offer","allowed_clients":"tiktok","allowed_devices":"mobile"}' \
  https://yoursite.com/api/campaigns
curl -X POST -H "Authorization: Bearer KEY" https://yoursite.com/api/campaigns/1/clone

# Domains
curl -X POST -H "Authorization: Bearer KEY" -H "Content-Type: application/json" \
  -d '{"domain":"s.example.com"}' https://yoursite.com/api/domains

# Verify (client mode)
curl -X POST -H "Authorization: Bearer KEY" -H "Content-Type: application/json" \
  -d '{"campaign_id":1,"ip":"1.2.3.4","user_agent":"...","referer":"https://www.facebook.com/","language":"en-US","params":{"utm_source":"fb"}}' \
  https://yoursite.com/api/verify
```

## Configuration

Edit `config.local.php` (copy `config.local.example.php`) to override:

- `APP_RUNTIME_DIR` — root-owned runtime directory outside the immutable app tree
- `DB_PATH` — database location
- `APP_BASE_URL` / `SYSTEM_HOSTS` — canonical app hostname and system-owned short-link hosts
- `TRUSTED_PROXIES` — proxies allowed to set forwarding headers (Cloudflare etc.)
- `ENABLE_TOR_CHECK` — toggle Tor DNSBL lookups
- `IP_INTELLIGENCE_ENDPOINT` / `IP_INTELLIGENCE_API_KEY` — reviewed authenticated HTTPS or local adapter
- `IP_INTELLIGENCE_FAILURE_MODE` — `closed` by default; explicit `open` requires recorded risk acceptance
- `LOGIN_MAX_ATTEMPTS` / `LOGIN_WINDOW_SECONDS` — login rate limiting
- `LOG_RETENTION_DAYS` — hit-log retention (pruned automatically)

The app key is generated atomically with mode `0600` in `APP_RUNTIME_DIR/app.key` on first run unless you set it explicitly.

## Security notes

- Create the first administrator through `install.php --password-stdin`; there is no default password
- Serve over HTTPS; visitor-token issuance fails closed without it
- Keep `config.local.php`, `app.key`, the SQLite database, and backups out of the public docroot
- `data/`, `logs/`, and `includes/` are denied by `.htaccess` / `nginx.conf`
- Never put your API key in URLs — the API only accepts the Authorization header
- Client-mode API keys live in server-side PHP files only
- Rotate API keys from **Settings** when needed
- Complete the privacy and vendor controls in [docs/IP_INTELLIGENCE.md](/Users/nasir/Documents/GitHub/Cloaking/docs/IP_INTELLIGENCE.md) before enabling IP intelligence

Vulnerability handling and release secret expectations live in
[SECURITY.md](/Users/nasir/Documents/GitHub/Cloaking/SECURITY.md).

## Release gates

- CI runs from a clean checkout, lints PHP, runs the full PHP test suite, verifies pinned image references, validates Docker Compose, rebuilds the production image without cache, exercises Apache/Nginx/Caddy integration checks, generates an SBOM, and scans the image for high and critical vulnerabilities.
- Protected release tags enter the protected `legal-approval` environment, validate the external legal attestation and git tag provenance before any GHCR push, then publish the image digest and post-push metadata.
- The release stays blocked until the protected `LEGAL_APPROVAL_ATTESTATION` matches the legal review artifact digest and the published metadata proves the immutable tag, commit, image digest, and SBOM artifact together.

## File structure

```
Cloaking/
├── admin/                    # Admin panel
│   ├── dashboard.php         # Stats, block-rate chart, request log + filters
│   ├── links.php             # Link management (campaign/domain binding)
│   ├── campaigns.php         # Campaigns: rules, actions, presets, clone
│   ├── domains.php           # Multi-domain management
│   ├── client.php            # Client-deployment code generator
│   ├── login.php / settings.php / index.php / _nav.php
├── api/index.php             # REST API (+ /api/verify)
├── assets/
│   ├── css/admin.css
│   └── js/tracker.js         # Fingerprint collector (interstitial + client mode)
├── includes/
│   ├── bootstrap.php         # Errors, security headers, sessions
│   ├── security.php          # CSRF, rate limiting, validation
│   ├── auth.php              # Admin auth helpers
│   ├── database.php          # Schema, indexes, migrations, pruning
│   ├── bot_detector.php      # Detection + rule evaluation (with reasons)
│   ├── rules.php             # Effective rules, wildcards, tiers, presets
│   ├── rule_fields.php       # Shared admin rule form
│   └── client_template.php.txt  # Client-deployment template
├── config.php / config.local.example.php
├── index.php                 # Cloaking engine (public)
├── install.php / dev-router.php
├── .htaccess / nginx.conf
├── Dockerfile / docker-compose.yml / docker/
└── README.md
```

## License

Private use only.
