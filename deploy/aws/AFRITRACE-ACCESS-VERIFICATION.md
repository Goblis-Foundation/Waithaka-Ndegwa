# AfriTrace Link management-account access check

Confirmed project evidence as of 2026-10-21:

- AfriTrace Link AWS management account: `891923193638`.
- Yesterday's CloudShell session authenticated to that account.
- Waithaka Ndegwa member/workload account: `466401734428`.
- `AfriTrace-Link/AfriTrace-Link` is accessible through the connected GitHub integration with admin permission.
- Pull request #2 (`Create inspect-aws-identity.yml`) is merged into `main`.
- The merged workflow is manual-only and inspects GitHub OIDC claims; it deliberately makes no AWS call.
- Therefore GitHub-to-AWS role assumption is not yet verified by that workflow.

## Preferred live path now

Use the already-authenticated AfriTrace Link management account and verify the standard Organizations access role into Waithaka:

```bash
bash deploy/aws/verify-afritrace-management-access.sh
```

Expected target account: `466401734428`.

If this succeeds, continue with the existing read-only readiness check:

```bash
bash deploy/aws/bootstrap-foundation.sh
```

Then, after reviewing the generated readiness evidence, create only the IAM/network foundation:

```bash
bash deploy/aws/bootstrap-foundation.sh --apply
```

Do not proceed to DNS, RDS, SES, application traffic, or branded nameserver delegation until their separately documented prerequisites have been recovered and reviewed.

## Historical evidence

See `AFRITRACE-YESTERDAY-EVIDENCE.md` for the exact distinction between the confirmed CloudShell/root access in `891923193638` and the GitHub OIDC path that was prepared but not proven.
