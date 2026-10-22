#!/usr/bin/env python3
"""Read-only AWS readiness audit for the Waithaka Ndegwa website workload account.

This helper never creates, changes, or deletes AWS resources. It accepts either:
- credentials already in workload account 466401734428, or
- management-account credentials for 891923193638 and assumes the standard
  OrganizationAccountAccessRole (or --role-name override) into the workload account.
"""
from __future__ import annotations
import argparse, json, os, subprocess, sys, tempfile, datetime

MANAGEMENT_ACCOUNT = "891923193638"
WORKLOAD_ACCOUNT = "466401734428"
REGION = "af-south-1"
CF_CERT_REGION = "us-east-1"
DOMAINS = ["waithakandegwa.com", "waithakandegwa.co.ke"]

class AwsError(RuntimeError): pass

def run_aws(args, profile=None, env=None, allow_fail=False):
    cmd = ["aws", *args, "--output", "json", "--no-cli-pager"]
    if profile:
        cmd += ["--profile", profile]
    proc = subprocess.run(cmd, text=True, capture_output=True, env=env)
    if proc.returncode:
        if allow_fail:
            return {"ok": False, "error": (proc.stderr or proc.stdout).strip()[:800]}
        raise AwsError(f"AWS command failed ({' '.join(args[:2])}): {(proc.stderr or proc.stdout).strip()[:800]}")
    txt = proc.stdout.strip()
    return json.loads(txt) if txt else {}

def assumed_env(creds):
    env = os.environ.copy()
    c = creds["Credentials"]
    env.update({
        "AWS_ACCESS_KEY_ID": c["AccessKeyId"],
        "AWS_SECRET_ACCESS_KEY": c["SecretAccessKey"],
        "AWS_SESSION_TOKEN": c["SessionToken"],
        "AWS_DEFAULT_REGION": REGION,
        "AWS_REGION": REGION,
    })
    env.pop("AWS_PROFILE", None)
    return env

