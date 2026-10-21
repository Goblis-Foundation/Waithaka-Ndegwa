# Deployment checklist

Use [the AWS migration runbook](docs/AWS-MIGRATION.md) for the detailed steps.

- [ ] Recover a consistent current database export and matching original application key.
- [ ] Recover public uploads and private proof files; record and verify file hashes.
- [ ] Select the AWS account/region, budget, VPC/subnets, security groups and application roles.
- [ ] Validate a supported PHP platform and an available RDS MySQL 8.4 minor version.
- [ ] Provision the independent data stack and restore a staging copy.
- [ ] Apply explicit migrations with a migration account; use a separate runtime DML account.
- [ ] Fill all deployment placeholders and register HTTPS certificates and staging DNS.
- [ ] Deploy the versioned application bundle with APP_READY=0, WORKER_ENABLED=0 and mail disabled.
- [ ] Confirm .htaccess enforcement and secret/key configuration before enabling staging.
- [ ] Validate MySQL 8.4 compatibility, restored article URLs, both media stores, private routes and sessions.
- [ ] Authorize actual Google/social resources and verify each destination in Writer.
- [ ] Test permitted provider formats and mail acceptance with intended owner-controlled destinations.
- [ ] Configure monitoring, redacted access logs, retention, SES feedback and backups; rehearse a restore.
- [ ] Freeze old-host writes and workers, capture the final delta, restore and re-run migrations as needed.
- [ ] Update APP_URL/allowed hosts/media URL, provider redirect URLs and verified sitemap settings.
- [ ] Cut over both domains, preserving paths; verify TLS, canonical redirects, sitemap, RSS and login.
- [ ] Enable WORKER_ENABLED=1 only for the intended production worker and enable mail separately, then monitor the first weekly release.
- [ ] Keep a documented rollback point and the former host until the verification window closes.

No check is marked complete merely because a configuration template exists.
