-- Final-copy approval is separate from general permission and editorial review.
ALTER TABLE posts ADD COLUMN founder_approval_hash CHAR(64) NULL;
ALTER TABLE founder_submissions ADD INDEX idx_founder_story_post(associated_post_id,id);
CREATE TABLE IF NOT EXISTS founder_story_workflows (
 founder_id BIGINT UNSIGNED PRIMARY KEY,
 post_id BIGINT UNSIGNED NOT NULL UNIQUE,
 email VARCHAR(254) NOT NULL,
 whatsapp VARCHAR(20) NOT NULL,
 contact_evidence VARCHAR(500) NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'draft',
 rounds_used TINYINT UNSIGNED NOT NULL DEFAULT 0,
 current_review_id BIGINT UNSIGNED NULL,
 approved_hash CHAR(64) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 CONSTRAINT fk_story_workflow_founder FOREIGN KEY(founder_id) REFERENCES founder_submissions(id) ON DELETE RESTRICT,
 CONSTRAINT fk_story_workflow_post FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS founder_story_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 founder_id BIGINT UNSIGNED NOT NULL,
 round_no TINYINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 contact_hash CHAR(64) NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 content_hash CHAR(64) NOT NULL,
 proposed_json LONGTEXT NULL,
 response_note TEXT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'pending',
 response_days TINYINT UNSIGNED NOT NULL DEFAULT 14,
 expires_at DATETIME NULL,
 responded_at DATETIME NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_story_round(founder_id,round_no),
 CONSTRAINT fk_story_review_workflow FOREIGN KEY(founder_id) REFERENCES founder_story_workflows(founder_id) ON DELETE RESTRICT,
 CONSTRAINT fk_story_review_actor FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
 INDEX idx_story_review_expiry(state,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS founder_story_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 review_id BIGINT UNSIGNED NOT NULL,
 channel VARCHAR(16) NOT NULL,
 payload_encrypted LONGTEXT NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'pending',
 provider_id VARCHAR(255) NULL,
 error_code VARCHAR(80) NULL,
 claimed_at DATETIME NULL,
 accepted_at DATETIME NULL,
 UNIQUE KEY uq_story_delivery(review_id,channel),
 CONSTRAINT fk_story_delivery_review FOREIGN KEY(review_id) REFERENCES founder_story_reviews(id) ON DELETE RESTRICT,
 INDEX idx_story_delivery_state(state,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
