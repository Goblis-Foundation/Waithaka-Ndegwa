# Waithaka Ndegwa AWS hosting plan — account 466401734428

Prepared 21 October 2026 for the recovered Waithaka Ndegwa personal website.

## Fixed ownership boundary

- AWS Organizations management / parent account: **891923193638** (AfriTrace Link). Use it only for organization governance, consolidated billing and controlled access to the member account.
- Dedicated workload/member account: **466401734428** (Waithaka Ndegwa). All website runtime, data, DNS, media, mail and monitoring resources belong here.
- Primary workload region: **Africa (Cape Town), `af-south-1`**.
- CloudFront viewer-certificate region: **`us-east-1`**, because CloudFront ACM certificates must be in N. Virginia.
- Canonical public host: **www.waithakandegwa.com**.
- Secondary domain: **www.waithakandegwa.co.ke**, redirecting to the corresponding canonical `.com` path.
- Branded authoritative nameservers: **ns1–ns4.waithakandegwa.com**. The Route 53 reusable delegation set and the public hosted zones for both domains must be created in **466401734428**.

Do not deploy the website into 891923193638 and do not reuse account IDs, hosted zones, roles or buckets from AfriTrace Link or another project.

## Why Cape Town

AWS currently exposes Elastic Beanstalk, RDS, SES, Secrets Manager, ELB and STS in `af-south-1`. The current Elastic Beanstalk PHP branch includes PHP 8.4 on Amazon Linux 2023. The live readiness check still resolves the exact account-visible platform ARN and current MySQL 8.4 minor before a deployment plan is accepted.

Primary references checked 21 October 2026:

- AWS Organizations member-account best practices: https://docs.aws.amazon.com/organizations/latest/userguide/orgs_best-practices_member-acct.html
- Accessing member accounts / OrganizationAccountAccessRole: https://docs.aws.amazon.com/organizations/latest/userguide/orgs_manage_accounts_access.html
- Elastic Beanstalk supported platforms: https://docs.aws.amazon.com/elasticbeanstalk/latest/platforms/platforms-supported.html
- Elastic Beanstalk regional endpoints: https://docs.aws.amazon.com/general/latest/gr/elasticbeanstalk.html
- RDS region availability: https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Concepts.RegionsAndAvailabilityZones.html
- SES regional endpoints: https://docs.aws.amazon.com/general/latest/gr/ses.html
- CloudFront certificate requirements: https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/cnames-and-https-requirements.html
- Route 53 white-label nameservers: https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/white-label-name-servers.html

## Access model

AWS recommends minimizing use of the Organizations management account and using roles / IAM Identity Center for everyday administration. A member account created directly through Organizations normally receives an `OrganizationAccountAccessRole`. New member accounts created through Organizations can also have no usable root credentials by default when centralized root management is in use.

Recommended steady state:

1. Keep 891923193638 restricted to organization administration.
2. Enable/use IAM Identity Center at the organization level and grant Waithaka an administrator permission set to 466401734428 for human administration.
3. Use a project-scoped deployment role for automation later; do not use root credentials or long-lived IAM user access keys for deployment.
4. Centralize or otherwise strongly secure member-account root access in line with the organization policy.
5. Confirm the organization SCP preventing member accounts from leaving the organization or closing themselves. AWS Organizations created through the console after 10 July 2026 may already receive this protection automatically; verify rather than assume.

The supplied `account_readiness.py` is read-only. It accepts credentials already in 466401734428 or starts from 891923193638 and assumes the standard organization role into 466401734428.

## Architecture

### Global edge

CloudFront terminates public HTTPS for the four public host variants. The viewer ACM certificate is in `us-east-1`. The distribution sends dynamic requests to the regional ALB/origin and public media requests to the S3 media origin. Static assets receive an explicit cache policy; authenticated/private paths are never cached as public content.

### Cape Town application plane

`af-south-1` contains:

- two public subnets for the Application Load Balancer and NAT gateways;
- two private application subnets across two Availability Zones for Elastic Beanstalk EC2 instances;
- two isolated database subnets across two Availability Zones;
- Elastic Beanstalk PHP 8.4 AL2023 using Apache;
- RDS MySQL 8.4, exact supported minor selected by live readiness;
- Secrets Manager for the original application key and dedicated DB credentials;
- S3 public-media and private-media/proof buckets;
- SES and SNS for application email delivery/feedback once sender identity and production access are verified;
- CloudWatch logs/health.

