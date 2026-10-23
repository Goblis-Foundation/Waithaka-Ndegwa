CREATE TABLE IF NOT EXISTS publishing_connections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service VARCHAR(60) NOT NULL UNIQUE,
  provider VARCHAR(30) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'disconnected',
  external_id VARCHAR(255) NULL,
  display_name VARCHAR(255) NULL,
  credentials_encrypted LONGTEXT NULL,
  scopes_json TEXT NULL,
  resources_json LONGTEXT NULL,
  metadata_json LONGTEXT NULL,
  selected_resource VARCHAR(500) NULL,
  verified_at DATETIME NULL,
  connected_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publishing_channels (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  profile_url VARCHAR(500) NOT NULL,
  account_type VARCHAR(40) NOT NULL,
  delivery_method VARCHAR(20) NOT NULL DEFAULT 'manual',
  buffer_channel_id VARCHAR(255) NULL,
  buffer_mode VARCHAR(20) NOT NULL DEFAULT 'automatic',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO publishing_channels(slug,name,profile_url,account_type) VALUES
('linkedin','LinkedIn','https://www.linkedin.com/in/iwaithakaw/','member'),
('facebook','Facebook','https://www.facebook.com/iwaithakaw','professional_profile'),
('instagram','Instagram','https://www.instagram.com/iwaithakaw','professional'),
('x','X','https://x.com/iwaithakaw','professional'),
('youtube','YouTube','https://www.youtube.com/@waithakandegwa','channel'),
('tiktok','TikTok','https://www.tiktok.com/@waithakandegwa','personal');

CREATE TABLE IF NOT EXISTS publishing_oauth_states (
  state_hash CHAR(64) PRIMARY KEY,
  service VARCHAR(60) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_hash CHAR(64) NOT NULL,
  verifier_encrypted TEXT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  INDEX idx_publishing_oauth_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publishing_packages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(240) NOT NULL,
  source_post_id BIGINT UNSIGNED NULL,
  draft_json LONGTEXT NOT NULL,
  draft_version INT UNSIGNED NOT NULL DEFAULT 1,
  approved_revision_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_publishing_packages_updated(updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publishing_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_id BIGINT UNSIGNED NOT NULL,
  draft_version INT UNSIGNED NOT NULL,
  snapshot_json LONGTEXT NOT NULL,
  content_hash CHAR(64) NOT NULL,
  approved_by BIGINT UNSIGNED NOT NULL,
  approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_publishing_revision_package FOREIGN KEY(package_id) REFERENCES publishing_packages(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_publishing_approval(package_id,draft_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publishing_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  revision_id BIGINT UNSIGNED NOT NULL,
  channel_id BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NULL,
  connection_external_id VARCHAR(255) NULL,
  destination_json TEXT NOT NULL,
  job_key CHAR(64) NOT NULL UNIQUE,
  method VARCHAR(20) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
  scheduled_at DATETIME NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_at DATETIME NULL,
  next_check_at DATETIME NULL,
  remote_id VARCHAR(255) NULL,
  external_url VARCHAR(1000) NULL,
  provider_state_json TEXT NULL,
  last_error VARCHAR(500) NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_publishing_job_revision FOREIGN KEY(revision_id) REFERENCES publishing_revisions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_publishing_job_channel FOREIGN KEY(channel_id) REFERENCES publishing_channels(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_publishing_revision_channel(revision_id,channel_id),
  INDEX idx_publishing_due(status,scheduled_at),
  INDEX idx_publishing_check(status,next_check_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publishing_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_id BIGINT UNSIGNED NULL,
  job_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(60) NOT NULL,
  note VARCHAR(500) NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_publishing_events_package(package_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
