# Current hosting cost target — 25 November 2026

Owner-approved target: **USD 40/month**, inclusive of existing workload charges, storage, backups, networking, tax and variable usage. Retain the current separate workload account, existing domain ownership, private application, public website, worker, database and mail configuration. The previous USD 75 cost-review target is superseded, not a historical deletion.

The existing hosting-cost-review GitHub workflow now compares micro/small/medium x86 and Graviton EC2 sizes and micro/small MySQL RDS prices in Cape Town. These catalog alternatives do not establish architecture compatibility, current deployed resources or monthly total.

## Protected acceptance sequence
1. Use existing authenticated production inventory and consolidated payer cost breakdown; reconcile actual Elastic Beanstalk, EC2, RDS, ELB, NAT, EBS, CloudFront, DNS, IPv4, backups, traffic and retained charges.
2. Compare the current layout against a single-instance or other lower-fixed-cost option, preserving private-path and cache separation, data and worker functionality. No ARM change without application/runtime acceptance. Retain rollback and do not remove HA/security controls unless a reviewed alternative meets workload acceptance.
3. Approve exact priced plan at or below USD 40 inclusive of variable reserve, then deploy through the existing GitHub AWS route and perform public and protected journey acceptance, database restore and post-change health/readback.
4. Payer budget readback and Cost Explorer actual/forecast evidence remain distinct from catalog prices. No automatic shutdown on budget alert.

No live topology or actual billed saving is claimed by this change.