The network template defaults to one NAT gateway to avoid silently committing to the recurring cost of two. For a production design that must retain outbound access when one AZ fails, set `UseTwoNatGateways=true` after reviewing cost. This does not change the two-AZ ALB/app/database placement.

### IAM baseline

`iam-baseline-stack.json` creates only the workload roles required by Elastic Beanstalk:

- `waithaka-eb-service-role` with AWS's current enhanced-health and managed-update policies;
- `waithaka-eb-instance-role` with `AWSElasticBeanstalkWebTier`;
- `waithaka-eb-instance-profile`.

The existing reviewed installer adds a narrowly scoped runtime inline policy for the exact Secrets Manager secret and S3 media prefixes. Do not attach broad AdministratorAccess to the instance role.

## Execution order

### 1. Confirm member-account access (read-only)

From a workstation or CloudShell session with AWS CLI v2 and a management-account profile:

```bash
umask 077
python3 deploy/aws/account_readiness.py \
  --profile YOUR_891923193638_PROFILE \
  --out /private/waithaka/account-readiness.json
```

The helper checks the entry account and refuses any account other than 891923193638 or 466401734428. When starting in the management account it assumes:

```text
arn:aws:iam::466401734428:role/OrganizationAccountAccessRole
```

Override `--role-name` only when the account was created with a deliberately different organization role.

Review the JSON. It checks account identity, available AZs, the PHP 8.4 Beanstalk branch, MySQL 8.4 engine versions, SES account state, existing Route 53 zones, matching CloudFront aliases, matching ACM certificates, IAM role existence, the CloudFront origin-facing managed prefix list and CloudTrail visibility. It performs no mutations.

### 2. Establish human access and governance

Before creating resources:

- confirm the account is active in AWS Organizations;
- confirm owner/recovery email, alternate contacts and organization tags;
- establish IAM Identity Center access or another approved role-based human-access route;
- confirm centralized root-access posture and the relevant organization SCPs;
- check whether an organization trail / GuardDuty / Security Hub baseline already applies from the parent. Do not duplicate organization controls inside the workload account without reviewing the existing organization setup.

### 3. Create workload IAM roles

In **466401734428**:

```bash
aws cloudformation deploy \
  --profile waithaka-ndegwa-prod \
  --region af-south-1 \
  --stack-name waithaka-iam-baseline \
  --template-file deploy/aws/iam-baseline-stack.json \
  --capabilities CAPABILITY_NAMED_IAM
```

Read back the three outputs and verify role trust/policies before moving on.

### 4. Create the Cape Town network baseline

Discover the current AWS-managed CloudFront origin-facing prefix list in `af-south-1`:

```bash
aws ec2 describe-managed-prefix-lists \
  --profile waithaka-ndegwa-prod --region af-south-1 \
  --filters Name=prefix-list-name,Values=com.amazonaws.global.cloudfront.origin-facing
```

Deploy the network template using the returned prefix-list ID. Example with one NAT gateway:

```bash
aws cloudformation deploy \
  --profile waithaka-ndegwa-prod --region af-south-1 \
  --stack-name waithaka-network \
  --template-file deploy/aws/network-stack.json \
  --parameter-overrides \
    CloudFrontOriginPrefixListId=pl-REPLACE \
    UseTwoNatGateways=false
```

For dual-AZ outbound resilience, use `UseTwoNatGateways=true` after cost review. Record VPC, subnet and security-group outputs in the private production install configuration.

### 5. Prepare TLS certificates

Create/validate in **466401734428**:

- `us-east-1`: CloudFront viewer certificate covering `waithakandegwa.com`, `www.waithakandegwa.com`, `waithakandegwa.co.ke`, `www.waithakandegwa.co.ke` (or an equivalently reviewed certificate set supported by the edge design).
- `af-south-1`: regional certificate for `origin.waithakandegwa.com` used by the ALB.

Do not route production traffic until validation records and certificate status are confirmed.

### 6. Prepare data, secrets and recovery input

The existing r5/r6 install helper deliberately does not generate a new application key. Recover and preserve:

- current database export;
- the exact original `APP_KEY`;
- public uploads;
- private release proofs;
- private media library;
- complete current DNS exports, including MX, SPF, DKIM and verification records.

Create the application secret in Secrets Manager in `af-south-1` only after the original key and dedicated DB credentials are ready. Keep the recovery data outside source control and public S3.

### 7. Deploy the data foundation and application staging

Use `install-config.466401734428.example.json` as the starting private configuration. It is intentionally incomplete until actual stack outputs, certificates, current platform ARN, DB version and recovery paths are supplied.

