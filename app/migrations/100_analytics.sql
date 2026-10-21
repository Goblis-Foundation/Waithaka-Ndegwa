-- The owner supplied this measurement ID on 21 October 2026.
-- Run once on restored and fresh installations; later Writer edits remain intact.
INSERT INTO settings(setting_key,setting_value,is_secret)
VALUES('google.ga4','G-RN0YXC2WCH',0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=0;
INSERT INTO settings(setting_key,setting_value,is_secret)
VALUES('google.analytics_enabled','1',0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=0;
