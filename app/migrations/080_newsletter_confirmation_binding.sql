-- Bind new confirmation capabilities to the address present at issuance.
-- Preserve existing tokens unchanged. Legacy NULL bindings are checked against
-- their matching encrypted confirmation outbox envelope, never guessed from
-- the subscriber's current address. Missing evidence requires fresh opt-in.
ALTER TABLE newsletter_tokens
  ADD COLUMN issued_email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;
