-- The owner confirmed that the TikTok profile is professional.
-- Correct only the old default; do not infer a verified Business subtype or
-- overwrite a more specific declaration. Approved destinations remain immutable.
-- Connections, credentials, delivery routes, revisions and jobs are unchanged.
UPDATE publishing_channels
SET account_type = 'professional'
WHERE slug = 'tiktok' AND account_type = 'personal';