def summarize_platforms(env, profile):
    resp = run_aws([
        "elasticbeanstalk", "list-platform-branches",
        "--region", REGION,
        "--filters",
        '[{"Attribute":"PlatformName","Operator":"=","Values":["PHP"]},{"Attribute":"LifecycleState","Operator":"=","Values":["supported"]}]',
    ], profile, env, allow_fail=True)
    if not resp.get("ok", True): return resp
    rows = []
    for r in resp.get("PlatformBranchSummaryList", []):
        if "8.4" in (r.get("BranchName") or ""):
            rows.append(r)
    return {"ok": bool(rows), "php84_branches": rows}

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--profile", help="AWS CLI profile for either management or workload account")
    ap.add_argument("--role-name", default="OrganizationAccountAccessRole")
    ap.add_argument("--out", required=True)
    a = ap.parse_args()

    base_id = run_aws(["sts", "get-caller-identity"], a.profile)
    caller = str(base_id.get("Account", ""))
    env = None
    profile = a.profile
    org = None
    access = {"entry_account": caller, "assumed_role": False}

    if caller == MANAGEMENT_ACCOUNT:
        org = run_aws(["organizations", "describe-account", "--account-id", WORKLOAD_ACCOUNT], a.profile)
        role_arn = f"arn:aws:iam::{WORKLOAD_ACCOUNT}:role/{a.role_name}"
        creds = run_aws(["sts", "assume-role", "--role-arn", role_arn, "--role-session-name", "waithaka-readiness"], a.profile)
        env = assumed_env(creds)
        profile = None
        access.update({"assumed_role": True, "role_arn": role_arn})
    elif caller != WORKLOAD_ACCOUNT:
        raise AwsError(f"Refusing readiness audit: caller account {caller!r} is neither management {MANAGEMENT_ACCOUNT} nor workload {WORKLOAD_ACCOUNT}.")

    target_id = run_aws(["sts", "get-caller-identity"], profile, env)
    if str(target_id.get("Account")) != WORKLOAD_ACCOUNT:
        raise AwsError("Assumed/direct target identity is not workload account 466401734428")

    azs = run_aws(["ec2", "describe-availability-zones", "--region", REGION, "--filters", "Name=state,Values=available"], profile, env, allow_fail=True)
    platforms = summarize_platforms(env, profile)
    rds = run_aws(["rds", "describe-db-engine-versions", "--region", REGION, "--engine", "mysql", "--include-all"], profile, env, allow_fail=True)
    ses = run_aws(["sesv2", "get-account", "--region", REGION], profile, env, allow_fail=True)
    zones = run_aws(["route53", "list-hosted-zones-by-name"], profile, env, allow_fail=True)
    cert_regional = run_aws(["acm", "list-certificates", "--region", REGION, "--certificate-statuses", "ISSUED"], profile, env, allow_fail=True)
    cert_edge = run_aws(["acm", "list-certificates", "--region", CF_CERT_REGION, "--certificate-statuses", "ISSUED"], profile, env, allow_fail=True)
    cf = run_aws(["cloudfront", "list-distributions"], profile, env, allow_fail=True)
    iam_service = run_aws(["iam", "get-role", "--role-name", "waithaka-eb-service-role"], profile, env, allow_fail=True)
    iam_instance = run_aws(["iam", "get-role", "--role-name", "waithaka-eb-instance-role"], profile, env, allow_fail=True)
    prefix = run_aws(["ec2", "describe-managed-prefix-lists", "--region", REGION, "--filters", "Name=prefix-list-name,Values=com.amazonaws.global.cloudfront.origin-facing"], profile, env, allow_fail=True)
    trail = run_aws(["cloudtrail", "describe-trails", "--region", REGION, "--include-shadow-trails"], profile, env, allow_fail=True)

    mysql84 = []
    if rds.get("ok", True):
        for v in rds.get("DBEngineVersions", []):
            ev = str(v.get("EngineVersion", ""))
            if ev.startswith("8.4") and v.get("Status", "available") in ("available", ""):
                mysql84.append(ev)

    hosted = []
    if zones.get("ok", True):
        for z in zones.get("HostedZones", []):
            name = (z.get("Name") or "").rstrip(".")
            if name in DOMAINS:
                hosted.append({"name": name, "id": z.get("Id"), "private": z.get("Config", {}).get("PrivateZone")})

    aliases = []
    if cf.get("ok", True):
        dist = (cf.get("DistributionList") or {}).get("Items") or []
        for d in dist:
            aitems = ((d.get("Aliases") or {}).get("Items") or [])
            if set(aitems) & {"www.waithakandegwa.com","waithakandegwa.com","www.waithakandegwa.co.ke","waithakandegwa.co.ke"}:
                aliases.append({"id": d.get("Id"), "domain": d.get("DomainName"), "aliases": aitems, "enabled": d.get("Enabled")})

    def certs_for(resp):
        if not resp.get("ok", True): return resp
        out=[]
        for c in resp.get("CertificateSummaryList", []):
            dom=c.get("DomainName") or ""
            if "waithakandegwa" in dom:
                out.append(c)
        return {"ok": True, "matches": out}

    result = {
        "schema_version": 1,
        "kind": "waithaka-account-readiness",
        "checked_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "management_account_id": MANAGEMENT_ACCOUNT,
        "workload_account_id": WORKLOAD_ACCOUNT,
        "target_region": REGION,
        "cloudfront_certificate_region": CF_CERT_REGION,
        "access": access,
        "organization_account": org,
        "target_identity": {k: target_id.get(k) for k in ("Account","Arn","UserId")},
        "availability_zones": azs,
        "elastic_beanstalk_php84": platforms,
        "rds_mysql84_versions": mysql84,
        "ses": ses,
        "existing_target_hosted_zones": hosted,
        "existing_target_cloudfront_distributions": aliases,
        "regional_certificates": certs_for(cert_regional),
        "edge_certificates_us_east_1": certs_for(cert_edge),
        "existing_iam_roles": {
            "service_role": iam_service.get("Role", {}).get("Arn") if iam_service.get("ok", True) else None,
            "instance_role": iam_instance.get("Role", {}).get("Arn") if iam_instance.get("ok", True) else None,
        },
        "cloudfront_origin_prefix_list": prefix,
        "cloudtrail": trail,
        "ready_to_prepare": bool(platforms.get("ok") and len(mysql84) and azs.get("ok", True)),
        "mutations_performed": False
    }
    os.makedirs(os.path.dirname(os.path.abspath(a.out)), exist_ok=True)
    with open(a.out, "x", encoding="utf-8") as f:
        json.dump(result, f, indent=2, sort_keys=True)
        f.write("\n")
    print(json.dumps({"out": a.out, "target_account": WORKLOAD_ACCOUNT, "ready_to_prepare": result["ready_to_prepare"]}))

if __name__ == "__main__":
    try:
        main()
    except (AwsError, FileExistsError, json.JSONDecodeError) as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(2)
