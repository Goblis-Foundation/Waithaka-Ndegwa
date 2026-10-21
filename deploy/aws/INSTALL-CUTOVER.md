# Reviewed installation and cutover helper

These helpers are implemented and tested offline. No AWS account has been selected, no live restore has been accepted, and no resources, DNS records or provider messages have been changed as part of this implementation.

Use this procedure together with [the AWS migration runbook](../../docs/AWS-MIGRATION.md) and [BRANDED-NAMESERVERS.md](BRANDED-NAMESERVERS.md). It preserves the existing website and Writer accounts. It does not initialize an empty replacement site or generate a replacement application key. The branded nameserver workflow prepares authoritative DNS separately; this installer's cutover phase changes only the website address records described below.

## Inputs and boundaries

`cloudshell_readiness.py` validates local recovery inputs by default. Its optional `--check-aws` performs read-only account and infrastructure checks. `install_cutover.py plan` is also entirely offline. In these installation helpers, only the explicitly named `apply`, `check-aws` and `capture-dns` commands can contact AWS. The separate DNS preparation/probe commands and their network boundaries are documented in the branded nameserver guide.

The helper requires Python 3.10 or newer on Linux and AWS CLI v2.38 or newer for live commands (including its explicit `--cli-error-format legacy` setting for safe error-code handling). Its supplied templates target the commercial `aws` partition. Existing VPC/subnets, service and instance roles, security groups, validated certificates, the application secret, mail feedback infrastructure and DNS ownership are prerequisites. It does not create or modify those existing network resources. The runtime inline policy it adds is limited to the exact application secret and media prefixes; the separately gated mail setup adds the chosen SES identity/configuration set and exact SNS topic.

Choose the account, region, CLI profile, instance sizes, availability, subnet design and costs explicitly. No account or region is borrowed from another project. Every live command checks the intended account using STS before any mutation and supplies explicit region/profile flags. Configured endpoint overrides are disabled. Raw AWS errors and secret values are suppressed.

Copy `install-config.example.json` to a private workspace outside the application source tree and replace every placeholder. The example deliberately fails validation. Its single-instance and Single-AZ values are choices to review, not an availability commitment. Use unique application/environment/stack names and a unique `deployment_id` for staging and production; use separate secrets, databases, buckets and journals. Preserve the completed configuration: an existing journal refuses a different configuration.

The private workspace needs mode `0700`; key/export/receipt/plan files need `0600`. Set `umask 077` before preparing them. Do not place keys, SQL exports, inventories or deployment state in the application document root, git or a release ZIP. The recovered key file is a private JSON object with an `APP_KEY` field containing the **exact original key**, including any intentional characters; it is not a new key. The JSON application secret in Secrets Manager must already contain that same key plus a dedicated runtime `DB_USERNAME` and `DB_PASSWORD`. Restoration uses a different administrative identity. Customer-managed KMS keys require separately reviewed decrypt grants; the helper does not broaden its policy automatically.

Use `deployment/application.zip` from the complete release, with its matching `source` files. Its migration bytes must match the local migration files. It must contain locked Composer dependencies and the platform hooks. The outer delivery archive is not an application source bundle.

Provide all three recovered media roots, even if one is an explicitly empty directory:

| Local recovered tree | Target and preserved key |
| --- | --- |
| `assets/uploads` | Public bucket: `assets/uploads/<relative path>` |
| `storage/release-proofs` | Private bucket: `release-proofs/<relative path>` |
| `storage/media-library` | Private bucket: `media-library/<relative path>` |

The inventory rejects symlinks, executable/unknown extensions and files larger than a single S3 PutObject (5 GB). It records original relative paths, bytes and SHA-256. Each copy uses a conditional create, an S3 checksum and expected bucket owner; a matching existing object is reused only after checksum, length and VersionId verification. A conflicting object is never overwritten. Changed legacy files need a separately reviewed versioned copy and restoration reconciliation. The generated media receipt includes object VersionIds so restored `media_assets` records can be matched to their new bucket, driver, URL and immutable version. Copying files alone does not rewrite those database records.

