# AfriTrace Link AWS access evidence — 2026-10-20

This file records only access that was actually evidenced before the Waithaka Ndegwa website deployment.

## Confirmed

- AfriTrace Link management account: `891923193638`.
- A CloudShell readiness report on 2026-10-20 executed as `arn:aws:iam::891923193638:root`.
- Primary region inspected: `af-south-1`.
- GitHub OIDC provider `token.actions.githubusercontent.com` existed in account `891923193638` with audience `sts.amazonaws.com`.
- GitHub organization/repository `AfriTrace-Link/AfriTrace-Link` is readable through the connected GitHub integration.
- Pull request #2, `Create inspect-aws-identity.yml`, is merged to `main`.

## Not confirmed

- Role `AfriTraceGitHubProductionInventory` was not present in the readiness evidence.
- The merged GitHub diagnostic makes no AWS call; it only inspects GitHub OIDC claims.
- No workflow run tied to that merge proves GitHub-to-AWS role assumption.
- Cross-account assumption from `891923193638` to Waithaka account `466401734428` still requires an STS verification.

## Correct live path

Use the proven management-account CloudShell access and verify the standard Organizations role into Waithaka:

```bash
bash deploy/aws/verify-afritrace-management-access.sh
```

Then run the read-only foundation readiness check:

```bash
bash deploy/aws/bootstrap-foundation.sh
```

Only after reviewing the readiness JSON should the IAM/network foundation be created:

```bash
bash deploy/aws/bootstrap-foundation.sh --apply
```

The bootstrap refuses to continue unless the final STS identity is account `466401734428`.
