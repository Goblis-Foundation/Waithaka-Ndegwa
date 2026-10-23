CREATE TABLE IF NOT EXISTS google_calendar_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 google_sub VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 display_name VARCHAR(255) NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'pending_confirmation',
 credentials_encrypted LONGTEXT NULL,
 scopes_json TEXT NOT NULL,
 calendars_json LONGTEXT NOT NULL,
 selected_json TEXT NOT NULL,
 verified_at DATETIME NOT NULL,
 last_read_at DATETIME NULL,
 UNIQUE KEY uq_google_calendar_owner(user_id,google_sub),
 CONSTRAINT fk_google_calendar_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS google_report_snapshots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 report_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 destination_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_json LONGTEXT NOT NULL,
 observed_at DATETIME NOT NULL,
 INDEX idx_google_report_owner(user_id,report_key,observed_at),
 CONSTRAINT fk_google_report_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
