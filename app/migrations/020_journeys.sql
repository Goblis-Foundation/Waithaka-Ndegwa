-- A single editorial guard serializes publication decisions, including first insert.
-- This is application state, not a sample venture or a public content seed.
CREATE TABLE IF NOT EXISTS journey_editorial_locks (
  lock_name VARCHAR(64) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO journey_editorial_locks(lock_name) VALUES ('active-season');

CREATE TABLE IF NOT EXISTS journey_seasons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(160) NOT NULL UNIQUE,
  title VARCHAR(180) NOT NULL,
  venture_name VARCHAR(180) NOT NULL,
  summary TEXT NOT NULL,
  baseline TEXT NOT NULL,
  learning_question TEXT NULL,
  outcomes TEXT NULL,
  private_notes LONGTEXT NULL,
  started_on DATE NULL,
  phase VARCHAR(24) NOT NULL DEFAULT 'planning',
  publication_status VARCHAR(24) NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_journey_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_journey_publication (publication_status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journey_episodes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  season_id BIGINT UNSIGNED NOT NULL,
  post_id BIGINT UNSIGNED NOT NULL,
  episode_order INT UNSIGNED NOT NULL,
  public_summary VARCHAR(600) NULL,
  evidence_basis VARCHAR(40) NOT NULL DEFAULT 'author_experience',
  evidence_summary TEXT NULL,
  private_evidence_notes LONGTEXT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_journey_episode_order (season_id, episode_order),
  UNIQUE KEY uq_journey_episode_post (season_id, post_id),
  CONSTRAINT fk_episode_season FOREIGN KEY (season_id) REFERENCES journey_seasons(id) ON DELETE CASCADE,
  CONSTRAINT fk_episode_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS founder_submissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(40) NOT NULL UNIQUE,
  submission_type VARCHAR(20) NOT NULL,
  submitter_name VARCHAR(120) NOT NULL,
  submitter_email VARCHAR(254) NOT NULL,
  founder_name VARCHAR(120) NOT NULL,
  venture_name VARCHAR(180) NOT NULL,
  country VARCHAR(120) NOT NULL,
  story_summary TEXT NOT NULL,
  claimed_metrics TEXT NULL,
  metric_basis VARCHAR(40) NOT NULL,
  private_evidence_notes TEXT NULL,
  founder_publication_permission TINYINT(1) NOT NULL DEFAULT 0,
  privacy_version VARCHAR(40) NOT NULL,
  privacy_accepted_at DATETIME NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  review_status VARCHAR(24) NOT NULL DEFAULT 'new',
  review_notes LONGTEXT NULL,
  consent_reference VARCHAR(500) NULL,
  permission_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  claims_reviewed TINYINT(1) NOT NULL DEFAULT 0,
  associated_post_id BIGINT UNSIGNED NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_founder_post FOREIGN KEY (associated_post_id) REFERENCES posts(id) ON DELETE SET NULL,
  CONSTRAINT fk_founder_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_founder_review (review_status, created_at),
  INDEX idx_founder_public (is_public, associated_post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL UNIQUE,
  name VARCHAR(120) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  consent_version VARCHAR(40) NOT NULL,
  requested_at DATETIME NOT NULL,
  confirmed_at DATETIME NULL,
  unsubscribed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_newsletter_status (status, confirmed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  purpose VARCHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_newsletter_token_subscriber FOREIGN KEY (subscriber_id) REFERENCES newsletter_subscribers(id) ON DELETE CASCADE,
  INDEX idx_newsletter_token_owner (subscriber_id, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journey_rate_limits (
  bucket_key CHAR(64) PRIMARY KEY,
  purpose VARCHAR(40) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 1,
  expires_at DATETIME NOT NULL,
  INDEX idx_journey_rate_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journey_audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(32) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_journey_audit_entity (entity_type, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
