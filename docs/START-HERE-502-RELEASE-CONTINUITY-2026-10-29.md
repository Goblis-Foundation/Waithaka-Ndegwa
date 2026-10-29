# Start Here 502 and zero-interruption release controls — 29 October 2026

## Observed incident and supported cause

The owner supplied a screenshot showing HTTP **502 Bad Gateway** for `https://www.waithakandegwa.com/start-here` around **06:50 UTC (09:50 Africa/Nairobi)** during favicon release [40830924840](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40830924840). The production AWS job ran 06:46:02–06:52:54 UTC, with an Elastic Beanstalk update beginning at 06:47:29 and a second Workspace mail configuration being reapplied around 06:50–06:51. The job and browser acceptance only passed **after** the visitor's failure.

The source configured a **single-instance minimum** (MinSize=1, MaxSize=2), provided no explicit application deployment policy, and sent an entire bootstrap option set with every application release, including `MAIL_TRANSPORT=disabled` and `WORKER_ENABLED=0`. It then ran another EB environment update to remove bootstrap credentials, reapplied Workspace mail, updated origin DNS, and redeployed the CloudFront stack. AWS documents that all-at-once updates can interrupt public requests. This is a demonstrable availability risk consistent with the incident; without directly recovered ALB/web-proxy logs it is not proof of the exact underlying 502 component.

See [GitHub issue #100](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/100).

## Implementation

1. The Beanstalk application template explicitly selects the **Immutable** application deployment policy and `Ok` health threshold. On an already configured healthy production environment the release script independently verifies or installs this policy **before** updating the code version. New instances are deployed while the old version remains in service. This can consume temporary extra EC2 capacity; permanent MinSize remains 1 and MaxSize remains 2.
2. Routine application releases update only the requested immutable application version. They never reapply `beanstalk-options.template.json`, disable existing mail, reset worker settings, remove first-time credentials a second time, or republish the origin ALB DNS mapping. They check that mail and worker configuration remains byte-for-byte equal before/after the release (nonsecret values only).
3. `fresh_hosting.sh` preserves an existing healthy CloudFront edge without repeating Route 53 changes, distribution deployment, or global media policy changes for each application release. Initial provisioning is still separately supported. Unexpected in-progress edge stacks fail closed rather than being replaced.
4. Existing deployments that newly require schema/bootstrap changes fail closed unless independently approved through the separate reviewed migration process. Code-only changes do not silently authorize data/schema updates.
5. The production workflow wraps the hosting release and existing Google Workspace mail handoff in `deploy/aws/release_continuity.py`. It uses bounded external GET-only requests to **/**, **/start-here** and **/healthz** before, throughout and after the operation. It rejects 502/503, missing body, incomplete Start Here journeys and failed application database health. The command is allowed to finish safely before the pipeline fails on any recorded outage. JSON artifacts are retained even if a release fails.
6. Scheduled live browser accessibility checks additionally require **substantive visible Start Here content** and links to My Thoughts, Startup Journeys, Resources, Founder Stories and Contact. A structurally valid empty main section is no longer accepted.

This is a continuity/incident fix only; it does not change the article source, sender postal address, social publishing rights, payments, user accounts, consent records or source-approved impact figures.

## Independent verification and explicit limits

- The offline release test validates current published Start Here copy, health/502 classification, detection of a mid-release 502, immutable Beanstalk policy, fixed instance capacity, DNS/SMTP preservation and the expected production guard.
- The test is included in the PHP 8.3/8.4 with MySQL 8.4 CI suite; browser, security, and live public-source checks also run on the reviewed PR.
- **A green AWS status is not zero-downtime evidence.** Immutable deployment and the continuous guard lower risk and make disruption observable, but cannot promise perfection against upstream provider outages or every subsecond failure.
- Do not mark #100 closed until a successful reviewed production rollout shows pre/during/post samples with zero failures, and separately verify post-release webpage content. Open acceptance issues #70–#76 retain their original distinct external/provider gates.
- Use the known canonical domain and AWS account 466401734428 / af-south-1. Avoid duplicate environments, unapproved permanent instance-capacity spending, releasing queued subscriber email, or disabling the current approved Workspace relay.

## Primary references

- AWS Elastic Beanstalk deployment policies: https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/using-features.rolling-version-deploy.html
- AWS environment configuration updates: https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/using-features.rollingupdates.html
- AWS immutable update caveats: https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/environmentmgmt-updates-immutable.html
