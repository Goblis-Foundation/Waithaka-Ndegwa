ALTER TABLE email_outbox
  ADD COLUMN feedback_status VARCHAR(24) NULL,
  ADD COLUMN feedback_at DATETIME NULL,
  ADD INDEX idx_email_provider(provider_id);

CREATE TABLE IF NOT EXISTS mail_suppressions (
  email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  email_encrypted TEXT NOT NULL,
  reason VARCHAR(30) NOT NULL,
  outbox_id BIGINT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_feedback_events (
  message_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  topic_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_type VARCHAR(32) NOT NULL,
  provider_id VARCHAR(255) NOT NULL DEFAULT '',
  outbox_id BIGINT UNSIGNED NULL,
  recipient_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  action VARCHAR(40) NOT NULL,
  source_at DATETIME NOT NULL,
  received_at DATETIME NOT NULL,
  INDEX idx_mail_feedback_outbox(outbox_id,received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_feedback_subscriptions (
  topic_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  token_encrypted TEXT NULL,
  requested_at DATETIME NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  confirmed_at DATETIME NULL,
  subscription_arn VARCHAR(1024) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
