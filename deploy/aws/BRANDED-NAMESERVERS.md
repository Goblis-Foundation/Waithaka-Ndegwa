# Branded Route 53 nameservers

Status: implementation and migration instructions prepared for review. No AWS delegation set, hosted zone, registrar host object, DNSSEC setting or live delegation has been changed by this release. Complete the account and DNS checks below against the actual services before cutover.

Use this guide for the nameserver migration, [INSTALL-CUTOVER.md](INSTALL-CUTOVER.md) for the application installation and address-record cutover, and [the AWS migration runbook](../../docs/AWS-MIGRATION.md) for recovery and staging. Changing authoritative DNS and moving the website to CloudFront are separate operations. Preserve the current website and mail destinations while preparing the new DNS service.

## Intended layout

Both domains will use exactly these four nameservers:

```text
ns1.waithakandegwa.com
ns2.waithakandegwa.com
ns3.waithakandegwa.com
ns4.waithakandegwa.com
```

Create or adopt **one reusable Route 53 delegation set** and use it for two separate **public** hosted zones in the same AWS account: `waithakandegwa.com` and `waithakandegwa.co.ke`. The names label AWS-operated authoritative servers; they are not four new web servers. Sharing nameservers does not merge the two zones or redirect one website to another. The application/edge retains `www.waithakandegwa.com` as its canonical host.

The underlying AWS nameservers remain assigned to the zones. Map each one to one vanity hostname and persist that mapping. Read the actual delegation set and resolve each assigned AWS hostname's A and AAAA records. Use those addresses for the matching vanity records and registrar glue. Never substitute the old website IP, an ALB/CloudFront address, or an address from an AWS documentation example. See [CreateReusableDelegationSet](https://docs.aws.amazon.com/Route53/latest/APIReference/API_CreateReusableDelegationSet.html) and [GetReusableDelegationSet](https://docs.aws.amazon.com/Route53/latest/APIReference/API_GetReusableDelegationSet.html).

## Dated public observations are not an account inventory

The [public DNS/HTTP inventory](../../tests/evidence/public-dns-http-2026-10-21.json) observed the following on **21 October 2026, 18:57–18:59 UTC**:

| Observation | Meaning and limit |
| --- | --- |
| Recursive answers for `.com` returned `sns00005.webhostbox.net` and `sns00006.webhostbox.net`; the apex A answer was `66.116.209.139` and `www` pointed to the apex | Preserve the existing service while preparing Route 53. These answers do not identify an AWS account or prove the origin's hosting platform. |
| Both recursive services returned NXDOMAIN for `.co.ke` apex NS/DS and `www` A queries | Public DNS was absent in those observations. This does not prove whether the domain is registered, owned, available or recoverable. Check its registrar account. |
| Queries for the four vanity names returned NXDOMAIN | They were not resolving through the observed public DNS. This does not establish whether registrar host objects already exist. |
| No `.com` DS or DNSKEY answer was observed | This is not a complete DNSSEC audit. Direct parent/authoritative queries failed from the research environment; no independent cryptographic validation was performed. |
| The successful `.com` homepage HTML contained `G-WCKVHN823V` in its Google loader/configuration | The observed live page differs from the release setting `G-RN0YXC2WCH`. Recheck after application cutover. The HTML observation did not execute JavaScript or verify analytics reception/account access. |

The `.co.ke` HTTP 503 came from the research network's upstream-connection failure; it was not a verified application response. Registry RDAP was unavailable in that observation. Re-run current checks from the deployment environment. A bounded public lookup cannot enumerate unknown DKIM selectors, verification labels or all subdomains and must never replace a complete provider export.

## External inputs and access

Prepare these before any mutation:

