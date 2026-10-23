-- Preserve existing display names; never guess a subscriber's family name.
ALTER TABLE newsletter_subscribers
 ADD COLUMN first_name VARCHAR(120) NOT NULL DEFAULT '',
 ADD COLUMN last_name VARCHAR(120) NOT NULL DEFAULT '';