## CloudShell considerations

Ordinary CloudShell is not assumed to reach private RDS. Restore and run migrations from a restricted host in the intended VPC, or a deliberately configured CloudShell VPC environment. VPC CloudShell home storage is ephemeral, its UI upload/download is unavailable, and internet access requires the actual NAT/network design; an internet gateway in a public subnet does not give CloudShell a public IP. Preserve the private journal, receipts and backups in a controlled durable location after every phase. Never store recovery keys in the public-media bucket.

Start with an offline check (all paths below are examples of operator-chosen private paths, not preconfigured infrastructure):

```bash
umask 077
python3 deploy/aws/cloudshell_readiness.py \
  --config /private/waithaka/install.json \
  --out /private/waithaka/offline-readiness.json
```

An explicit live read-only check is:

```bash
python3 deploy/aws/cloudshell_readiness.py \
  --config /private/waithaka/install.json --check-aws \
  --out /private/waithaka/aws-readiness.json
```

It checks the STS account, VPC/subnet membership and availability zones, security-group VPC membership, exact engine availability, supported Beanstalk platform date/status, certificate names, role/profile relationship and secret metadata. It does not fetch secret values. Routing/NAT, security-group ingress, role trust, costs and live restore correctness remain explicit operator checks.

## Plans and receipts

Every phase is prepared with `plan` into a **new** private file. Review the entire file: exact account, resource names, paths, DNS actions, template hashes, artifact hashes and any receipts. Then provide its printed SHA-256 to `apply`. There is no interactive guessing and no default apply.

```bash
python3 deploy/aws/install_cutover.py plan \
  --config /private/waithaka/install.json --phase foundation \
  --out /private/waithaka/plans/01-foundation.json
python3 deploy/aws/install_cutover.py apply \
  --plan /private/waithaka/plans/01-foundation.json \
  --confirm-sha256 THE_EXACT_PRINTED_64_CHARACTER_HASH
```

`apply` rebuilds the plan before contacting AWS. Changed configuration, receipts, backup, key fingerprint, media inventory, release, migration/template bytes or state invalidate it. New phases use outputs recorded by earlier phases so their plans contain real target resource IDs. The first foundation plan requires an infrastructure-review receipt.

Generate a receipt template for the relevant kind, perform the checks, and then edit only verified fields:

```bash
python3 deploy/aws/install_cutover.py receipt-template \
  --config /private/waithaka/install.json --kind infrastructure \
  --out /private/waithaka/deployment-state/infrastructure-receipt.json
```

Receipt templates start with every check `false`, an empty verifier and timestamp. They are **operator attestations**, not cryptographic attestations or independent evidence that a test ran. Record the actual verifier, an ISO-8601 timestamp including timezone, and checks really performed. Receipts expire after seven days and bind the exact artifacts. Keep supporting screenshots/logs privately. Never put credentials, recipients, authorization tokens or raw provider responses in a receipt.

## Phase sequence

| Phase | What apply does | Required evidence |
| --- | --- | --- |
| `foundation` | Creates the new retained data stack through a checked CloudFormation change set | `infrastructure-receipt.json`; existing network/roles/certs/secret metadata |
| `media` | Copies only absent objects and verifies all current hashes/versions | Actual local files; prior foundation outputs |
| `application` | Compares the secret key with the original, installs the scoped runtime policy, uploads the immutable release and creates the new Beanstalk environment | Verified current media; `APP_READY=0`, worker and mail remain paused |
| `edge` | Creates the new CloudFront stack for the explicit aliases | `origin-receipt.json` after origin DNS and regional TLS point to the actual ALB |
| `bind-media` | Updates only `PublicMediaTlsPolicy` on the same data stack with the exact distribution ARN | Recorded edge outputs; no RDS/bucket replacement is permitted |
| `ready` | Verifies the original key again, sets `APP_READY=1`, keeps worker/mail paused | `restore-receipt.json`, verified final media, migrations and database checks |
| `mail-setup` (SES only) | Adds scoped SES/SNS runtime permissions and sender/feedback/postal settings; keeps mail and workers paused | Explicit SES configuration and a ready production environment; mail feedback stack is created separately |
| `cutover` | Applies only reviewed address records in Route 53, or records acceptance of an externally performed DNS cutover | `acceptance-receipt.json`; production also `cutover-receipt.json`; fresh DNS snapshot or `traffic-receipt.json` for external DNS; configured branded delegation report for this production Route 53 workflow |
| `worker` | Enables production work while keeping mail paused | `traffic-receipt.json` and `worker-receipt.json`: current destinations, pending queues, uncertain outcomes and monitoring reviewed |
| `mail` | Enables SES delivery | Prior mail setup and worker phase, plus `mail-receipt.json`: verified identity/DKIM, configuration-set feedback, signed subscription, suppression, controlled delivery, consent/postal address and unsubscribe |