- The intended AWS account, explicit CLI profile and region, deployment identity and a private directory for plans, exports and journals. Use separate deployment permissions; the application's runtime role does not need DNS administration.
- Access to the current authoritative provider for **each** domain, plus a complete zone export or equivalent complete record inventory. If `.co.ke` has no provider configuration, verify that state in its account rather than treating NXDOMAIN as an empty-zone authorization.
- Access to the `.com` registrar to register/update child nameserver hosts and glue, change domain delegation, and inspect/manage DNSSEC. Access to the `.co.ke` registrar to change its delegation and inspect/manage its DS records. These may be different registrars from the DNS provider.
- Existing Route 53 zone IDs, private/public status, account ownership, their assigned AWS servers and any reusable-set ID. Resolve duplicate same-name zones deliberately; do not select the first matching name.
- Current parent delegation, glue/host-object information, DS records, DNSKEY/signing state, and the original NS/DS/record TTLs. Record the time each check was made and retain the previous registrar values.
- The existing website, origin, mail and verification destinations; the approved target CloudFront distribution for the later application cutover; and monitoring access for actual website and mail operation.

Keep complete exports, registrar evidence and journals outside the application document root, git and source ZIPs. Use a private directory (`0700`) and private files (`0600`). The supplied scripts do not recover registrar credentials, register a domain, transfer registration, or automatically write to a registry.

## Existing zones and infrastructure ownership

`CreateReusableDelegationSet` can mark the assigned set of an existing public hosted zone reusable by accepting its `HostedZoneId`. Use that adoption path only after verifying the exact zone/account and reading back the resulting assignment. To move an existing zone to a **different** set, prepare a replacement zone and copy its records; editing an apex NS record does not change Route 53's underlying server assignment.

