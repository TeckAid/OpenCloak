CREATE UNIQUE INDEX IF NOT EXISTS idx_campaigns_id_user_unique ON campaigns(id, user_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_domains_id_user_unique ON domains(id, user_id);

DROP TABLE IF EXISTS links_new;

CREATE TABLE links_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    slug TEXT UNIQUE NOT NULL,
    name TEXT DEFAULT '',
    campaign_id INTEGER,
    domain_id INTEGER,
    offer_url TEXT NOT NULL,
    white_page TEXT,
    is_active INTEGER DEFAULT 1,
    offer_urls TEXT DEFAULT '',
    rotation_mode TEXT DEFAULT 'single',
    offer_routes TEXT DEFAULT '',
    offer_method TEXT DEFAULT 'redirect',
    forward_utms INTEGER DEFAULT 0,
    no_cache INTEGER DEFAULT 0,
    fast_mode INTEGER DEFAULT 0,
    delay_start INTEGER DEFAULT 0,
    delay_permanent INTEGER DEFAULT 0,
    blocked_url_params TEXT DEFAULT '',
    required_url_keywords TEXT DEFAULT '',
    allow_geo_override INTEGER DEFAULT 0,
    block_bots INTEGER DEFAULT 1,
    block_datacenters INTEGER DEFAULT 1,
    block_review_infra INTEGER DEFAULT 1,
    block_vpn INTEGER DEFAULT 0,
    block_tor INTEGER DEFAULT 1,
    block_headless INTEGER DEFAULT 1,
    block_curl INTEGER DEFAULT 1,
    allowed_countries TEXT DEFAULT '',
    blocked_countries TEXT DEFAULT '',
    allowed_clients TEXT DEFAULT '',
    blocked_clients TEXT DEFAULT '',
    allowed_devices TEXT DEFAULT '',
    blocked_devices TEXT DEFAULT '',
    allowed_os TEXT DEFAULT '',
    blocked_os TEXT DEFAULT '',
    os_min_versions TEXT DEFAULT '',
    allowed_languages TEXT DEFAULT '',
    blocked_languages TEXT DEFAULT '',
    blocked_referrers TEXT DEFAULT '',
    allowed_referrers TEXT DEFAULT '',
    allow_empty_referer INTEGER DEFAULT 1,
    required_url_params TEXT DEFAULT '',
    allowed_resolutions TEXT DEFAULT '',
    blocked_resolutions TEXT DEFAULT '',
    require_screen_info INTEGER DEFAULT 0,
    single_visit_only INTEGER DEFAULT 0,
    redirect_type TEXT DEFAULT '302',
    redirect_delay INTEGER DEFAULT 0,
    reject_mode TEXT DEFAULT 'white',
    reject_code INTEGER DEFAULT 403,
    total_hits INTEGER DEFAULT 0,
    offer_shows INTEGER DEFAULT 0,
    white_shows INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id, user_id) REFERENCES campaigns(id, user_id) ON DELETE RESTRICT,
    FOREIGN KEY (domain_id, user_id) REFERENCES domains(id, user_id) ON DELETE RESTRICT
);

INSERT INTO links_new (
    id, user_id, slug, name, campaign_id, domain_id, offer_url, white_page, is_active,
    offer_urls, rotation_mode, offer_routes, offer_method, forward_utms, no_cache,
    fast_mode, delay_start, delay_permanent, blocked_url_params, required_url_keywords,
    allow_geo_override, block_bots, block_datacenters, block_review_infra, block_vpn,
    block_tor, block_headless, block_curl, allowed_countries, blocked_countries,
    allowed_clients, blocked_clients, allowed_devices, blocked_devices, allowed_os,
    blocked_os, os_min_versions, allowed_languages, blocked_languages, blocked_referrers,
    allowed_referrers, allow_empty_referer, required_url_params, allowed_resolutions,
    blocked_resolutions, require_screen_info, single_visit_only, redirect_type,
    redirect_delay, reject_mode, reject_code, total_hits, offer_shows, white_shows,
    created_at, updated_at
)
SELECT
    id, user_id, slug, name, campaign_id, domain_id, offer_url, white_page, is_active,
    offer_urls, rotation_mode, offer_routes, offer_method, forward_utms, no_cache,
    fast_mode, delay_start, delay_permanent, blocked_url_params, required_url_keywords,
    allow_geo_override, block_bots, block_datacenters, block_review_infra, block_vpn,
    block_tor, block_headless, block_curl, allowed_countries, blocked_countries,
    allowed_clients, blocked_clients, allowed_devices, blocked_devices, allowed_os,
    blocked_os, os_min_versions, allowed_languages, blocked_languages, blocked_referrers,
    allowed_referrers, allow_empty_referer, required_url_params, allowed_resolutions,
    blocked_resolutions, require_screen_info, single_visit_only, redirect_type,
    redirect_delay, reject_mode, reject_code, total_hits, offer_shows, white_shows,
    created_at, updated_at
FROM links;

DROP TABLE links;
ALTER TABLE links_new RENAME TO links;

DROP TABLE IF EXISTS delay_ips_new;

CREATE TABLE delay_ips_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id INTEGER,
    campaign_id INTEGER,
    ip_hash TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    CHECK (
        (link_id IS NOT NULL AND campaign_id IS NULL)
        OR (link_id IS NULL AND campaign_id IS NOT NULL)
    )
);

INSERT INTO delay_ips_new (id, link_id, campaign_id, ip_hash, created_at)
SELECT id, link_id, campaign_id, ip_hash, created_at
FROM delay_ips;

DROP TABLE delay_ips;
ALTER TABLE delay_ips_new RENAME TO delay_ips;

CREATE INDEX IF NOT EXISTS idx_campaigns_user ON campaigns(user_id);
CREATE INDEX IF NOT EXISTS idx_links_user ON links(user_id);
CREATE INDEX IF NOT EXISTS idx_links_domain ON links(domain_id, slug);
CREATE INDEX IF NOT EXISTS idx_hit_log_link_created ON hit_log(link_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_hit_log_created ON hit_log(created_at);
CREATE INDEX IF NOT EXISTS idx_hit_log_campaign ON hit_log(campaign_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_delay_ips ON delay_ips(campaign_id, link_id, ip_hash);
CREATE UNIQUE INDEX IF NOT EXISTS idx_delay_ips_campaign_ip_unique ON delay_ips(campaign_id, ip_hash) WHERE campaign_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_delay_ips_link_ip_unique ON delay_ips(link_id, ip_hash) WHERE link_id IS NOT NULL;
