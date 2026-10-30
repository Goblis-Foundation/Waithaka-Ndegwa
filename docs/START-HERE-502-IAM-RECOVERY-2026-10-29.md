# AWS production release repair — inline-policy quota follow-up

## Current blocker and corrected implementation

On 30 October 2026 the owner used an `AWSReservedSSO_AdministratorAccess` session in the correct account, `466401734428`, to run the pinned repair. AWS rejected `PutRolePolicy` with `LimitExceeded`: the role had reached its aggregate 10,240-character inline-policy limit. The failed write did not add the permissions, and the subsequent `--verify-only` correctly failed. This is a size-limit failure, not a wrong administrator account.

The revised `deploy/aws/repair_rolling_role.py` creates or reuses the exact customer-managed policy `WaithakaRollingDeploymentRepair` and attaches it only to `WaithakaGitHubDeployRole`. It **never writes, deletes or replaces an inline policy**. All existing inline statements, SIDs, allows, denies, resources and conditions remain intact. Adding another inline policy or minifying whitespace would not solve the aggregate quota.

The small managed policy contains:

- `autoscaling:TerminateInstanceInAutoScalingGroup`, restricted to the existing `awseb-e-8sjvpauytq-stack-*` Auto Scaling group in `af-south-1` and account `466401734428`.
- `iam:GetRolePolicy` and `iam:ListAttachedRolePolicies`, restricted to `WaithakaGitHubDeployRole`.
- `iam:GetPolicy` and `iam:GetPolicyVersion`, restricted to `arn:aws:iam::466401734428:policy/WaithakaRollingDeploymentRepair`.

It grants no IAM create, update, attach, detach or delete rights to the deployment role. The administrator performs policy creation and attachment. The repair refuses a conflicting policy rather than updating its default version, rejects deployment-role self-grants, verifies the exact active document and actual role attachment, and rereads the active version. A stopped create/attach can be rerun: an existing exact policy is reused. Existing successful legacy inline repairs remain supported without mutation or new read requirements.

`bootstrap-automation.py` leaves these repair statements out of its large inline policy and invokes the same administrator repair after creating/updating the role. The targeted repair is the intended operation for this incident; do not rerun the full account bootstrap.

## Owner CloudShell continuation

Fetch and check out the newly reviewed Git revision before running these commands; the older `7b0ac98` script still uses the failed inline approach. The session must be the account's authorized administrator, not the GitHub deployment role.

```bash
python3 -B deploy/aws/repair_rolling_role.py
python3 -B deploy/aws/repair_rolling_role.py --apply
python3 -B deploy/aws/repair_rolling_role.py --verify-only
```

The first command previews the exact managed document without writes. Apply creates/attaches only that policy, then reads it back. Verification must report the exact managed repair attached to `WaithakaGitHubDeployRole`. If creation succeeded but a later read or attachment failed, retry explicitly; no inline rollback or deletion is necessary. Conflicting managed content or an IAM denial requires investigation, not automatic overwrite. Avoid concurrent administrative edits during this operation; IAM does not provide transactional attachment/version verification.

After successful verification, rerun the **new production workflow from the corrected Git revision**. An old workflow attempt uses its original checked-out script and will continue looking only for inline statements. The new release preflight checks the exact managed default version and attachment before DNS recovery or an environment update. It still accepts an already-correct legacy inline repair. Stored allows do not override SCPs, boundaries or explicit denies, and policy verification alone is not a deployment.

## Validation and acceptance

Offline regressions exercise a near-limit inline policy, preservation of all original grants, exact managed scope, dry-run/read-only behavior, creation and attachment idempotence, interrupted attempts, access denial, conflicting/default-version content, incomplete attachment inventories, creation races and temporary-file cleanup. The standard PHP/MySQL CI and production validation runner includes the suite. No real AWS changes are made by these tests.

Keep incident #100 open until a guarded rollout produces the new version, Ready/Green status, uninterrupted sampled public content and all post-deployment checks. Retain all 33 work areas. Do not bypass the preflight, use an all-at-once release, release paused outbound queues or claim the new code is live based on the old site's health.

Official references:
- https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_iam-quotas.html
- https://docs.aws.amazon.com/IAM/latest/APIReference/API_AttachRolePolicy.html
- https://docs.aws.amazon.com/cli/latest/reference/iam/get-policy-version.html

## Historical incident evidence

The owner's captured page for `/start-here` displayed **502 Bad Gateway** during a release. Issue #100 records the first incident. The latest release (workflow [40835576114](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40835576114), commit `cc8461d6277551e4d75760d85768781d1ddd9206`) failed. Its Elastic Beanstalk events show an explicit authorization denial for `autoscaling:TerminateInstanceInAutoScalingGroup` on the existing environment's Auto Scaling group. The earlier immutable-deployment approach also required ungranted `cloudformation:CreateStack` on a temporary EB stack.

The release uses the `RollingWithAdditionalBatch` policy to retain the existing serving instance while preparing a new instance. This action is required to retire the old serving instance. A missing permission blocks successful completion; an AWS health value alone cannot establish uninterrupted public access. Do not use an all-at-once deployment as a workaround.