Complete `mail-setup` before activating workers. If you deliberately re-run it later, it pauses workers again; repeat the reviewed worker phase afterward. Staging cannot activate workers or mail. A paused timer still records its invocation in Writer → Operations.

Creation is limited to new resources tagged with the exact deployment ID. An unrelated same-named stack/application/environment is refused. CloudFormation resource removal or replacement is refused; the media-binding update accepts only the intended bucket-policy modification. Existing matching owned resources can be reconciled after interruption, but rollback/failed/in-progress stacks require inspection; no destructive cleanup is attempted. Every Beanstalk creation/adoption and option update reads back the deployed configuration, including pause/activation flags and database/media/canonical/secret references. A `Ready` environment with old or drifted settings is not recorded as successful. An interrupted partial Route 53 cutover records each accepted zone change before waiting. Capture current DNS and prepare a fresh plan before proceeding; never blindly replay an old journal.

After CloudFormation execution, the helper polls the exact change-set ARN and stack ARN returned by creation. Completion requires that change set's `ExecutionStatus=EXECUTE_COMPLETE`, the intended successful create/update stack status, and all reviewed stack parameters. A previous `CREATE_COMPLETE`/`UPDATE_COMPLETE` response is insufficient. The helper waits through delayed/in-progress observations, stops on failed/obsolete/rollback/deleting states, and records verified execution identities in the private change-set evidence file before marking the phase complete.

## Restricted-host restoration boundary

The helper deliberately does **not** execute an arbitrary SQL file or create/recover a password. It supplies the target RDS host and managed administrator-secret ARN in the foundation state. Restore from a controlled VPC host with a separate admin identity:

1. Preserve the original source, consistent SQL export, exact key, media and DNS rollback copies. Inspect the SQL export for unintended `USE`, `CREATE DATABASE`, grants or cross-schema statements before import. Use an export scoped to this application schema, not a server-wide dump.
2. Set a private MySQL client option file to the exact target RDS hostname, migration/admin identity and CA bundle. Use MySQL 8.4 client verification (`ssl-mode=VERIFY_IDENTITY`). Keep credentials out of command arguments, history and the application runtime account.
3. Import the reviewed export into the **new target database recorded in the journal**. Do not import into the old live database. An example command shape is `mysql --defaults-extra-file=/run/waithaka-restore.cnf --database=THE_RECORDED_DATABASE < /private/waithaka/database.sql`; the option file and target must be populated from the reviewed plan. Compressed exports must be decompressed and reviewed privately first.
4. Compare source and target counts for every recovered table, not just article counts. Inspect recent published/draft articles, original slugs, consent/release/payment records, encrypted connections and queue status. Ensure restored Writer accounts exist. Do not create a replacement first user.
5. Create the runtime database user with only SELECT/INSERT/UPDATE/DELETE on this schema and store that credential with the exact original key in the configured application secret. Use the separate migration identity for schema changes. Confirm negotiated TLS.
6. With the matching release and a private migration configuration, run `php bin/migrate.php --plan`, review it, retain a pre-migration snapshot, then run `php bin/migrate.php`. **Never use `--fresh` for recovery.** Clear unrelated DB environment overrides first. Keep the existing `+03:00` legacy date semantics; publishing timestamps are explicitly UTC.
7. Use the generated media-copy receipt to reconcile any existing `media_assets` driver/bucket/URL/VersionId fields while preserving the source SHA-256 and storage key. Legacy proof paths keep their exact references and are translated by runtime in S3 mode. Verify private downloads and compare actual object bytes as needed.
8. Generate `restore-receipt.json` and fill source/target counts for all recovered tables. Required core counts are `users`, `posts`, `contributions`, `media_releases`, `settings`; `users` must be at least one. New migration-only tables can be verified separately. Mark checks true only after they passed. The receipt binds the SQL SHA-256, migration hash, key/media/release inventory, target RDS host and database name.

