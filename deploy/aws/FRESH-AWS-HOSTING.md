# Waithaka Ndegwa — fresh AWS hosting path

This is a **clean AWS installation**. It deliberately does not depend on the previous cPanel database, uploads, application key, or email transport.

## Already complete
- AWS account `466401734428` selected for the workload.
- `waithaka-iam-baseline` complete.
- `waithaka-network` complete in `af-south-1`.
- Persistent data stack complete: RDS MySQL 8.4 + retained/versioned media/release S3 buckets.
- Viewer and origin ACM certificates requested.

## One external registrar handoff
Run:

```bash
bash deploy/aws/fresh_hosting.sh --prepare-dns
```

This creates Route 53 zones under one reusable delegation set, copies currently published core `.com` DNS needed to keep the current public site/mail reachable during the handoff, adds ACM validation records, prepares Google Workspace baseline DNS, and creates the A/AAAA + NS/SOA records required for `ns1`–`ns4.waithakandegwa.com`.

It writes `~/waithaka-aws-bootstrap/REGISTRAR-HANDOFF.json`. At the registrar:
1. register glue/child nameservers for `ns1`–`ns4.waithakandegwa.com` with the supplied IPv4 and IPv6 addresses;
2. change `waithakandegwa.com` to those four nameservers;
3. change `waithakandegwa.co.ke` to the same four nameservers.

No application or database recovery from the previous host is required.

## Automated continuation after registrar update

```bash
bash deploy/aws/fresh_hosting.sh --after-registrar
```

The command waits until both domains publicly delegate to the four branded nameservers and ACM reports both certificates `ISSUED`, then it:
- creates a fresh runtime Secrets Manager secret;
- creates a fresh least-privilege database user;
- initializes the schema and all migrations;
- creates the initial Writer account for `isaac@waithakandegwa.com`;
- deploys the PHP 8.4 application into Elastic Beanstalk private application subnets;
- binds `origin.waithakandegwa.com` to the ALB;
- disables the one-time database bootstrap and removes RDS admin-secret access from the runtime role;
- deploys CloudFront and points all four public domain variants to the distribution;
- preserves `G-RN0YXC2WCH` as the public Google tag;
- leaves application mail and background delivery workers disabled for separate acceptance.

The initial Writer password is written only to `~/waithaka-aws-bootstrap/writer-initial-credentials.json` with mode `0600` in CloudShell. It is not printed.

## Google Workspace mail
Google Workspace is the selected mail platform. SES is not the primary mail transport.

```bash
bash deploy/aws/fresh_hosting.sh --workspace-status
```

The output shows the fixed NAT egress IP to authorize in Google Admin's SMTP relay service. After the relay is configured for that IP and TLS:

```bash
bash deploy/aws/fresh_hosting.sh --workspace-enable
```

The application uses `smtp-relay.gmail.com:587` with STARTTLS and IP authentication, from `isaac@waithakandegwa.com`. No SMTP password is stored. Newsletter approval remains intentionally blocked until a real postal address is provided.

Generate a 2048-bit DKIM key in Google Admin and add its TXT record in Route 53. Do not invent a DKIM value.
