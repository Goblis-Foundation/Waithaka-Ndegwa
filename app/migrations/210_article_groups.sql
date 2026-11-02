-- Grouping belongs to the reviewed article snapshot; drafts retain their own JSON payload.
ALTER TABLE posts ADD COLUMN article_groups_json JSON NULL;
