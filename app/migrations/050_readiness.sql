-- Worker monitoring only. All timestamps below are written explicitly in UTC.
CREATE TABLE IF NOT EXISTS worker_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  state VARCHAR(20) NOT NULL,
  stage VARCHAR(24) NOT NULL,
  started_at DATETIME NOT NULL,
  heartbeat_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  counts_json TEXT NULL,
  error_code VARCHAR(40) NULL,
  INDEX idx_worker_recent (started_at,id),
  INDEX idx_worker_state (state,heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