The existing reviewed installer then executes its staged phases:

1. foundation/data resources;
2. media copy and database restore;
3. application/Elastic Beanstalk staging;
4. migrations and acceptance checks;
5. edge/CloudFront preparation;
6. worker and mail activation only after separate acceptance;
7. production traffic cutover.

Every live phase checks STS against **466401734428** before mutation.

### 8. Route 53 and branded nameservers

Use `branded-dns-config.466401734428.example.json`. The reusable delegation set and both hosted zones must be owned by **466401734428**. Route 53 allows the same reusable set to serve multiple public hosted zones only when the set and zones are created in the same AWS account.

The existing r5 branded DNS workflow will:

- preserve the complete reviewed DNS inventories;
- create/adopt the shared four-server delegation set;
- create `ns1`–`ns4.waithakandegwa.com` A/AAAA bindings to the actual AWS assigned nameserver addresses;
- generate exact registrar glue/host-object instructions;
- verify authoritative answers, parent delegation/glue, DNSSEC state and two public resolvers;
- gate application traffic cutover on a fresh successful report for both domains.

Order remains:

1. prepare Route 53 records in 466401734428;
2. create `.com` child/host objects (glue) at the registrar;
3. delegate `.com` to `ns1`–`ns4.waithakandegwa.com` and verify;
4. delegate `.co.ke` to those same `.com` names and verify;
5. stabilize TTLs and verify again;
6. only then use the fresh successful report in the production application cutover.

### 9. SES activation

SES is available in `af-south-1`, but every new account's actual SES sending state, verified identities, DKIM configuration and production-access status must be read from the account. Keep `MAIL_TRANSPORT=disabled` until those are verified. Then activate mail in a separate reviewed phase so the restored queue cannot send unexpectedly during staging.

### 10. Production acceptance

Do not call the site live until all of the following are true:

- public and private content from the old site reconciles with the restore;
- sitemap parses and contains only intended public URLs;
- all four domain variants have valid TLS and correct canonical redirects;
- branded delegation verification passes for both domains;
- `G-RN0YXC2WCH` appears once on public pages and the old GA ID is absent;
- Analytics Realtime / Tag Assistant confirms intended consent behavior;
- Writer login, draft isolation, media, newsletter, founder workflows and payment callbacks pass production checks;
- worker/mail queues are enabled only after their acceptance checks;
- CloudWatch/Beanstalk health is green;
- database and bucket backups/restoration are tested;
- rollback values for old DNS and application routing remain retained through the acceptance window.

## Files added for account 466401734428

- `account-target.json` — immutable account/domain/region boundary for this release.
- `account_readiness.py` — read-only management/member-account audit.
- `iam-baseline-stack.json` — Elastic Beanstalk service and runtime roles.
- `network-stack.json` — two-AZ VPC/subnet/routing/security baseline.
- `install-config.466401734428.example.json` — target-pinned production installer configuration.
- `branded-dns-config.466401734428.example.json` — target-pinned branded DNS configuration.

These additions prepare the workload account but do not create resources by themselves.

## One-command CloudShell bootstrap added in r7

`bootstrap-foundation.sh` packages the first live stage into one guarded command. Its default mode is read-only. `--apply` is limited to the reviewed IAM and network CloudFormation stacks and verifies the final stack states before declaring completion. It refuses an AWS caller outside the management/workload account pair and stops before all data, DNS, website traffic and SES operations.

## R13 post-foundation stage

After `waithaka-iam-baseline` and `waithaka-network` are complete, use `prepare-data-and-certificates.sh`.
The read-only default resolves actual stack outputs and current platform/database capabilities. Certificate requests and the billable persistent data stack are separately gated.

The certificate step requests/reuses:
- `us-east-1`: viewer certificate for `www.waithakandegwa.com`, `waithakandegwa.com`, `www.waithakandegwa.co.ke`, `waithakandegwa.co.ke`;
- `af-south-1`: origin certificate for `origin.waithakandegwa.com`.

It writes ACM DNS-validation CNAMEs to private CloudShell evidence and makes no DNS change. CloudFront viewer certificates must be in `us-east-1`.

The data step deploys `waithaka-ndegwa-production-466401734428-data` using the reviewed `data-stack.json` and the actual network outputs. It defaults to `db.t4g.small`, selects the latest visible MySQL 8.4 minor only after confirming that class/version combination is orderable in Cape Town, enables RDS deletion protection and backups, and creates retained/versioned public/private/release S3 buckets. It does not restore data or generate application secrets.
