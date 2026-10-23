# AWS migration runbook

Status: application, reviewed deployment helper, configuration templates and release bundle prepared; no live AWS resources, database import, provider authorization or DNS cutover performed.

This runbook preserves the recovered PHP application and its existing database. It is not an instruction to initialize a replacement empty website.

## Architecture and prerequisites

Route 53 will serve both domains through `ns1`–`ns4.waithakandegwa.com`. Their website records point to CloudFront, which forwards application requests over HTTPS to an Application Load Balancer and Elastic Beanstalk PHP/Apache. Keep the existing DNS provider serving during preparation. RDS stores content, sessions and delivery records. Public media is served from an S3 bucket through CloudFront Origin Access Control. Private proof files and owned private images/videos use a separate blocked-public S3 bucket and authenticated PHP downloads. SES sends approved email; signed SNS events reconcile delivery, bounces and complaints.

The supplied files cover:

| File | Responsibility |
| --- | --- |
| data-stack.json | Independent RDS database, security group, private subnet group, public/private media and release buckets, retention/encryption and bucket policies |
| edge-stack.json | CloudFront distribution, canonical redirects, public-media OAC and separate static/dynamic cache behavior |
| beanstalk-options.template.json | Application environment settings, proxy, ALB, roles, health check, scaling bounds and secret references |
| runtime-iam-policy.template.json | Additional application permissions to attach to the instance role after replacing placeholders |
| mail-feedback-stack.json | SES configuration set, signed SNS topic and optional HTTPS feedback subscription |
| install_cutover.py and INSTALL-CUTOVER.md | Offline, hash-bound phase plans, guarded AWS execution, recovery receipts and separate activation steps |
| branded_dns.py, dns_probe.py, branded-dns-config.example.json and BRANDED-NAMESERVERS.md | Separate Route 53 delegation-set/zone preparation, complete-record preservation, registrar handoff, DNSSEC/cache prerequisites and observed DNS verification |
| .ebextensions and .platform | PHP settings, source protection, runtime checks, native systemd worker timer and log rotation |

Existing VPC/subnets, security groups, roles and certificates are inputs, not created by these templates. The application installer needs the final public zone IDs; prepare or adopt those through the separate branded nameserver workflow. The release does not choose an AWS account, region or spending commitment, register domains, or automatically modify registrar delegation/glue/DS.

Select an available, supported RDS **MySQL 8.4 minor version** and a supported Elastic Beanstalk **PHP 8.3 or 8.4 / Amazon Linux 2023** platform in the intended region. Validate current availability before provisioning. The secret JSON-key syntax in the options file needs a Beanstalk platform released on or after **2026-01-13** [1]. The local regression used MariaDB, so execute the supplied MySQL 8.4 CI or equivalent staging tests before accepting production compatibility.

The default scaling range is one to two application instances, and the database defaults to Single-AZ. These are initial configuration choices, not a high-availability guarantee. Select at least two application instances and Multi-AZ RDS if the availability objective requires them. Calculate actual AWS costs for the chosen region, traffic, storage, backups, mail, logs and networking before provisioning.

## 1. Recover the live state

Obtain a consistent export of the current database together with:

- The original application key and private configuration, transferred securely.
- All files under assets/uploads, preserving relative paths and original file bytes.
- All private storage/release-proofs files and any existing storage/media-library files.
- Current integration settings, callback registrations, DNS records and any live scheduled work.
- Complete exports from both DNS providers, including mail, DKIM/DMARC/SPF, verification labels, certificate records, subdomains and routing metadata; current registrar delegation/glue/DS values and previous TTLs. Public lookup results are not complete exports.
- A current copy of the deployed source for comparison with the recovered V9 source.

The source ZIP alone is not a backup of the current website. The original application key is needed to decrypt stored secrets; replacing it with a newly generated key will not recover them.

Create a file inventory with relative path, byte length and SHA-256 for each recovered media file. Verify source/destination hashes after transfer. Store private exports and key material outside the public document root, protect them, and keep them out of git, application ZIPs and shell history.

