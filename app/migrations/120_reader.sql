-- Reader choices do not change subscription status or import another audience.
-- New workflow timestamps are UTC; legacy contact/subscriber dates stay Nairobi.
CREATE TABLE IF NOT EXISTS reader_preferences (
  subscriber_id BIGINT UNSIGNED PRIMARY KEY,
  receive_all TINYINT(1) NOT NULL DEFAULT 1,
  topic_ids_json TEXT NOT NULL,
  journey_ids_json TEXT NOT NULL,
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_reader_preference_subscriber FOREIGN KEY (subscriber_id) REFERENCES newsletter_subscribers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reader_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purpose VARCHAR(24) NOT NULL,
  subscriber_id BIGINT UNSIGNED NULL,
  founder_id BIGINT UNSIGNED NULL,
  nonce_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  issued_email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  consent_revision BIGINT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  outbox_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_reader_link_subscriber FOREIGN KEY (subscriber_id) REFERENCES newsletter_subscribers(id) ON DELETE CASCADE,
  CONSTRAINT fk_reader_link_founder FOREIGN KEY (founder_id) REFERENCES founder_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_reader_link_outbox FOREIGN KEY (outbox_id) REFERENCES email_outbox(id) ON DELETE SET NULL,
  INDEX idx_reader_link_subscriber (subscriber_id,purpose),
  INDEX idx_reader_link_founder (founder_id,purpose),
  INDEX idx_reader_link_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reader_enquiries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_id BIGINT UNSIGNED NOT NULL UNIQUE,
  enquiry_type VARCHAR(32) NOT NULL DEFAULT 'general',
  status VARCHAR(24) NOT NULL DEFAULT 'new',
  next_action VARCHAR(1000) NOT NULL DEFAULT '',
  next_action_at DATETIME NULL,
  last_response_at DATETIME NULL,
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  notification_state VARCHAR(24) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_reader_enquiry_contact FOREIGN KEY (contact_id) REFERENCES contact_messages(id) ON DELETE CASCADE,
  INDEX idx_reader_enquiry_work (status,next_action_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reader_enquiry_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  enquiry_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(32) NOT NULL,
  note TEXT NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_reader_enquiry_event_owner FOREIGN KEY (enquiry_id) REFERENCES reader_enquiries(id) ON DELETE CASCADE,
  CONSTRAINT fk_reader_enquiry_event_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_reader_enquiry_event (enquiry_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reader_founder_followups (
  founder_id BIGINT UNSIGNED PRIMARY KEY,
  clarification_status VARCHAR(24) NOT NULL DEFAULT 'none',
  permission_status VARCHAR(24) NOT NULL DEFAULT 'not_requested',
  clarification_question TEXT NOT NULL,
  permission_request TEXT NOT NULL,
  receipt_state VARCHAR(24) NOT NULL DEFAULT 'pending',
  last_reply_at DATETIME NULL,
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_reader_followup_founder FOREIGN KEY (founder_id) REFERENCES founder_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reader_founder_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  founder_id BIGINT UNSIGNED NOT NULL,
  message_type VARCHAR(32) NOT NULL,
  body TEXT NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_reader_founder_message_owner FOREIGN KEY (founder_id) REFERENCES founder_submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_reader_founder_message_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_reader_founder_message (founder_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historical records become visible to Writer without sending retrospective mail.
INSERT INTO reader_enquiries(contact_id,enquiry_type,status,next_action,notification_state,created_at,updated_at)
  SELECT c.id,'general','new','Review recovered enquiry',IF(c.email_sent=1,'sent','historical'),CONVERT_TZ(c.created_at,'+03:00','+00:00'),UTC_TIMESTAMP()
  FROM contact_messages c LEFT JOIN reader_enquiries r ON r.contact_id=c.id WHERE r.id IS NULL;
INSERT INTO reader_founder_followups(founder_id,clarification_question,permission_request,receipt_state,updated_at)
  SELECT f.id,'','','historical',UTC_TIMESTAMP() FROM founder_submissions f LEFT JOIN reader_founder_followups r ON r.founder_id=f.id WHERE r.founder_id IS NULL;