Same-name zones, and parent/child zones, cannot share any assigned nameserver while both exist. A parallel replacement must use a non-overlapping set. The `.com` and `.co.ke` zones themselves are not a parent/child pair. See the [official conflict contract](https://docs.aws.amazon.com/botocore/latest/reference/services/route53/client/exceptions/ConflictingDomainExists.html).

Current CloudFormation supports [`AWS::Route53::HostedZone.DelegationSetId`](https://docs.aws.amazon.com/AWSCloudFormation/latest/TemplateReference/aws-resource-route53-hostedzone.html); changing it requires **replacement**. Do not apply that update casually to a live zone. This release's application/data/edge stacks remain separate from the DNS migration. Choose one owner for DNS resources, retain them through replacement/deletion decisions, and do not manage the same records through both a stack and an independent script without an explicit handover.

## Preserve the entire DNS configuration

Export every record and preserve names, types, values, TTLs and routing metadata. Include MX, SPF/TXT, DKIM, DMARC, verification tokens, CAA, certificate-validation CNAMEs, SRV, child-zone NS/DS, aliases, health checks, policy identifiers and all application subdomains. Preserve mail configuration first; a DNS move is not permission to redesign an existing mail policy.

For Route 53 exports, follow pagination to completion and avoid record changes while exporting. [`ListResourceRecordSets`](https://docs.aws.amazon.com/Route53/latest/APIReference/API_ListResourceRecordSets.html) can include pending values, so compare the configuration and check authoritative responses separately. Public `dig` queries, including `ANY`, are not a complete backup.

When creating a replacement zone, handle its generated apex NS/SOA separately. Preserve child delegations. Rewrite an alias's zone ID only when the target is another record in the old zone; aliases to AWS resources retain that resource's hosted-zone ID. Preserve or explicitly reconcile health-check/Traffic Flow dependencies. Do not silently drop an unsupported record or routing policy. Compare old and new inventories before delegation. AWS documents these distinctions in its [hosted-zone migration procedure](https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/hosted-zones-migrating.html).

## DNSSEC and cache prerequisites

Check DNSSEC independently for each domain. Sharing a delegation set does not share a domain's parent DS relationship.

**If replacing a signed zone/provider/key with one that cannot satisfy the existing DS**, first record the old DS and TTL, remove that DS through the parent/registrar, verify its removal at the parent, and wait out its previous TTL before changing to the incompatible signed state. Inspect delegated child chains too. Do not disable signing while a validating resolver can still hold the old DS. Follow AWS's [ordered DNSSEC removal procedure](https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/dns-configuring-dnssec-disable.html).

If the same hosted zone and signing keys are retained, changing their displayed nameserver names alone is not a reason to replace keys or disable DNSSEC. Verify the existing chain and record that decision. If no DS exists, there is no old DS to remove, but current authoritative/parent evidence is still needed. A timed-out query or a stale recursive result is not a verified absence.

Lower migration TTLs in advance and wait for the former cache lifetime. Record both parent delegation and in-zone NS TTLs; a short child-zone TTL does not shorten a registry-controlled parent TTL or evict old cached answers. AWS's [active-domain migration procedure](https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/migrate-dns-domain-in-use.html) allows for the commonly cached two-day NS interval. Use the measured intervals where longer, including previous negative-cache TTLs when adding previously absent names.

After a replacement, configure signing on the final zone and verify its signed responses before publishing its new DS through the registrar. Wait the documented cache intervals and verify the chain. Do this separately for both domains using the actual returned key data; do not reuse a DS merely because the nameservers match. See [enabling DNSSEC and establishing trust](https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/dns-configuring-dnssec-enable-signing.html).

## Helper configuration and complete exports

Run the helper from the release's source directory with Python 3.10 or newer on Linux. Its AWS wrapper requires AWS CLI v2.38 or newer, using the configured identity; address capture and DNS verification require an existing BIND `dig` executable. Verification needs working direct DNS port-53 access and access to the queried recursive resolvers; `dig` may fall back to TCP. IPv6 address records and glue are mandatory, but this probe queries IPv4 endpoints and does not require or establish IPv6 transport reachability. The scripts do not install packages or create registrar credentials.

Copy [branded-dns-config.example.json](branded-dns-config.example.json) to a private path outside the source tree and replace every `REPLACE` value before provisioning. It deliberately fails until the real account, region, profile and paths are supplied. Its `schema_version` is `1`; provide `deployment_id`, `account_id`, `region`, `profile`, `state_dir`, `delegation_set`, and a `zones` entry for each of the two exact domains. Each zone has `hosted_zone_id` and `source_export`. The delegation-set selection is explicit:

| Configuration | Behavior |
| --- | --- |
| `delegation_set.id` and `delegation_set.adopt_zone_id` both empty | Request a new reusable set |
| Only `delegation_set.id` populated | Reuse the specified set after verification |
| Only `delegation_set.adopt_zone_id` populated | Make that existing public zone's assigned set reusable |

An empty zone `hosted_zone_id` requests a new zone on the chosen set. A populated ID must already be physically assigned to that same set; the helper refuses a mismatch instead of rewriting its apex NS to conceal it. For adoption, also supply that exact zone ID in the corresponding `zones` entry so the existing zone is explicitly retained. Select a replacement zone deliberately if migration to a different set is required. Returned IDs are stored in `state_dir/branded-dns-state.json`: **do not fill those blanks or edit the configuration after provisioning**. Keep this DNS journal separate from the application installer's journal.

| Command | Network/mutation boundary |
| --- | --- |
| `provision-plan`, `plan` | Offline; read the local configuration, journal and required snapshots/exports |
| `export` | Read-only AWS inventory for the selected account/zone |
| `capture` | Read-only AWS and DNS lookups; records the observed mapping in the private local journal |
| `provision` | Creates/adopts the reviewed AWS delegation set and missing zones |
| `apply` | Writes only the reviewed Route 53 record batches, with AWS/DNS preflight and read-back |
| `verify` | Reads AWS state and performs DNS probes; saves observations and updates local verification state |

No command writes registrar hosts, delegation or DS records. `dns_probe.py` is the probe library used by `verify`; it has no separate operator CLI.

For an existing Route 53 zone, capture a complete source export with the real source ID:

```bash
umask 077
python3 deploy/aws/branded_dns.py --config /private/waithaka/dns-config.json \
  export --domain waithakandegwa.com --zone-id THE_VERIFIED_SOURCE_ZONE_ID \
  --output /private/waithaka/source-com.json
```

This is an explicit read from the configured AWS account. Repeat for `.co.ke` if it already has a zone there. For an external provider or a different source account, obtain its complete export separately, retain that original, and faithfully convert it to Route 53 resource-record-set JSON with wrapper fields `schema_version: 1`, `domain`, `source_provider`, `source_zone_id`, `exported_at`, `records`, and `review`. `source_zone_id` is the old Route 53 zone ID when applicable; use an empty string for an external provider rather than inserting its unrelated internal identifier. Keep that provider's identity in the retained original evidence.

The review object includes `reviewed_by`, `domain_control_confirmed`, `complete_zone_export`, `dnssec_transition_reviewed` and `new_zone`. Exported review checks start false. Complete them only from actual account/export/DNSSEC review, and retain its evidence. These fields are operator attestations; they are not independent tests of ownership, completeness or elapsed DNSSEC TTLs.

An existing `.com` export may not be empty. A genuinely new `.co.ke` zone may use `records: []` and `new_zone: true` only after its registration/control and absence of a previous zone have been verified. NXDOMAIN alone does not satisfy that review. Exports must be no more than seven days old, with timezone-aware `exported_at` values, and be reviewed again if the provider changes. Source export files are needed for preparation, although provisioning the empty AWS resources can be planned before the files are ready. Traffic Flow-managed records require a separate policy migration and are refused by the raw-copy workflow.

## Provision and prepare the AWS records

Use new output paths for reviewed artifacts. Review the exact account, selected set/adoption, zones and resulting change plan; pass its printed **Reviewed content SHA-256** unchanged to the corresponding mutation command. This is the digest of canonical JSON content, **not** the result of running `sha256sum` on the formatted file. The helper checks the selected STS account and rebuilds the relevant plan before mutation.

```bash
python3 deploy/aws/branded_dns.py --config /private/waithaka/dns-config.json \
  provision-plan --output /private/waithaka/plans/dns-provision.json
python3 deploy/aws/branded_dns.py --config /private/waithaka/dns-config.json \
  provision --plan /private/waithaka/plans/dns-provision.json \
  --sha256 THE_EXACT_PRINTED_64_CHARACTER_HASH
```

Then capture the actual AWS state and prepare the reviewed DNS records. A capture requires completed provisioning; its snapshot must be no more than 24 hours old for the record plan:

```bash
python3 deploy/aws/branded_dns.py --config /private/waithaka/dns-config.json \
  capture --output /private/waithaka/dns-state-before-prepare.json
python3 deploy/aws/branded_dns.py --config /private/waithaka/dns-config.json \
  plan --snapshot /private/waithaka/dns-state-before-prepare.json \
  --mode prepare --output /private/waithaka/plans/dns-prepare.json
python3 deploy/aws/branded_dns.py --config /private/waithaka/dns-config.json \
  apply --plan /private/waithaka/plans/dns-prepare.json \
  --sha256 THE_EXACT_PRINTED_64_CHARACTER_HASH
```

Preparation creates missing nonmanaged source records and refuses conflicting existing values. It manages the apex NS/SOA and the four vanity address pairs separately; it is not a general overwrite/import command. The preparation TTL is 60 seconds for managed NS/A/AAAA and the SOA negative-cache minimum. It preserves other records and refuses an unreviewed conflict. Resolve the actual source/target discrepancy and prepare a new plan rather than deleting records to make the plan pass.

Apply checks both full zones, the pinned server/address mapping and DNSSEC state for drift before writing, then journals each API intent and returned change ID and waits for authoritative `INSYNC`/record read-back. Resume an interrupted submission with the **same saved plan and hash**. That exact started plan may outlive the initial snapshot/export age limit, but its configuration and source-export hashes must still match, and fresh AWS/address/full-zone/DNSSEC checks still run. A new plan or readiness report cannot bypass another plan's unfinished submitted change. Keep every reviewed plan with its journal; do not edit either to clear an interruption.

If an accepted response was lost, recovery can restate identical reviewed values only when the complete observed zone equals that batch's expected result, then save a new change ID and wait for `INSYNC`. Unrelated drift still stops recovery. New, unstarted plans require fresh evidence. Stabilization pins the verification that admitted it so its exact interrupted plan remains recoverable; a new stabilization plan needs a current successful full observation. A failed verification clears prior local stabilization readiness, including lookup failures and attempts blocked by pending changes. A report or completed AWS batch does not perform the following registrar handoff.

## Registrar handoff and ordered cutover

AWS's [white-label procedure](https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/white-label-name-servers.html) calls for the following final DNS layout:

- Four A and four AAAA records in **`waithakandegwa.com` only**, one pair per vanity hostname, using each matching assigned AWS server's addresses and simple routing.
- The four vanity hostnames in each zone's apex NS record.
- A vanity hostname, such as `ns1.waithakandegwa.com.`, as the first hostname in each SOA. Preserve other SOA fields except deliberately reviewed TTL changes.

Create/update the matching four registrar host/glue entries under `.com`, with the respective IPv4 and IPv6 addresses. Then change `.com` delegation, verify it, and only then change `.co.ke` to the same four `.com` names. Do not add duplicate vanity A/AAAA records in the `.co.ke` zone. Keep the former service available during cache expiry.

Registrar terminology varies: child nameservers, host objects, private nameservers and glue may describe the relevant control. A normal A/AAAA record in a hosted zone is not the registrar host registration. See [AWS's registrar/glue guidance](https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/domain-name-servers-glue-records.html). KeNIC documents separate registrar operations for [domain nameservers](https://support.kenic.or.ke/kb/faq.php?id=40) and [DS records](https://support.kenic.or.ke/kb/faq.php?id=46); use the actual accredited registrar's process and verify its accepted result.

After changing `.com` delegation, request its focused verification:

```bash
python3 deploy/aws/branded_dns.py --config /private/waithaka/dns-config.json \
  verify --domain waithakandegwa.com \
  --output /private/waithaka/dns-com-verification.json
```

Only continue the registrar handoff to `.co.ke` after resolving failed or incomplete `.com` checks. After both delegations are ready, run the full verification:

```bash
python3 deploy/aws/branded_dns.py --config /private/waithaka/dns-config.json \
  verify --output /private/waithaka/branded-delegation-report.json
```

Verification requires a completed record apply and performs a fresh AWS capture. It derives expected DS values from `GetDNSSEC`: exactly the active KSKs when `ServeSignature` is `SIGNING`, or no DS for an unsigned target. There is no operator-configurable replacement for that expected key state. A signed target without its expected parent DS fails this strict report even during an intentional trust transition. Complete the coordinated registrar trust transition before expecting a successful report; the verifier does not remove or install DS records.

The verifier checks the exact current AWS address binding, authoritative zone answers, two discovered parent endpoints' NS/glue and expected DS values, plus the recursive resolvers `1.1.1.1` and `8.8.8.8`. It does not enumerate every parent endpoint or resolver. DS equality is **not independent cryptographic DNSSEC-chain validation**. A missing executable, blocked required DNS path, timeout or incomplete answer cannot count as successful verification. Perform a separate validating check when DNSSEC is enabled and a separate transport check if verifying IPv6 reachability. `verify` returns exit status 1 for failed/incomplete checks; inspect the saved checks/errors when a report was produced.

Provide the completed full report path through `dns.branded_delegation_report` in the application installer's private configuration for this **production Route 53** cutover. The accepted report must be schema version 1, kind `waithaka-branded-dns-verification`, scope `authoritative-parent-recursive`, have `ok: true`, nonempty successful checks and no errors, match the exact account/both zone IDs/four nameservers, and have a UTC `checked_at` no more than 24 hours old or 60 seconds in the future. A single-domain report is useful during the ordered transition; it does not establish both-domain readiness. Keep the path stable before preparing the installer's journal. The installer still requires its application/traffic acceptance evidence; this DNS report does not replace it. A refreshed report changes the evidence hash and requires a fresh cutover plan.

After full verification and the acceptance/cache window, prepare a new stabilization plan from a new capture:

```bash
python3 deploy/aws/branded_dns.py --config /private/waithaka/dns-config.json \
  capture --output /private/waithaka/dns-state-before-stabilize.json
python3 deploy/aws/branded_dns.py --config /private/waithaka/dns-config.json \
  plan --snapshot /private/waithaka/dns-state-before-stabilize.json \
  --mode stabilize --output /private/waithaka/plans/dns-stabilize.json
python3 deploy/aws/branded_dns.py --config /private/waithaka/dns-config.json \
  apply --plan /private/waithaka/plans/dns-stabilize.json \
  --sha256 THE_EXACT_PRINTED_64_CHARACTER_HASH
```

Stabilization requires prior successful full verification no more than 24 hours old. It preserves current nonmanaged records, uses 172800 seconds for the managed apex NS, 3600 seconds for vanity addresses and 900 seconds for the SOA negative-cache minimum. These deliberate final values do not change registry-controlled parent TTLs. Applying either mode clears the journal's prior verification, so run full `verify` again after stabilization and use its fresh report for the subsequent application cutover. Choose a new output file or preserve and retire the old report deliberately; output files are not silently overwritten.

## Completion evidence and rollback

Treat these as separate results:

| Evidence | What it establishes | What it does not establish |
| --- | --- | --- |
| AWS change is `INSYNC` | The accepted change reached Route 53's authoritative fleet | Parent delegation, registrar glue, recursive cache expiry, website or mail success |
| Direct parent/authority observations | The queried servers returned the expected delegation/glue/zone answers at that time | Every recursive cache has expired, or the application works |
| Recursive observations | Those resolvers returned the expected public answers | Complete zone preservation, registry account ownership or universal propagation |
| Verified website and mail operation | The checked application paths, TLS, redirects and controlled mail actions worked | Untested integrations or permanent availability |

[`GetChange`](https://docs.aws.amazon.com/Route53/latest/APIReference/API_GetChange.html) defines `INSYNC` narrowly. A [`ChangeResourceRecordSets`](https://docs.aws.amazon.com/Route53/latest/APIReference/API_ChangeResourceRecordSets.html) batch is atomic within that zone; a two-domain migration and registrar changes are not one transaction. Keep each accepted change ID and result so interruption recovery does not replay an unknown operation blindly.

Before considering migration accepted, compare the complete DNS inventories, query all four assigned servers, verify actual parent delegation/glue, check both domains through independent resolvers, verify DNSSEC where enabled, and monitor website and mail traffic. Use an environment with working direct DNS access; an unavailable required query is an unverified check, not a successful result. The helper's IPv6 address/glue comparisons do not establish transport reachability over IPv6.

Retain old zones/provider service, registrar values, exports and signing information for at least 48 hours after delegation changes and longer when observed TTLs or the acceptance window require it. Do not delete a still-delegated zone or its reusable set. Restore normal TTLs after acceptance, not merely after an API response.

For rollback, choose whether the fault is authoritative delegation, application address routing or application/data state. Prepare the corresponding reverse plan from preserved values. Restore a consistent delegation **and DNSSEC trust state**; old nameservers with a new incompatible DS can still fail. If application traffic or delivery processing also moved, pause the new worker/mail route and reconcile completed external operations before restoring old queues. The [installation rollback procedure](INSTALL-CUTOVER.md#interruptions-and-rollback) continues to apply.

Primary documentation checked 21 October 2026. The dated observation above is evidence of that observation window only. Recheck actual AWS, registrar, parent and application state at execution time.