Build staging while the old site still serves. For final production recovery, freeze the old editor, stop old external workers, and replace the local export/media roots with the final consistent snapshot at the same configured paths. Re-run `media`, regenerate the restore receipt for the new hashes and re-run `ready`. Changing the backup/media after ready invalidates cutover. A changed release ZIP requires a separate reviewed version-update process; this install journal does not silently redeploy changed code.

## Domain and origin procedure

The intended authoritative nameservers for both domains are `ns1.waithakandegwa.com` through `ns4.waithakandegwa.com`. Follow [BRANDED-NAMESERVERS.md](BRANDED-NAMESERVERS.md) to prepare one reusable Route 53 set and separate public zones, preserve the full current records, complete DNSSEC/cache prerequisites, register actual IPv4/IPv6 glue under `.com`, and verify `.com` delegation before `.co.ke`. That workflow does not register a domain or write automatically to either registrar. The 21 October 2026 public observations are a dated baseline, not a complete zone export or ownership check.

Prefer completing the nameserver migration with current website/mail targets preserved before changing the website to CloudFront. Supply the verified final public zone IDs to this installer's private configuration before preparing its phases. An existing install journal rejects changed configuration: do not edit a completed journal to substitute a new zone ID; reconcile the deployment configuration through a fresh reviewed workflow. Current CloudFormation supports a reusable `DelegationSetId`, but changing it replaces a zone, so it is not a shortcut for changing a live zone's server assignment.

For the requested production branded-nameserver route, set `dns.branded_delegation_report` to the private path of the full report produced by `branded_dns.py verify`. Set that path before creating the installer journal; the report itself can be generated after the registrar work. At cutover planning/apply it must match this account, both final zone IDs and all four nameservers, contain successful authoritative/parent/recursive checks without errors, and be no more than 24 hours old. The gate does not accept a `.com`-only report. Refreshing the report requires a fresh cutover plan. DS comparison in that report does not independently validate DNSSEC signatures or prove website/mail traffic.

The helper records the Beanstalk environment CNAME and load-balancer identifiers. Resolve the actual ALB DNS name/zone through the AWS console or read-only ELB describe operation, point the distinct origin hostname at it, and verify regional TLS for both the origin and forwarded canonical hostname. Confirm the intended origin ingress and proxy chain; record `origin-receipt.json`. This explicit prerequisite avoids changing an existing origin DNS record blindly.

For production set `environment=production`, canonical `www.waithakandegwa.com`, and these exact aliases: `www.waithakandegwa.com`, `waithakandegwa.com`, `www.waithakandegwa.co.ke`, `waithakandegwa.co.ke`. For staging use separate chosen preview aliases throughout. Staging must be protected and noindexed by an explicit edge/access policy; this helper does not claim that APP_ENV alone provides access restriction. Use the normal staging distribution to perform the full acceptance checks. For production before DNS cutover, test the target distribution with the intended Host/SNI via an operator-controlled DNS override so canonical routing remains representative.

With Route 53, configure the verified final public hosted zone ID for each alias and capture its address records read-only. Confirm separately that these zones actually serve the domains; a successful AWS record update in an undelegated zone does not move public traffic:

```bash
python3 deploy/aws/install_cutover.py capture-dns \
  --config /private/waithaka/install.json \
  --out /private/waithaka/deployment-state/dns-snapshot.json
```

