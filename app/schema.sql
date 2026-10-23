CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(140) NOT NULL UNIQUE,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  title VARCHAR(240) NOT NULL,
  slug VARCHAR(260) NOT NULL UNIQUE,
  standfirst TEXT NULL,
  content_html LONGTEXT NOT NULL,
  featured_image VARCHAR(500) NULL,
  featured_image_alt VARCHAR(500) NULL,
  featured_image_caption VARCHAR(500) NULL,
  video_url VARCHAR(1000) NULL,
  series_name VARCHAR(220) NULL,
  writer_note TEXT NULL,
  source_notes LONGTEXT NULL,
  travel_country VARCHAR(120) NULL,
  travel_city VARCHAR(120) NULL,
  seo_title VARCHAR(255) NULL,
  seo_description VARCHAR(320) NULL,
  status ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft',
  is_selected TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_posts_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  INDEX idx_posts_status_date (status, published_at),
  INDEX idx_posts_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(240) NOT NULL,
  standfirst TEXT NULL,
  content_html LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_revisions_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  INDEX idx_revisions_post (post_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(140) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_tags (
  post_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(post_id, tag_id),
  CONSTRAINT fk_posttags_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_posttags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(32) NOT NULL UNIQUE,
  first_name VARCHAR(120) NOT NULL,
  last_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone_code VARCHAR(12) NOT NULL,
  phone VARCHAR(60) NOT NULL,
  country VARCHAR(120) NOT NULL,
  city VARCHAR(120) NOT NULL,
  message TEXT NOT NULL,
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  email_sent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contact_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contributions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(40) NOT NULL UNIQUE,
  method ENUM('mpesa','pesapal','paypal') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  first_name VARCHAR(120) NULL,
  last_name VARCHAR(120) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(60) NULL,
  provider_reference VARCHAR(190) NULL,
  provider_payload LONGTEXT NULL,
  status ENUM('initiated','pending','completed','failed','cancelled') NOT NULL DEFAULT 'initiated',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_contrib_status_date (status, created_at),
  INDEX idx_contrib_provider (provider_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(190) PRIMARY KEY,
  setting_value LONGTEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NULL,
  ip_hash CHAR(64) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_ip_time (ip_hash, attempted_at),
  INDEX idx_login_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categories (name, slug, description, sort_order) VALUES
('Social Entrepreneurship','social-entrepreneurship','Experience and views on enterprise as a tool for social and economic change in Kenya and across East Africa.',10),
('Agribusiness','agribusiness','Agriculture, value chains, regenerative practice, food systems and rural enterprise in Kenya and across East Africa.',20),
('National Infrastructure','national-infrastructure','Transport, energy, water, digital systems, logistics and the foundations of development across Kenya and East Africa.',30),
('Innovation','innovation','Technology, research, new business models and practical approaches to complex problems in Kenya, East Africa and beyond.',40),
('Travel','travel','Places encountered, lessons observed and ideas challenged while travelling across East Africa and beyond.',50);

CREATE TABLE IF NOT EXISTS media_releases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(40) NOT NULL UNIQUE,
  public_token CHAR(64) NOT NULL UNIQUE,
  title VARCHAR(220) NOT NULL,
  context_note TEXT NULL,
  purpose_note TEXT NULL,
  allow_photo TINYINT(1) NOT NULL DEFAULT 1,
  allow_video TINYINT(1) NOT NULL DEFAULT 0,
  allow_audio TINYINT(1) NOT NULL DEFAULT 0,
  allow_quotes TINYINT(1) NOT NULL DEFAULT 0,
  requested_attribution ENUM('full_name','first_name','anonymous','signer_choice') NOT NULL DEFAULT 'signer_choice',
  status ENUM('draft','sent','signed','withdrawal_requested','closed') NOT NULL DEFAULT 'draft',
  expires_at DATETIME NULL,
  signer_name VARCHAR(190) NULL,
  signer_contact VARCHAR(190) NULL,
  signer_country VARCHAR(120) NULL,
  signer_capacity ENUM('self','parent_guardian') NOT NULL DEFAULT 'self',
  participant_name VARCHAR(190) NULL,
  signer_attribution ENUM('full_name','first_name','anonymous') NULL,
  consent_photo TINYINT(1) NULL,
  consent_video TINYINT(1) NULL,
  consent_audio TINYINT(1) NULL,
  consent_quotes TINYINT(1) NULL,
  consent_version VARCHAR(40) NULL,
  consent_snapshot LONGTEXT NULL,
  signature_text VARCHAR(190) NULL,
  signed_ip_hash CHAR(64) NULL,
  signed_user_agent VARCHAR(500) NULL,
  signed_at DATETIME NULL,
  withdrawal_requested_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_media_release_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_release_status_date (status, created_at),
  INDEX idx_release_signed (signed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_release_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  release_id BIGINT UNSIGNED NOT NULL,
  method ENUM('mpesa','bank') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  recipient_name VARCHAR(190) NULL,
  recipient_destination VARCHAR(190) NULL,
  institution VARCHAR(190) NULL,
  transaction_reference VARCHAR(190) NOT NULL,
  paid_at DATETIME NOT NULL,
  proof_path VARCHAR(500) NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_release_payment_release FOREIGN KEY (release_id) REFERENCES media_releases(id) ON DELETE CASCADE,
  CONSTRAINT fk_release_payment_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_release_payment_release (release_id, paid_at),
  INDEX idx_release_payment_reference (transaction_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_release_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  release_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  event_note VARCHAR(500) NULL,
  actor_type ENUM('writer','signer','system') NOT NULL DEFAULT 'system',
  actor_user_id BIGINT UNSIGNED NULL,
  ip_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_release_event_release FOREIGN KEY (release_id) REFERENCES media_releases(id) ON DELETE CASCADE,
  CONSTRAINT fk_release_event_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_release_event_release (release_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
