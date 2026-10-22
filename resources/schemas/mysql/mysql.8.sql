ALTER TABLE uploads ADD COLUMN game VARCHAR(255), ADD COLUMN description TEXT, ADD COLUMN recorded_at VARCHAR(32);
CREATE TABLE IF NOT EXISTS sso_identities (
 identity_key CHAR(64) PRIMARY KEY,
 user_id INT NOT NULL,
 issuer TEXT NOT NULL,
 subject TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS preview_jobs (
 upload_id INT PRIMARY KEY,
 attempts INT NOT NULL DEFAULT 0,
 error TEXT,
 finished INT NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS upload_receipts (
 receipt_key CHAR(64) PRIMARY KEY,
 response TEXT NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