Keep the old site serving while staging is built. Do not direct live traffic at an empty database or use the retired web installer.

The public inventory on 21 October 2026 observed webhostbox nameservers for `.com`, NXDOMAIN for `.co.ke`, and `G-WCKVHN823V` in the successful `.com` homepage HTML. Direct parent/authoritative checks were unavailable from that environment, and no live analytics account/collection was checked. These observations do not prove domain ownership, registrar glue, a complete DNS inventory or the deployed release. Recheck the live state and use [the dated evidence limits](../deploy/aws/BRANDED-NAMESERVERS.md#dated-public-observations-are-not-an-account-inventory).

## 2. Establish the AWS boundary

Use separate staging and production data/secrets/queues. A staging copy may contain production credentials, so keep **WORKER_ENABLED=0** and **MAIL_TRANSPORT=disabled**; do not authorize production posting routes or exercise payment writes from staging.

Provide:

- Private database subnets in at least two availability zones.
- Application and ALB security groups. The database allows only the application group.
- Application-instance ingress only from its ALB. Restrict ALB origin ingress appropriately, for example to CloudFront’s origin-facing managed prefix list when all public traffic goes through CloudFront.
- Validated trusted proxy CIDRs matching the real proxy chain and security groups. Never use 0.0.0.0/0 or ::/0 as a shortcut.
- An outbound path to the required AWS and provider APIs. The options template uses public application subnets/public IPs with restricted ingress; change this deliberately if using private instances, NAT and/or VPC endpoints.
- An Elastic Beanstalk service role and EC2 instance profile with the applicable platform/deployment/log permissions, plus the narrow application policy template.
- A Secrets Manager JSON secret containing the exact APP_KEY, runtime DB_USERNAME and DB_PASSWORD. SMTP_PASSWORD is optional when using SMTP. With a customer-managed KMS key, add narrowly scoped decrypt permission for that key and service as required.
- ACM viewer certificate in us-east-1 covering the distribution aliases, plus the regional ALB certificate. Use an origin DNS name pointing to the ALB. The template expects the origin certificate to cover that name and the forwarded canonical host [3].
- Restricted staging access and a noindex response policy. Do not rely on an obscure preview hostname or assume APP_ENV alone blocks indexing.

The supplemental IAM example intentionally has no account-wide administrator permission. Replace every REPLACE value; remove the SES/SNS statements if those services are not used. The template does not supply the platform’s own service/instance role policies. Runtime code requires object read/write and version reads only at assets/uploads/* in the public bucket and release-proofs/* plus media-library/* in the private bucket, access to its exact application secret, and confirmation permission for its exact mail-feedback topic when SES is configured. Backup/migration administration is a separate role.

### Use the guarded installation helper

Follow [INSTALL-CUTOVER.md](../deploy/aws/INSTALL-CUTOVER.md) with a private copy of install-config.example.json. Supply the actual account, region, network, roles, certificates and recovered-artifact paths; the helper refuses placeholders and does not infer the website's AWS identity from other projects. It can produce plans offline. Applying a phase requires the plan's exact hash and checks the selected caller identity, resource ownership and relevant prior phases.

Restore the SQL backup on a restricted host with database access, preserve the original key, and record verified source/target counts in the helper's restore receipt. A plan or unchecked receipt does not prove restoration. Recovery artifacts and receipts are bound by hashes; changed data requires fresh verification. Create-only provisioning, selective changeset checks and separate readiness, DNS, worker and mail stages keep the recovery reviewable. The supplied helper does not create a VPC, obtain certificates, recover a missing database/key, or establish provider permissions.

### Prepare the branded authoritative DNS separately

Follow [BRANDED-NAMESERVERS.md](../deploy/aws/BRANDED-NAMESERVERS.md). Both `waithakandegwa.com` and `waithakandegwa.co.ke` use the same four `.com` vanity names through one reusable Route 53 set, with separate public zones in one AWS account. Populate the four A/AAAA mappings from the actual assigned AWS nameservers. Preserve existing website/mail targets during DNS preparation and keep complete rollback exports.

Verify registrar control, existing zone assignments and DNSSEC first. Adopting an existing zone's own set can retain that zone; moving it to a different set requires replacement and record migration. Current CloudFormation supports `HostedZone.DelegationSetId` but changing it requires replacement. If the signing key changes, remove any incompatible parent DS, verify removal and wait its prior TTL before cutover. Retaining the same zone/keys does not itself require disabling DNSSEC.

Register/update the four matching IPv4/IPv6 host/glue entries under `.com`, complete and verify `.com` delegation, then delegate `.co.ke` to those same four names. Registrar changes are external steps. Record AWS `INSYNC`, authoritative/parent answers, recursive observations and actual website/mail operation separately. The application installer's later address-record cutover cannot prove or perform that registrar migration.

Use the full helper verification report through `dns.branded_delegation_report` for the production Route 53 cutover. Its fresh successful observations must match the account, both final zone IDs and all four nameservers; a partial report is insufficient. Expected DS values come from the target zones' actual AWS signing state, but matching records do not independently validate the cryptographic DNSSEC chain. Use the detailed guide's exact commands, plan-content hashes and separate traffic acceptance steps.

## 3. Create and restore the persistent data stack

Create data-stack.json with the actual VPC, private subnets, application group, selected engine version and database settings. Leave MediaDistributionArn empty on the first deployment.

The database is private, encrypted, configured for fourteen days of automated backups, deletion protected, and snapshotted on removal/replacement. The three buckets are encrypted, versioned, blocked from public access and retained on stack deletion. These are configuration settings; verify backup recovery with a real restore rehearsal.

Use the managed RDS master credential for restoration/administration, not the ordinary website login. Restore the production export into the correct database and compare table counts, record counts and representative recent articles, consent records and contribution records.

Create a dedicated runtime database user with SELECT, INSERT, UPDATE and DELETE for this application schema. Use a separate migration/admin identity for schema changes. Advisory locks and runtime queue transactions do not require schema-changing privileges.

The PHP database connection uses the supplied AWS RDS trust bundle through DB_SSL_CA and retains the legacy +03:00 session timezone for existing DATETIME values. New publishing delivery timestamps are explicitly UTC. Do not mass-convert legacy dates without a verified migration plan.

## 4. Copy the media

Copy public files to the public-media bucket with the original keys:

`assets/uploads/<original relative path>`

CloudFront routes that entire prefix to S3. It will not fall back to an old application-instance file if an object is missing. Set appropriate MIME types and verify original URLs after the distribution is ready.

Copy private files to the private bucket with keys:

`release-proofs/YYYY/MM/<original filename>`

A restored database reference such as `storage/release-proofs/2026/10/example.pdf` is translated to that private S3 key when MEDIA_DRIVER=s3. No database rewrite is needed for valid legacy paths. The translation does not copy the files.

Do not put private proofs into the public media bucket or expose them through a signed/public link in article content. The application serves them through an authenticated Writer route.

The new media library writes immutable private objects to media-library/YYYY/MM/<asset UUID>.<extension>, or explicitly public assets under assets/uploads. A library record freezes its storage driver, bucket/key, version, size and SHA-256. If migrating an installation that already contains these records, reconcile their storage location/version deliberately and verify the hashes; changing MEDIA_DRIVER alone does not rewrite approved media snapshots. Original V9 installations have no such new library records.

The library accepts validated JPEG, PNG and WebP images up to 25 MiB and MP4, MOV or WebM video up to 512 MiB. Beanstalk includes 512 MiB PHP file/514 MiB request limits and a bounded Apache upload window; the CloudFront/ALB response settings are aligned at 120 seconds. These are configuration limits, not proof that every network can upload a maximum-size video. Test an intended video on the actual edge/ALB/PHP path. The application does not transcode videos.

Preserve the original source copies and hash inventory until the migration is accepted. A later object version is not a substitute for verifying the initial import.

## 5. Apply explicit migrations

Use the supplied source or application bundle with its locked dependencies. On a restricted migration host that can reach RDS, provide the recovered key and migration database credentials through a private configuration/environment. For example, an operator-created PHP configuration file may live at /run/waithaka-migration.php, readable only by that operator.

```bash
APP_CONFIG_FILE=/run/waithaka-migration.php php bin/migrate.php --plan
APP_CONFIG_FILE=/run/waithaka-migration.php php bin/migrate.php
```

The configuration file must follow app/config.example.php and must contain the intended database, original key and timezone. Environment values override that file, so verify the effective target without printing secrets. On a migration shell, exclude unrelated runtime DB_* environment overrides.

The runner performs the legacy additions and the numbered operations/security/journeys/publishing/newsletter/readiness/media/feedback/confirmation-binding migrations, records checksums and uses a database advisory lock. Re-running completed migrations is a no-op. Inspect the plan first and retain a matching pre-migration database snapshot.

**Do not use --fresh for this recovery.** It is only for a deliberately new empty installation and refuses a database that already contains tables. Similarly, bin/create-writer.php only creates the first user in an empty user table; restored Writer accounts are preserved.

Deploy hooks deliberately do not run migrations, and web requests never modify schema. Do not enable the application before the schema is ready.

## 6. Deploy staging and establish the edge

Use deployment/application.zip from the complete release archive as the Elastic Beanstalk source bundle. Its root contains index.php, .ebextensions and .platform; do not upload the outer delivery ZIP as the application.

Fill a private copy of beanstalk-options.template.json. Keep:

- APP_READY=0 until credentials/schema/media are ready.
- WORKER_ENABLED=0 throughout staging.
- MAIL_TRANSPORT=disabled until a deliberately controlled mail test.
- APP_URL, APP_ALLOWED_HOSTS and MEDIA_PUBLIC_URL on the actual staging hostname.

Use separate staging edge parameters: **CanonicalHost=preview.waithakandegwa.com** and **Aliases=preview.waithakandegwa.com**, or the chosen staging name. The edge template’s default production names must not be combined with the unchanged preview application settings.

Create the edge stack using the actual regional media domain, origin DNS and viewer certificate. Then update the **same data stack** with MediaDistributionArn set to the returned exact DistributionArn, retaining all its other parameters. That stack is the single owner of the public-media bucket policy. Its optional CloudFront allow statement is limited to assets/uploads/* and the exact distribution ARN; its TLS deny remains in place. Do not separately overwrite the bucket policy.

Point staging DNS at the distribution and test TLS. The distribution forwards viewer headers, cookies and query strings to the application so authentication and forms work [4]. Dynamic HTML has TTL zero; static files and versioned uploads have their own cache behavior. Private media has no CloudFront behavior.

The web platform consumes JSON-key secret references. The worker separately fetches the JSON secret with the AWS SDK because Beanstalk get-config does not return secret environment values [2]. Do not replace this with a plaintext secret dump.

## 7. Stage the full acceptance check

After verifying the key, schema and settings, set APP_READY=1 while keeping WORKER_ENABLED=0. The site can now be checked without draining external delivery work.

Verify on the actual Apache/PHP-FPM/CloudFront environment:

- .htaccess and the Directory override take effect. app, storage, vendor, bin, tests, deploy, docs, dotfiles, schemas and source archives are inaccessible by HTTP.
- Public articles, journey pages, founder pages, sitemap and RSS render correctly; restored article addresses and images match the old site.
- Unknown hosts are rejected; the intended aliases redirect to the canonical host with the path and query preserved.
- Writer login, CSRF-protected forms, draft isolation and previews work across instances; sessions persist in the database.
- Private/token pages have no-store, noindex and no-referrer, and do not load analytics. Do not infer Apache header behavior from the built-in PHP test server alone.
- Public S3 objects load through OAC, direct unauthenticated S3 access is denied, and private proof/library downloads require Writer authentication. Verify partial video preview requests and immutable approved-asset hashes.
- Google and provider callback addresses match the intended environment; confirm returned identities/resources, not just a successful redirect.
- Payments are tested only against explicitly configured sandbox merchants. Unknown legacy provider/environment records remain for review.
- The native systemd timer is installed and active. With APP_READY=1 and WORKER_ENABLED=0 it reads the runtime secret/database and records a paused heartbeat, then exits without delivery work. Writer → Operations should show that heartbeat. With APP_READY=0 the launcher stops before fetching secrets. No cron installation is assumed on AL2023 [5].
- The MySQL 8.4/PHP compatibility gate passes. CI is prepared, not already executed.

For an owner-controlled live provider test, use a deliberately approved package/destination after deciding the content and visibility. Do not mark a route working solely because credentials saved successfully.

### Configure email while delivery is paused

The campaign composer, approved frozen audience, one-click unsubscribe and signed SES feedback are included. They need an actual sender identity, correct postal-address footer, intended consent records and verified delivery configuration.

1. Verify the SES identity/DKIM and sending quota/access in the chosen region. Deploy mail-feedback-stack.json first with FeedbackEndpoint empty. Its SNS topic uses signature version 2 and restricts SES publishing to the exact account/configuration set [9].
2. Configure MAIL_FROM, MAIL_FROM_NAME, MAIL_POSTAL_ADDRESS, SES_REGION, SES_CONFIGURATION_SET and the exact SES_SNS_TOPIC_ARN while MAIL_TRANSPORT remains disabled. The helper's mail-setup phase grants the narrow runtime SES/SNS policy and installs this configuration without enabling sends.
3. Update the feedback stack with the exact HTTPS canonical URL /webhooks/ses. The endpoint validates the SNS signature, certificate origin, topic and recipient binding before writing state [10].
4. Open Writer → Mail feedback and confirm the signed pending subscription. The application calls SNS ConfirmSubscription for the configured topic; it does not fetch an arbitrary SubscribeURL.
5. In an explicitly controlled test, verify actual inbox delivery, DKIM/SPF/DMARC configuration as applicable, List-Unsubscribe and List-Unsubscribe-Post coverage in the received message, human unsubscribe and one-click unsubscribe. For RFC 8058, inspect the received DKIM signature's signed-header list; adding the headers in PHP alone does not prove provider signing [11].
6. Verify provider delivery/bounce/complaint events reach the signed endpoint and reconcile the correct outbox record. Hard bounces and complaints must suppress the address across newsletter and transactional outbox sends. An unsubscribe after campaign queuing must also prevent the later send.
7. Check the actual restored pending work, mail quota and intended campaign recipients before the separate worker/mail activation phases. Record the helper's mail receipt only from completed checks.

SMTP is an alternative transport, but this release's automatic provider feedback adapter is specifically SES/SNS. The guarded activation helper expects SES feedback; a different mail provider needs equivalent feedback/suppression integration and verified deployment configuration before production campaigns.

## 8. Monitoring and recovery

The hooks install a systemd oneshot worker and timer, running about a minute after the previous run. The application and worker both require explicit enablement. Database claims/locks protect against concurrent instances. Logs go to /var/log/waithaka-worker.log, rotate daily for fourteen files, and are available through Beanstalk tail/bundle tasks.

Beanstalk application/proxy log streaming is configured with thirty-day retention. The custom worker file needs an explicit unified CloudWatch Agent collection rule if central streaming is required; tail/bundle registration alone is not continuous CloudWatch collection.

Add alerts for application 5xx/health failures, database availability/storage, failed worker runs, overdue scheduled jobs, pending email age and ambiguous/reconnect jobs. Set operator responsibility for reviewing those alerts. No alarm resources or on-call recipients are created by the templates.

Writer → Operations reports local configuration, migration state, worker heartbeat, queue age and delivery/reconnection issues without revealing secrets or recipient lists. It does not replace CloudWatch alarms, prove live provider permissions, or measure services it cannot reach. Writer → Mail feedback separates accepted email from delivered, bounced or complained messages.

Review proxy/ALB/CloudFront logs before enabling public traffic. Exclude sensitive query strings or use redaction where supported; tokens and OAuth codes must not become widely accessible logs. CloudFront access logging is not enabled by the supplied edge template. Use controlled retention and access for whichever logging route is selected.

A known provider acknowledgement is retained even when a dependent local update fails; a later worker repairs dependent records. Unknown outcomes never cause an automatic resend. Inspect the actual provider record before retrying or completing an uncertain job.

Keep the application key with encrypted backups and exercise restoration of database, key and media together. Rotating APP_KEY requires a deliberate re-encryption migration. Coordinate database-secret rotation with web-process refresh: the worker reads the current secret on each invocation, while Beanstalk’s web environment needs refresh/restart to receive updated secret values [1].

## 9. Cut over production

1. Retain a rollback copy of the current source, database, key, uploads and DNS configuration.
2. Freeze old-host editorial writes and stop its external workers. Obtain the final database/media delta.
3. Restore that final state to production, apply the reviewed migrations and repeat the targeted content/key/media checks.
4. Set production APP_URL to https://www.waithakandegwa.com; set the four allowed hosts; set MEDIA_PUBLIC_URL consistently.
5. Use the four production aliases and matching certificate on the production edge. Update the production data stack’s MediaDistributionArn to that distribution’s exact ARN.
6. Verify/register canonical provider callback URLs and payment notifications. Re-check account/resource selection after any restore that replaced connection records.
7. Complete the separate branded nameserver migration with the current DNS records preserved: `.com` glue/delegation first, verify, then `.co.ke` delegation to the same `ns1`–`ns4.waithakandegwa.com`. Use verified final zone IDs in the installer. After its DNS/cache prerequisites and staging acceptance pass, point both domains' apex/www address records to the production distribution. Preserve all unrelated email/DNS records.
8. Confirm redirect behavior, TLS, public pages, private headers, login, uploads, sitemap and RSS through each intended hostname.
9. Enable WORKER_ENABLED=1 only for the intended production application, after reviewing restored pending work and provider destinations. Enable mail separately after its acceptance test.
10. Verify both domains in Search Console as appropriate, submit the canonical sitemap, and inspect important migrated URLs. This connection is read-only and does not automatically submit them.
11. Observe the first real weekly release and inspect actual platform post links and provider email acknowledgements.

If rollback is necessary, stop new delivery work first. Decide whether to reverse application addresses, authoritative delegation, code or a matching database/media snapshot. Keep DNSSEC trust consistent with the delegated zone. Never attach old code blindly to an incompatible migrated schema, and never restore an old queue without reconciling external operations that already completed. Keep the former host and DNS service available until the acceptance window closes and prior cache lifetimes expire; retain old DNS zones/provider service for at least 48 hours after delegation changes.

## Primary technical references

Checked 2026-10-21. Platform/API requirements can change; verify the selected account and platform at deployment.

1. AWS — environment secrets and JSON-key/platform requirements: https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/AWSHowTo.secrets.env-vars.html
2. AWS — get-config and secret-variable limitation: https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/custom-platforms-scripts.html
3. AWS — HTTPS between CloudFront and custom origins: https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/using-https-cloudfront-to-custom-origin.html
4. AWS — managed AllViewer origin request policy: https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/using-managed-origin-request-policies.html
5. AWS — AL2023 cron/systemd scheduling: https://docs.aws.amazon.com/linux/al2023/ug/cron.html
6. AWS — Elastic Beanstalk PHP and Composer: https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/create_deploy_PHP.container.html
7. AWS — extending Apache configuration: https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/platforms-linux-extend.proxy.html
8. AWS — instance log tasks and log streaming: https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/using-features.logging.html
9. AWS — SES SNS event destinations and topic policy: https://docs.aws.amazon.com/ses/latest/dg/event-publishing-add-event-destination-sns.html
10. AWS — verify SNS message signatures: https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html
11. IETF — one-click unsubscribe, RFC 8058: https://www.rfc-editor.org/info/rfc8058/
