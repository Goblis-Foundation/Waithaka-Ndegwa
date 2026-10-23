-- Preserve every existing subscription and token; add campaign consent/suppression state.
ALTER TABLE newsletter_subscribers
  ADD COLUMN consent_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN suppressed_at DATETIME NULL,
  ADD COLUMN suppression_reason VARCHAR(40) NULL,
  ADD COLUMN suppression_note VARCHAR(500) NULL;

CREATE TABLE IF NOT EXISTS newsletter_campaigns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  subject VARCHAR(200) NOT NULL DEFAULT '',
  preheader VARCHAR(240) NOT NULL DEFAULT '',
  body_html MEDIUMTEXT NOT NULL,
  source_post_id BIGINT UNSIGNED NULL,
  draft_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  state_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  approved_revision_id BIGINT UNSIGNED NULL,
  scheduled_revision_id BIGINT UNSIGNED NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME NULL,
  audience_size INT UNSIGNED NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  CONSTRAINT fk_newsletter_campaign_source FOREIGN KEY (source_post_id) REFERENCES posts(id) ON DELETE SET NULL,
  CONSTRAINT fk_newsletter_campaign_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_newsletter_campaign_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_newsletter_campaign_due (status,scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id BIGINT UNSIGNED NOT NULL,
  draft_version BIGINT UNSIGNED NOT NULL,
  snapshot_json MEDIUMTEXT NOT NULL,
  content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NOT NULL,
  CONSTRAINT fk_newsletter_revision_campaign FOREIGN KEY (campaign_id) REFERENCES newsletter_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_newsletter_revision_actor FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_newsletter_revision_version (campaign_id,draft_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id BIGINT UNSIGNED NOT NULL,
  revision_id BIGINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NULL,
  email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  consent_revision BIGINT UNSIGNED NOT NULL,
  consent_version VARCHAR(40) NOT NULL,
  confirmed_at_snapshot DATETIME NOT NULL,
  delivery_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  outbox_id BIGINT UNSIGNED NULL UNIQUE,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  envelope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  provider_id VARCHAR(255) NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  queued_at DATETIME NULL,
  accepted_at DATETIME NULL,
  delivered_at DATETIME NULL,
  feedback_at DATETIME NULL,
  CONSTRAINT fk_newsletter_delivery_campaign FOREIGN KEY (campaign_id) REFERENCES newsletter_campaigns(id) ON DELETE RESTRICT,
  CONSTRAINT fk_newsletter_delivery_revision FOREIGN KEY (revision_id) REFERENCES newsletter_revisions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_newsletter_delivery_subscriber FOREIGN KEY (subscriber_id) REFERENCES newsletter_subscribers(id) ON DELETE SET NULL,
  CONSTRAINT fk_newsletter_delivery_outbox FOREIGN KEY (outbox_id) REFERENCES email_outbox(id) ON DELETE SET NULL,
  UNIQUE KEY uq_newsletter_recipient (campaign_id,subscriber_id),
  INDEX idx_newsletter_delivery_work (status,campaign_id,id),
  INDEX idx_newsletter_delivery_email (email_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id BIGINT UNSIGNED NULL,
  subscriber_id BIGINT UNSIGNED NULL,
  delivery_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(40) NOT NULL,
  note VARCHAR(500) NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_newsletter_event_campaign FOREIGN KEY (campaign_id) REFERENCES newsletter_campaigns(id) ON DELETE SET NULL,
  CONSTRAINT fk_newsletter_event_subscriber FOREIGN KEY (subscriber_id) REFERENCES newsletter_subscribers(id) ON DELETE SET NULL,
  CONSTRAINT fk_newsletter_event_delivery FOREIGN KEY (delivery_id) REFERENCES newsletter_deliveries(id) ON DELETE SET NULL,
  CONSTRAINT fk_newsletter_event_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_newsletter_event_campaign (campaign_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
