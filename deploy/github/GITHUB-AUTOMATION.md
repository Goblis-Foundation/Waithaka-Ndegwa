# GitHub automation — Waithaka Ndegwa

Canonical repository: `Goblis-Foundation/Waithaka-Ndegwa`.

Goblis Foundation is the GitHub Organization owner/control plane. The Waithaka website remains a separate personal publishing project and AWS workload; organizational repository ownership does not make the website a Goblis Foundation programme or public affiliation.

The existing empty repository `iwaithakaw/WaithakaNdegwa` is transferred and renamed rather than duplicated. GitHub repository transfer preserves the repository's numeric repository ID, issues/settings/history supported by GitHub, and gives the deployment one canonical remote.

AWS OIDC trust is scoped to all three of:

- exact numeric GitHub repository ID;
- exact numeric Goblis Foundation organization ID;
- the immutable post-transfer GitHub OIDC subject for `Goblis-Foundation/Waithaka-Ndegwa`;
- exact `main` branch.

## One-time bootstrap

Run from the reviewed release in the authenticated Waithaka AWS CloudShell (`waithaka-admin`):

1. Authenticate GitHub CLI once: `gh auth login --hostname github.com --git-protocol https --web`.
2. Ensure the authenticated GitHub identity can transfer the personal repository and create/receive repositories in `Goblis-Foundation`.
3. Run: `bash deploy/github/publish-repository.sh`.

The script transfers/renames the existing repository if needed, resolves its numeric repository ID, creates/updates the AWS GitHub OIDC provider and `WaithakaGitHubDeployRole`, restores the recovered implementation history, overlays the current release source, and pushes `main` without force-overwriting existing remote history.

After the first push, GitHub Actions validates the application and assumes `WaithakaGitHubDeployRole` using temporary OIDC credentials. No long-lived AWS access keys are stored in GitHub.


Because this transfer occurs after GitHub's 2026 immutable-subject rollout, the bootstrap resolves the post-transfer organization ID and repository ID and configures AWS for the immutable `sub` claim rather than relying on a mutable owner/repository name alone.
