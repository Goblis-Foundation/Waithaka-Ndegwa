# GitHub repository handoff

Target repository: `iwaithakaw/WaithakaNdegwa`.

The repository exists and the connected integration can read its metadata. A 2026-10-21 write attempt through the installed GitHub integration returned:

`403 Resource not accessible by integration`

The same permission boundary blocked both repository-content writes and issue creation. Therefore no source file, workflow, issue, secret, or branch change should be represented as having been made through that connector.

The full tested source remains in the Waithaka release archive. Once GitHub content-write permission is available, the complete `source/` tree should be pushed to this repository, excluding:

- production database exports;
- `app/config.php` or equivalent private configuration containing secrets;
- the application encryption key;
- private uploads and proof files;
- provider credentials or AWS credentials;
- generated local test state.

After source migration, protect `main`, create a protected `production` environment, and only then establish a project-scoped GitHub OIDC role in AWS account `466401734428` with an exact repository/environment subject. Do not use a wildcard subject.
