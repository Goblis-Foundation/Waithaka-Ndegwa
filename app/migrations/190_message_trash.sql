CREATE TABLE IF NOT EXISTS reader_enquiry_trash (
 enquiry_id BIGINT UNSIGNED PRIMARY KEY,
 deleted_by BIGINT UNSIGNED NOT NULL,
 deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_enquiry_trash_enquiry FOREIGN KEY (enquiry_id) REFERENCES reader_enquiries(id) ON DELETE CASCADE,
 CONSTRAINT fk_enquiry_trash_actor FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
