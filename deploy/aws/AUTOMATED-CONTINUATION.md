# Automated continuation after IAM/network foundation

Current verified account: 466401734428 (`waithaka-admin`, `af-south-1`).

Run:

```bash
bash deploy/aws/continue-staging.sh
```

This is safe/read-mostly plus ACM request/reuse. It does not change DNS or create RDS.

To also create/reuse the retained S3 + RDS data foundation:

```bash
bash deploy/aws/continue-staging.sh --apply-data
```

The script stops before registrar delegation, branded nameserver activation, live DNS, CloudFront traffic, SES sending, workers, or production cutover.

External prerequisites before final cutover:
1. Complete current authoritative DNS export for both domains, including MX/TXT/DKIM/verification records.
2. Registrar access for glue/delegation to ns1-ns4.waithakandegwa.com.
3. Current web-host recovery set: database export, original application encryption key/configuration, public uploads and private media.
4. ACM DNS validation records must be authoritative and certificates must reach ISSUED.
