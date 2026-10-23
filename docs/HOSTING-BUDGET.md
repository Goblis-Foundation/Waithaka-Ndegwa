# Hosting budget

## Current owner decision

23 October 2026: the owner accepted keeping the current architecture at the verified USD 113.22/month fixed-component baseline and instructed deployment to proceed. This supersedes the earlier USD 75/month target below. Storage, IPv4, traffic, NAT processing, ALB LCUs, CDN, DNS, secrets, logs, backups and applicable taxes are additional. USD 113.22 is not an all-inclusive invoice or hard spending cap. Retain the current one-server architecture; do not initiate a cost-reduction migration based on the superseded target.

## Superseded target and audit history

Owner instruction, 23 October 2026: keep total monthly hosting expenditure within USD 75.

This applies to the Waithaka Ndegwa workload in AWS account 466401734428, including compute, database, networking, CDN, storage, backups, DNS, monitoring and traffic. Existing AWS credits must not be used to hide the recurring cost after credits expire.

Before adding capacity or changing infrastructure, inventory live resources and estimate current regional rates. Reserve part of the budget for variable traffic, storage and logs. A target or budget alert is not a hard spending cap; do not report cost compliance without a verified estimate and usage assumptions.

Preserve the existing database, media, certificates and DNS identity. Do not delete or replace persistent resources to meet the budget. Prepare and validate any cost-reduction migration before a controlled cutover. Do not increase server count, database availability tiers or paid network services without demonstrating that the total remains within the budget.

Current status: budget recorded; cost inventory and reconciliation in progress. No AWS billing limit or budget alert has yet been configured by this change.

## Verified current baseline

Live inventory: one t3.small application instance, one db.t4g.small MySQL instance (Single-AZ, 20 GiB gp3, 14-day backups), one NAT gateway, and the retained application load balancer.

AWS public Cape Town offer files reviewed on 23 October 2026; 730 hours/month, On-Demand, before credits or taxes:

| Component | USD/month |
| --- | ---: |
| t3.small Linux | 19.78 |
| db.t4g.small MySQL Single-AZ | 29.93 |
| NAT gateway hourly charge | 41.61 |
| Application Load Balancer hourly charge | 21.90 |
| Fixed component subtotal | 113.22 |

Storage, IPv4, traffic, NAT processing, ALB LCUs, CDN, DNS, secrets, logs and applicable taxes are additional. Do not count alternative NAT products, unused RDS capacity reservations, Outposts load balancers or trust stores as separate deployed resources.

Current architecture therefore does not meet the USD 75 budget. Cost reduction must address networking and instance sizing; alerts alone cannot achieve it. Database and media preservation remain mandatory. No capacity expansion is authorized by this budget instruction.

Evidence: GitHub Actions Hosting cost review run 39911588408; resource inventory run 39911283295. Prices came directly from AWS regional offer files for AmazonEC2, AmazonRDS and AWSELB.
