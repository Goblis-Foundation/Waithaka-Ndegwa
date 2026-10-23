-- Private working copies and explicit public snapshots. No example venture or result is seeded.
CREATE TABLE IF NOT EXISTS editorial_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_type VARCHAR(24) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  draft_json LONGTEXT NOT NULL,
  public_json LONGTEXT NULL,
  publication_status VARCHAR(24) NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  public_modified_at DATETIME NULL,
  public_journey_id BIGINT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_editorial_slug (item_type,slug),
  INDEX idx_editorial_public (item_type,publication_status,published_at),
  INDEX idx_editorial_journey (public_journey_id,publication_status,published_at),
  CONSTRAINT fk_editorial_journey FOREIGN KEY (public_journey_id) REFERENCES journey_seasons(id) ON DELETE SET NULL,
  CONSTRAINT fk_editorial_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_editorial_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS editorial_post_meta (
  post_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  draft_json LONGTEXT NOT NULL,
  public_json LONGTEXT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_editorial_meta_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_editorial_meta_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS editorial_post_updates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  update_kind VARCHAR(24) NOT NULL,
  note TEXT NOT NULL,
  published_at DATETIME NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  INDEX idx_editorial_post_updates (post_id,published_at,id),
  CONSTRAINT fk_editorial_update_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_editorial_update_author FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
