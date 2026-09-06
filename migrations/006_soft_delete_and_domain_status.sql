-- 006: soft delete + restore for links/campaigns/domains, domain DNS status.

ALTER TABLE links ADD COLUMN is_deleted INTEGER DEFAULT 0;
ALTER TABLE campaigns ADD COLUMN is_deleted INTEGER DEFAULT 0;
ALTER TABLE domains ADD COLUMN is_deleted INTEGER DEFAULT 0;
ALTER TABLE domains ADD COLUMN dns_status TEXT DEFAULT '';
ALTER TABLE domains ADD COLUMN dns_records TEXT DEFAULT '';
ALTER TABLE domains ADD COLUMN status_checked_at DATETIME;
