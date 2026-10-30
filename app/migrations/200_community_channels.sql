-- New routes start assisted. These are platform home links, not invented owner accounts.
INSERT IGNORE INTO publishing_channels(slug,name,profile_url,account_type) VALUES
('threads','Threads','https://www.threads.net/','profile'),
('telegram','Telegram','https://telegram.org/','channel_or_group'),
('discord','Discord','https://discord.com/channels/@me','server_channel'),
('signal','Signal','https://signal.org/','private_group');
