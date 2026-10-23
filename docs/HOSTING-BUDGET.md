# Hosting budget

Owner instruction, 23 October 2026: keep total monthly hosting expenditure within USD 75.

This applies to the Waithaka Ndegwa workload in AWS account 466401734428, including compute, database, networking, CDN, storage, backups, DNS, monitoring and traffic. Existing AWS credits must not be used to hide the recurring cost after credits expire.

Before adding capacity or changing infrastructure, inventory live resources and estimate current regional rates. Reserve part of the budget for variable traffic, storage and logs. A target or budget alert is not a hard spending cap; do not report cost compliance without a verified estimate and usage assumptions.

Preserve the existing database, media, certificates and DNS identity. Do not delete or replace persistent resources to meet the budget. Prepare and validate any cost-reduction migration before a controlled cutover. Do not increase server count, database availability tiers or paid network services without demonstrating that the total remains within the budget.

Current status: budget recorded; cost inventory and reconciliation in progress. No AWS billing limit or budget alert has yet been configured by this change.
