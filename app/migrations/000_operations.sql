CREATE TABLE IF NOT EXISTS app_sessions (
 id VARCHAR(128) CHARACTER SET ascii PRIMARY KEY, payload MEDIUMTEXT NOT NULL, expires_at BIGINT NOT NULL, INDEX idx_session_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS email_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, dedupe_key CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE, payload LONGTEXT NOT NULL,
 status ENUM('pending','sending','sent','ambiguous','failed','suppressed') NOT NULL DEFAULT 'pending', attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, claimed_at DATETIME NULL, provider_id VARCHAR(255) NULL, error_code VARCHAR(80) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, sent_at DATETIME NULL, INDEX idx_email_work(status,available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
