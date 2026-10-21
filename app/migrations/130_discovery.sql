-- Public URL history contains only URLs that have actually passed public gates.
-- All discovery timestamps are UTC; legacy content timestamps remain Nairobi time.
CREATE TABLE IF NOT EXISTS discovery_public_urls (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  url_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  canonical_url VARCHAR(2048) NOT NULL,
  source_key VARCHAR(200) NOT NULL,
  content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  seen_run CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  first_public_at DATETIME NOT NULL,
  changed_at DATETIME NOT NULL,
  seen_at DATETIME NOT NULL,
  INDEX idx_discovery_visible (is_public,seen_run)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discovery_indexnow_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_url_id BIGINT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL,
  event_type VARCHAR(16) NOT NULL,
  canonical_url VARCHAR(2048) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  failure_count INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL,
  attempted_at DATETIME NULL,
  accepted_at DATETIME NULL,
  last_http_status SMALLINT UNSIGNED NULL,
  error_code VARCHAR(60) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_discovery_queue_public FOREIGN KEY(public_url_id) REFERENCES discovery_public_urls(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_discovery_revision (public_url_id,revision),
  INDEX idx_discovery_due (status,available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discovery_indexnow_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  queue_id BIGINT UNSIGNED NOT NULL,
  attempt_number INT UNSIGNED NOT NULL,
  result VARCHAR(32) NOT NULL DEFAULT 'started',
  http_status SMALLINT UNSIGNED NULL,
  error_code VARCHAR(60) NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  CONSTRAINT fk_discovery_attempt_queue FOREIGN KEY(queue_id) REFERENCES discovery_indexnow_queue(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_discovery_attempt (queue_id,attempt_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discovery_gsc_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  connection_id BIGINT UNSIGNED NOT NULL,
  connection_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  property_url VARCHAR(500) NOT NULL,
  days SMALLINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  previous_start DATE NOT NULL,
  previous_end DATE NOT NULL,
  status VARCHAR(20) NOT NULL,
  payload_json LONGTEXT NULL,
  error_code VARCHAR(60) NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  INDEX idx_discovery_gsc_binding (connection_id,connection_fingerprint,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
