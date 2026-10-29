# Start Here 502 / AWS production release blocker — 29 October 2026

## Observed evidence

The owner's captured page for `/start-here` displayed **502 Bad Gateway** during a release. Issue #100 records the first incident. The latest release (workflow [40835576114](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40835576114), commit `cc8461d6277551e4d75760d85768781d1ddd9206`) failed. Its Elastic Beanstalk events show an explicit authorization denial for `autoscaling:TerminateInstanceInAutoScalingGroup` on the existing environment's Auto Scaling group. The earlier immutable-deployment approach also required ungranted `cloudformation:CreateStack` on a temporary EB stack.

The release uses the `RollingWithAdditionalBatch` policy to retain the existing serving instance while preparing a new instance. This action is required to retire the old serving instance. A missing permission blocks successful completion; an AWS health value alone cannot establish uninterrupted public access. Do not use an all-at-once deployment as a workaround.

## Code remediation

- `bootstrap-automation.py` now includes precisely `autoscaling:TerminateInstanceInAutoScalingGroup` for the existing environment ASG `arn:aws:autoscaling:af-south-1:466401734428:autoScalingGroup:*:autoScalingGroupName/awseb-e-8sjvpauytq-stack-*`, restricted to `aws:RequestedRegion=af-south-1`. It does not give `autoscaling:*` or access to unrelated ASGs.
- `repair_rolling_role.py` provides a smaller **one-time, admin-operated** update to the existing `WaithakaScopedAutomation` inline policy on role `WaithakaGitHubDeployRole`. It retains all other statements, refuses conflicts, defaults to **dry run**, checks account `466401734428`, and verifies an exact policy read-back after applying.
- Offline tests validate scope, idempotence, preservation of existing grants and rejection of a conflicting broader statement. No AWS policy was changed by this repository PR.

## Controlled privileged handoff

Use an existing AWS administrator session for account **466401734428**. From this checked-out repository, **review the diff first**, then run:

```bash
python3 -B deploy/aws/repair_rolling_role.py
python3 -B deploy/aws/repair_rolling_role.py --apply
```

The script is intentionally not run by untrusted pull requests or a deployment workflow, which cannot grant itself new permissions. Explicit AWS IAM administrator authorization and successful read-back are prerequisites for another release attempt. If organization SCPs, permission boundaries or explicit denies override the inline role policy, resolve them with the AWS account owner. Do not paste secrets into GitHub or chat.

## Proving actual restoration

After an administrator applies the permission, run one controlled production deployment with the release continuity guard. Confirm before changing code that `/healthz`, `/` and `/start-here` each return HTTP 200 and Start Here contains all five required source-content markers. During the update, retain the external monitor's timestamped 5-second GET-only samples and raw failure count. Afterward, confirm Elastic Beanstalk Ready/Green at the **new** version, both frontend and origin targets, no 502/blank responses, independent browser acceptance and the correct live Start Here content. Review ALB/instance/proxy logs for the cause if symptoms persist.

The existing workflow deliberately refuses to start new deployment operations if its initial health/Start Here sample is not already valid. Do not bypass the guard, start a competing deploy or reactivate the outbound worker/email queue. Preserve the full 33-area register and open issues #70–76 and #100. A successful IAM policy patch **does not by itself certify website restoration**.