The cutover plan contains the exact previous A/AAAA/CNAME records and desired CloudFront aliases. If a CNAME must be replaced, its deletion is explicit in the reviewed plan, followed in the same zone batch by alias creation. It does not modify MX, TXT, NS or unrelated hosts. Special weighted/failover/geographic routing is refused. Apply re-reads the targeted address records and refuses stale snapshots. Snapshots expire after 24 hours.

Record Route 53 `INSYNC`, observed parent delegation/glue, recursive answers and actual website/mail checks separately. `INSYNC` covers Route 53's authoritative fleet only. Complete the traffic receipt after checking live TLS, canonical path-preserving redirects, representative pages/private routes and the preserved mail service; neither an API acknowledgement nor a public DNS probe alone proves those outcomes.

With external DNS, use the recorded CloudFront distribution domain and aliases to prepare the provider's A/AAAA alias/flattening or CNAME records. Apex names need a provider-supported alias/flattening mechanism; do not invent fixed CloudFront IP addresses. Preserve mail records and all unrelated records. Complete `traffic-receipt.json` only after performing and checking that external DNS cutover. The helper then records the attestation without claiming to have edited the provider.

## SES setup and acceptance

If SES is intended, declare `mail.transport=ses` in the initial configuration and provide `from_address`, `from_name`, `postal_address`, exact `identity_arn`, `configuration_set`, and exact `sns_topic_arn` in the selected region/account. This does **not** enable sending. Provision the separately documented mail feedback stack, identity and DKIM first. `mail-setup` deploys the exact settings and permissions while mail stays disabled; the Writer can then confirm the signed SNS subscription and inspect feedback. Perform an approved controlled SES test using the selected configuration set and verify feedback/suppression. `mail` requires a completed receipt and remains a separate plan/apply step. A successful SES submission is not proof of inbox delivery.

## Interruptions and rollback

Keep every plan and state update. Do not edit completed state to bypass a dependency. After interruption inspect current AWS resources before retrying; a successful resource creation with an interrupted local response may be recoverable through its matching tag and exact parameters. A conflicting object, policy, stack update, account or DNS snapshot requires a reviewed reconciliation, never a force flag.

This helper intentionally has no delete, destroy, database reset, queue resend or automated rollback command. To roll back traffic, stop new delivery work first and prepare the exact reverse DNS/code/data plan from preserved records. Reconcile external publications and mail acknowledgements before any old queue restore. Do not attach old code to a newer incompatible schema. Existing resources and original backup copies remain retained until acceptance and an explicit retention decision.

If authoritative delegation also changed, retain the former DNS provider/zones for at least 48 hours after delegation and through longer observed cache/acceptance periods. Use the branded nameserver guide's rollback procedure to keep delegation and DNSSEC keys/parent DS consistent. Reversing website A/AAAA records, reverting registrar nameservers and restoring application data are different actions; select the one the failure requires rather than resetting all three.

## Primary references

Checked against AWS primary documentation during implementation; the selected live versions/resources still need preflight at deployment.

- CloudShell VPC access and persistence: https://docs.aws.amazon.com/cloudshell/latest/userguide/using-cshell-in-vpc.html
- CloudFormation change sets: https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/using-cfn-updating-stacks-changesets.html
- Change-set replacement/action fields: https://docs.aws.amazon.com/cli/latest/reference/cloudformation/describe-change-set.html
- S3 conditional upload, SHA-256 and VersionId: https://docs.aws.amazon.com/cli/latest/reference/s3api/put-object.html
- Beanstalk platform status/date: https://docs.aws.amazon.com/cli/latest/reference/elasticbeanstalk/describe-platform-version.html
- Beanstalk deployed/pending/failed configuration readback: https://docs.aws.amazon.com/cli/latest/reference/elasticbeanstalk/describe-configuration-settings.html
- Beanstalk secret JSON-key version requirement: https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/AWSHowTo.secrets.env-vars.html
- Route 53 CloudFront alias target: https://docs.aws.amazon.com/Route53/latest/APIReference/API_AliasTarget.html
