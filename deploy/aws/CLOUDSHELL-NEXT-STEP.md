# CloudShell next step — account 466401734428

This is the smallest safe live action after r6/r7 preparation.

## Read-only first

Open AWS CloudShell while signed into either:
- Waithaka Ndegwa workload account **466401734428**, or
- AfriTrace Link management account **891923193638**, provided its `OrganizationAccountAccessRole` can be assumed into 466401734428.

Upload/extract the release package, enter `source/`, then run:

```bash
bash deploy/aws/bootstrap-foundation.sh
```

The default run is read-only. It refuses any unrelated AWS account and saves its readiness evidence under `~/waithaka-aws-bootstrap/`.

## Create only IAM + network foundation

After reviewing the readiness JSON:

```bash
bash deploy/aws/bootstrap-foundation.sh --apply
```

This creates only:
- `waithaka-iam-baseline`
- `waithaka-network`

in `af-south-1` / account `466401734428`.

It deliberately does **not** create Route 53 zones, register vanity nameserver glue, create RDS, upload private recovery data, deploy the PHP site, enable workers, activate SES, or change public DNS.

Use `--two-nat` only when you have explicitly chosen the higher recurring cost for AZ-independent outbound NAT.

## Evidence to retain

Keep these generated files private:
- `account-readiness-*.json`
- `waithaka-iam-baseline-outputs.json`
- `waithaka-network-outputs.json`
- `final-caller-identity.json`

They are inputs to the later staged installer and DNS cutover workflow.
