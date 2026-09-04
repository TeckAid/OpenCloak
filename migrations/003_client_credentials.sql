CREATE TABLE IF NOT EXISTS client_credentials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    campaign_id INTEGER NOT NULL,
    credential_hash TEXT UNIQUE NOT NULL,
    scope TEXT NOT NULL DEFAULT 'verify',
    status TEXT NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id, user_id) REFERENCES campaigns(id, user_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_client_credentials_campaign ON client_credentials(campaign_id, status, expires_at DESC);
CREATE INDEX IF NOT EXISTS idx_client_credentials_hash ON client_credentials(credential_hash);
