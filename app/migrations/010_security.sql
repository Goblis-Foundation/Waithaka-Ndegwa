CREATE TABLE IF NOT EXISTS post_drafts (
  post_id BIGINT UNSIGNED PRIMARY KEY,
  payload LONGTEXT NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_draft_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_draft_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_provider_ids (
  provider VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  identifier_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  external_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  contribution_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (provider, identifier_type, external_id),
  INDEX idx_payment_id_contribution (contribution_id),
  CONSTRAINT fk_payment_id_contribution FOREIGN KEY (contribution_id) REFERENCES contributions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_reconciliation (
  contribution_id BIGINT UNSIGNED PRIMARY KEY,
  provider_environment VARCHAR(16) NULL,
  state VARCHAR(24) NOT NULL DEFAULT 'pending',
  verification_level VARCHAR(32) NOT NULL DEFAULT 'none',
  detail VARCHAR(500) NULL,
  callback_payload LONGTEXT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_checked_at DATETIME NULL,
  next_attempt_at DATETIME NULL,
  verified_at DATETIME NULL,
  lease_token CHAR(32) NULL,
  lease_until DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_payment_reconcile_due (state,next_attempt_at),
  CONSTRAINT fk_payment_reconcile_contribution FOREIGN KEY (contribution_id) REFERENCES contributions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  contribution_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(16) NOT NULL,
  event_source VARCHAR(32) NOT NULL,
  outcome VARCHAR(32) NOT NULL,
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_payment_event_contribution (contribution_id,created_at),
  CONSTRAINT fk_payment_event_contribution FOREIGN KEY (contribution_id) REFERENCES contributions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
