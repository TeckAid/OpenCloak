-- 005: reusable filter lists, per-IP daily caps, warm-up bypass,
-- browser rules, IP blocklists, hit_log browser column.

CREATE TABLE IF NOT EXISTS filter_lists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    list_type TEXT NOT NULL DEFAULT 'black',
    list_ips TEXT DEFAULT '',
    list_agents TEXT DEFAULT '',
    list_providers TEXT DEFAULT '',
    list_referers TEXT DEFAULT '',
    is_deleted INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_filter_lists_user ON filter_lists(user_id);

CREATE TABLE IF NOT EXISTS ip_daily (
    scope TEXT NOT NULL,
    ip_hash TEXT NOT NULL,
    day TEXT NOT NULL,
    count INTEGER DEFAULT 0,
    PRIMARY KEY (scope, ip_hash, day)
);

ALTER TABLE links ADD COLUMN allowed_browsers TEXT DEFAULT '';
ALTER TABLE links ADD COLUMN blocked_browsers TEXT DEFAULT '';
ALTER TABLE links ADD COLUMN ip_blocklist TEXT DEFAULT '';
ALTER TABLE links ADD COLUMN ip_clicks_per_day INTEGER DEFAULT 0;
ALTER TABLE links ADD COLUMN clicks_before_filtering INTEGER DEFAULT 0;
ALTER TABLE links ADD COLUMN filter_id INTEGER DEFAULT 0;

ALTER TABLE campaigns ADD COLUMN allowed_browsers TEXT DEFAULT '';
ALTER TABLE campaigns ADD COLUMN blocked_browsers TEXT DEFAULT '';
ALTER TABLE campaigns ADD COLUMN ip_blocklist TEXT DEFAULT '';
ALTER TABLE campaigns ADD COLUMN ip_clicks_per_day INTEGER DEFAULT 0;
ALTER TABLE campaigns ADD COLUMN clicks_before_filtering INTEGER DEFAULT 0;
ALTER TABLE campaigns ADD COLUMN filter_id INTEGER DEFAULT 0;

ALTER TABLE hit_log ADD COLUMN browser TEXT DEFAULT '';
