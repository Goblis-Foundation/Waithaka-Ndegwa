# Deployment checklist

Use [the AWS migration runbook](docs/AWS-MIGRATION.md) and [guarded installer guide](deploy/aws/INSTALL-CUTOVER.md) for the detailed steps.

- [ ] Recover a consistent current database export and matching original application key.
- [ ] Recover public uploads, private proof files and any existing owned-library files; record and verify file hashes and any stored library versions.
- [ ] Select the AWS account/region, budget, VPC/subnets, security groups and application roles.
- [ ] Validate a supported PHP platform and an available RDS MySQL 8.4 minor version.
- [ ] Provision the independent data stack and restore a staging copy.
- [ ] Apply explicit migrations with a migration account; use a separate runtime DML account.
- [ ] Fill all deployment placeholders and register HTTPS certificates and staging DNS.
- [ ] Review the offline helper plan for the actual account/region and record verified restore receipts for the exact backup/key/media set.
- [ ] Deploy the versioned application bundle with APP_READY=0, WORKER_ENABLED=0 and mail disabled.
- [ ] Confirm .htaccess enforcement and secret/key configuration before enabling staging.
- [ ] Validate MySQL 8.4 compatibility, restored article URLs, both media stores, private routes and sessions.
- [ ] Check Writer → Operations, a paused worker heartbeat, immutable media previews/downloads and actual video-upload limits on the deployed edge.
- [ ] Authorize actual Google/social resources and verify each destination in Writer.
- [ ] For native TikTok, complete the separate Accounts API app review, authorize and confirm the owner account, verify the media domain/prefix, and check the live CDN returns the approved public video bytes. The Login Kit reporting grant cannot publish.
- [ ] Test permitted provider formats, including an approved private YouTube test and resumable status recovery, with intended owner-controlled destinations.
- [ ] For an approved TikTok public-video test, verify current interaction/disclosure settings, pending task status and returned public post IDs; confirm that recovery polls the original task without another publishing request.
- [ ] Configure SES/DKIM and the signed feedback topic while mail is disabled; confirm the exact signed subscription in Writer → Mail feedback.
- [ ] Verify received mail authentication/unsubscribe headers, human/one-click unsubscribe, feedback reconciliation and address suppression before campaign activation.
- [ ] Configure monitoring, redacted access logs, retention, SES feedback and backups; rehearse a restore.
- [ ] Freeze old-host writes and workers, capture the final delta, restore and re-run migrations as needed.
- [ ] Update APP_URL/allowed hosts/media URL, provider redirect URLs and verified sitemap settings.
- [ ] Cut over both domains, preserving paths; verify TLS, canonical redirects, sitemap, RSS and login.
- [ ] Enable WORKER_ENABLED=1 only for the intended production worker and enable mail separately, then monitor the first weekly release.
- [ ] Keep a documented rollback point and the former host until the verification window closes.

No check is marked complete merely because a configuration template exists.
