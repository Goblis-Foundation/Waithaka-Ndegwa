# Deployment checklist

Use [the AWS migration runbook](docs/AWS-MIGRATION.md), [guarded installer guide](deploy/aws/INSTALL-CUTOVER.md) and [branded nameserver guide](deploy/aws/BRANDED-NAMESERVERS.md) for the detailed steps.

- [ ] Recover a consistent current database export and matching original application key.
- [ ] Recover public uploads, private proof files and any existing owned-library files; record and verify file hashes and any stored library versions.
- [ ] Select the AWS account/region, budget, VPC/subnets, security groups and application roles.
- [ ] Confirm registrar control for both domains and `.com` child-host/glue access; recheck registration/delegation rather than inferring ownership or availability from public DNS.
- [ ] Obtain complete current DNS-provider exports, including mail, verification, certificate, subdomain and routing records; preserve registrar delegation/glue/DS values and their TTLs.
- [ ] Review one reusable Route 53 set and two separate public zones for `waithakandegwa.com` and `waithakandegwa.co.ke`; derive the four actual AWS A/AAAA mappings for `ns1`–`ns4.waithakandegwa.com`.
- [ ] Record DNSSEC/cache prerequisites per domain. If replacing a signing key, remove the incompatible parent DS, verify removal and wait its previous TTL before cutover; preserve valid signing when retaining the same zone/keys.
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
- [ ] Finish the reviewed nameserver migration with current service records preserved: verify new authoritative answers, register `.com` glue and change `.com` delegation first, then delegate `.co.ke` to the same four `.com` nameservers.
- [ ] Produce a successful full `branded_dns.py verify` report and supply its private path as `dns.branded_delegation_report`; match the exact account/both zone IDs and refresh it within 24 hours of the production Route 53 cutover plan/apply.
- [ ] Separately cut over the website address records to CloudFront, preserving paths; verify TLS, canonical redirects, sitemap, RSS and login.
- [ ] Record AWS `INSYNC`, parent/glue observations, recursive DNS observations, website operation and mail operation separately. Confirm DNSSEC trust where enabled; restore normal TTLs after acceptance.
- [ ] Enable WORKER_ENABLED=1 only for the intended production worker and enable mail separately, then monitor the first weekly release.
- [ ] Keep a documented rollback point, complete DNS exports, former zones/provider and former application host until the verification window closes and previous cache lifetimes have expired; retain old DNS service for at least 48 hours after delegation changes.

No check is marked complete merely because a configuration template exists.


## r4 additions

- Apply additive migrations 100–140 to the recovered staging database before serving the new application code; retain the original key and prior migration hashes.
- Confirm Settings → Google shows G-RN0YXC2WCH and the intended enable state. A blank ID or disabled switch must omit the tag.
- Check one Google library loader immediately after head on public editorial pages, one application page view after consent, and no tag on Writer/private/signup/contact/payment routes. Verify actual reception with Tag Assistant and GA4 after live deployment.
- Exercise Start Here, built-in resource downloads, authored resource publication, article evidence/correction release and a real owned video page when available.
- Verify subscriber preferences and expiry/mailbox binding, a contact reference saved before notification, and a private founder follow-up without automatic publication.
- Check multi-content search counts and pagination, public sitemap entries, campaign links and the confirmed Search Console property/report period.
- Leave INDEXNOW_ENABLED=0 through staging. Activate only for the intended canonical production origin, together with the Writer opt-in and worker. Review an actual accepted receipt separately from indexing status.

## r5 DNS release checks

- The required nameservers for **both** domains are `ns1.waithakandegwa.com`, `ns2.waithakandegwa.com`, `ns3.waithakandegwa.com` and `ns4.waithakandegwa.com`. No AWS nameserver address is prefilled from an example.
- Keep nameserver preparation and the existing application installer as separate reviewed operations. The DNS helpers do not register domains or automatically change registrar delegation/glue/DS.
- On 21 October 2026, public recursive observations still showed webhostbox nameservers for `.com`, NXDOMAIN for `.co.ke`, and the old `G-WCKVHN823V` tag in the successful `.com` HTML. These observations do not establish domain ownership, registrar host objects, complete records or live analytics reception. Recheck before and after migration; see the dated evidence and limits in the branded nameserver guide.
- Current CloudFormation supports `HostedZone.DelegationSetId`, but changing it replaces the zone. Preserve the DNS resource owner and rollback copies; do not treat a template update as an in-place live-zone reassignment.
