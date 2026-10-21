#!/usr/bin/env python3
"""Evidence-gated AWS recovery. Offline plans are the default; apply is explicit.

Python standard library + AWS CLI v2 only. Never imports SQL, initializes a user,
generates APP_KEY, prints a secret, deletes a stack or overwrites a media object.
See INSTALL-CUTOVER.md for the private migration-host restoration boundary.
"""
from __future__ import annotations

import argparse
import base64
import datetime as dt
import fcntl
import hashlib
import hmac
import ipaddress
import json
import mimetypes
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import zipfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
PHASES = ("foundation", "media", "application", "edge", "bind-media", "ready", "mail-setup", "cutover", "worker", "mail")
DEPENDENCIES = {"foundation": (), "media": ("foundation",), "application": ("foundation", "media"),
                "edge": ("application",), "bind-media": ("edge",), "ready": ("application", "media", "bind-media"),
                "mail-setup": ("ready",), "cutover": ("ready",), "worker": ("cutover",), "mail": ("worker", "mail-setup")}
RECEIPT_CHECKS = {
    "infrastructure": ("costs_accepted", "network_and_origin_ingress_reviewed", "runtime_roles_reviewed", "proxy_chain_verified", "backup_restore_rehearsal_planned", "staging_access_restricted_if_applicable"),
    "restore": ("consistent_backup_restored", "original_key_decrypts_existing_records", "migration_plan_reviewed", "migrations_applied", "runtime_user_crud_only", "database_tls_verified", "recent_content_and_consent_checked", "pending_queues_preserved", "media_library_hashes_and_versions_reconciled"),
    "origin": ("origin_dns_points_to_target_alb", "regional_tls_covers_origin_and_canonical", "origin_ingress_restricted", "staging_access_restricted_if_applicable"),
    "acceptance": ("apache_private_paths_denied", "restored_urls_and_media_checked", "writer_login_and_csrf_checked", "private_media_requires_auth", "canonical_redirects_and_tls_checked", "mysql84_regressions_passed", "timer_paused_and_observed", "provider_callbacks_and_resources_reviewed"),
    "cutover": ("old_editorial_writes_frozen", "old_workers_stopped", "final_backup_and_media_imported", "rollback_source_database_key_media_and_dns_retained", "no_unreconciled_external_delivery_replay"),
    "traffic": ("all_domain_tls_and_redirects_checked", "live_public_and_private_routes_checked", "dns_points_to_target_distribution"),
    "worker": ("pending_jobs_and_outbox_reviewed", "ambiguous_outcomes_reconciled_or_held", "provider_destinations_confirmed", "only_this_environment_will_deliver", "monitoring_owner_assigned"),
    "mail": ("ses_identity_and_dkim_verified", "configuration_set_sns_events_verified", "signed_subscription_confirmed", "bounce_and_complaint_suppression_tested", "controlled_delivery_test_accepted", "consent_postal_address_and_unsubscribe_verified"),
}
MEDIA_ROOTS = {"public_media_root": ("public", "assets/uploads/"),
               "private_proofs_root": ("private", "release-proofs/"),
               "private_library_root": ("private", "media-library/")}
MEDIA_EXTENSIONS = {".jpg", ".jpeg", ".png", ".gif", ".webp", ".avif", ".svg", ".ico", ".pdf", ".mp4", ".mov", ".webm", ".m4v", ".mp3", ".wav", ".m4a", ".ogg"}
CF_ZONE = "Z2FDTNDATAQYW2"


class GuardError(Exception):
    pass


class AwsError(GuardError):
    def __init__(self, service, operation, code="RequestFailed"):
        self.code = code
        super().__init__(f"AWS {service}/{operation} failed ({code}); inspect the private AWS console. Raw response suppressed.")


def require(condition, message):
    if not condition:
        raise GuardError(message)


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode()


def digest(value):
    return hashlib.sha256(canonical(value)).hexdigest()


def file_hash(path):
    sha = hashlib.sha256()
    with Path(path).open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            sha.update(block)
    return sha.hexdigest()


def read_json(path):
    try:
        with Path(path).open(encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, ValueError):
        raise GuardError("A required JSON input cannot be read or is malformed.") from None


def private_file(path):
    path = Path(path)
    require(path.is_absolute() and not path.is_symlink() and path.is_file(), "Private inputs must be absolute regular files, not symbolic links.")
    require(stat.S_IMODE(path.stat().st_mode) & 0o077 == 0, "Private key, receipt and state inputs must have mode 0600 or stricter.")
    require(path.stat().st_uid == os.getuid(), "Private inputs must belong to the current operator.")
    return path


def write_json(path, value, overwrite=False):
    path = Path(path)
    require(not path.is_symlink(), "Refusing a symbolic-link output.")
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    require(overwrite or not path.exists(), "Output already exists; choose a new filename.")
    fd, temporary = tempfile.mkstemp(prefix=".waithaka-", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(value, handle, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, 0o600)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def real_text(value, label, pattern=None):
    require(isinstance(value, str) and value == value.strip() and value, f"Explicit {label} is required.")
    require(not re.search(r"REPLACE|CHANGEME|PLACEHOLDER|YOUR[_ -]|TODO|example\.(com|org|net|invalid)|<|>", value, re.I), f"Replace the placeholder for {label}.")
    require(not any(ord(c) < 32 for c in value), f"Control characters are not allowed in {label}.")
    if pattern:
        require(re.fullmatch(pattern, value) is not None, f"Invalid {label}.")
    return value


def domain(value):
    return real_text(value, "domain", r"(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}")


def validate_config(c):
    require(isinstance(c, dict) and c.get("schema_version") == 1, "Configuration schema_version must be 1.")
    # Values with these names belong in Secrets Manager, never in this plan input.
    def no_secrets(v):
        if isinstance(v, dict):
            for k, val in v.items():
                require(k.lower() not in {"app_key", "db_password", "password", "secret_access_key", "access_token", "refresh_token"}, "Do not place secret values in deployment configuration.")
                no_secrets(val)
        elif isinstance(v, list):
            for val in v:
                no_secrets(val)
    no_secrets(c)
    try:
        real_text(c["deployment_id"], "deployment ID", r"[a-z][a-z0-9-]{5,35}")
        account = real_text(c["account_id"], "AWS account ID", r"[0-9]{12}")
        require(account not in {"000000000000", "123456789012"}, "Supply the intended real AWS account ID.")
        region = real_text(c["region"], "AWS region", r"[a-z]{2}(?:-[a-z]+)+-[1-9]")
        require(not region.startswith(("cn-", "us-gov-")), "This helper supports the commercial aws partition only; its templates need a separate partition review elsewhere.")
        real_text(c["profile"], "AWS CLI profile", r"[A-Za-z0-9_.-]{1,64}")
        require(c["environment"] in {"staging", "production"}, "Select staging or production explicitly.")
        n, r, d, a, h = (c[k] for k in ("network", "roles", "database", "application", "domains"))
        real_text(n["vpc_id"], "VPC ID", r"vpc-[0-9a-f]{8,17}")
        for key in ("database_subnet_ids", "application_subnet_ids", "alb_subnet_ids"):
            require(isinstance(n[key], list) and len(set(n[key])) == len(n[key]) and len(n[key]) >= 2, "Each subnet list must explicitly contain at least two distinct subnets.")
            for subnet in n[key]:
                real_text(subnet, "subnet ID", r"subnet-[0-9a-f]{8,17}")
        for key in ("application_security_group_id", "alb_security_group_id"):
            real_text(n[key], "security group", r"sg-[0-9a-f]{8,17}")
        require(n["application_security_group_id"] != n["alb_security_group_id"], "Application and ALB security groups must be distinct.")
        require(type(n["associate_public_ip"]) is bool, "Select the application public-IP network design explicitly.")
        require(isinstance(n["trusted_proxy_cidrs"], list) and n["trusted_proxy_cidrs"], "Explicit trusted proxy ranges are required.")
        for proxy in n["trusted_proxy_cidrs"]:
            real_text(proxy, "trusted proxy network")
            try:
                network = ipaddress.ip_network(proxy, strict=True)
                require(network.prefixlen > 0, "Trust-all proxy ranges are forbidden.")
            except ValueError:
                raise GuardError("Trusted proxies must be strict IP addresses or canonical CIDR networks.") from None
        for key in ("service_role", "instance_profile", "instance_role"):
            real_text(r[key], key, r"[A-Za-z0-9+=,.@_-]{1,64}")
        real_text(d["name"], "database name", r"[A-Za-z][A-Za-z0-9]{0,62}")
        real_text(d["instance_class"], "database instance class", r"db\.[a-z0-9]+\.[a-z0-9]+")
        real_text(d["engine_version"], "supported MySQL version", r"8\.4\.[0-9]+")
        require(type(d["multi_az"]) is bool, "Select Multi-AZ explicitly.")
        real_text(a["name"], "application name", r"[A-Za-z][A-Za-z0-9-]{3,60}")
        real_text(a["environment_name"], "environment name", r"[A-Za-z][A-Za-z0-9-]{3,38}[A-Za-z0-9]")
        real_text(a["platform_arn"], "supported PHP platform ARN", rf"arn:aws:elasticbeanstalk:{re.escape(region)}::platform/PHP 8\.[34] running on 64bit Amazon Linux 2023/[0-9.]+")
        require(type(a["min_instances"]) is int and type(a["max_instances"]) is int and 1 <= a["min_instances"] <= a["max_instances"] <= 20, "Review explicit application scaling bounds (1–20).")
        domain(h["canonical"]); domain(h["origin"])
        require(isinstance(h["aliases"], list) and 1 <= len(h["aliases"]) <= 10 and len(set(h["aliases"])) == len(h["aliases"]), "Provide the exact distinct domain aliases.")
        for host in h["aliases"]:
            domain(host)
        require(h["canonical"] in h["aliases"] and h["origin"] not in h["aliases"], "Canonical host must be an alias; the origin must be a separate host.")
        if c["environment"] == "production":
            require(h["canonical"] == "www.waithakandegwa.com" and set(h["aliases"]) == {"www.waithakandegwa.com", "waithakandegwa.com", "www.waithakandegwa.co.ke", "waithakandegwa.co.ke"}, "Production must preserve the agreed canonical .com host and all four domain aliases.")
        else:
            require(not set(h["aliases"]) & {"www.waithakandegwa.com", "waithakandegwa.com", "www.waithakandegwa.co.ke", "waithakandegwa.co.ke"}, "Staging cannot use production aliases.")
        for key, cert_region in (("viewer_certificate_arn", "us-east-1"), ("regional_certificate_arn", region)):
            real_text(h[key], key, rf"arn:aws:acm:{re.escape(cert_region)}:{account}:certificate/[a-f0-9-]{{36}}")
        real_text(c["secret_arn"], "application secret ARN", rf"arn:aws:secretsmanager:{re.escape(region)}:{account}:secret:[A-Za-z0-9/_+=.@-]+")
        state = Path(c["state_dir"])
        require(state.is_absolute() and not state.is_symlink() and not state.resolve().is_relative_to(ROOT), "Private deployment state must be outside the application source tree.")
        dns = c["dns"]
        require(dns["provider"] in {"route53", "external"}, "Select the current DNS provider explicitly.")
        if dns["provider"] == "route53":
            require(set(dns["zones"]) == set(h["aliases"]), "Explicit hosted zone IDs are required for every alias.")
            for zone in dns["zones"].values():
                real_text(zone, "hosted zone ID", r"Z[A-Z0-9]{4,32}")
        mail = c["mail"]
        require(mail["transport"] in {"disabled", "ses"}, "This helper enables only SES, after a separate receipt; other transports require a reviewed deployment procedure.")
        if mail["transport"] == "ses":
            real_text(mail["from_address"], "verified mail sender", r"[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-z0-9.-]+\.[a-z]{2,63}")
            real_text(mail["from_name"], "mail sender name")
            real_text(mail["postal_address"], "newsletter postal address")
            real_text(mail["identity_arn"], "verified SES identity", rf"arn:aws:ses:{re.escape(region)}:{account}:identity/[A-Za-z0-9.@-]+")
            real_text(mail["configuration_set"], "SES configuration set", r"[A-Za-z0-9_-]{1,64}")
            real_text(mail["sns_topic_arn"], "SES feedback topic", rf"arn:aws:sns:{re.escape(region)}:{account}:[A-Za-z0-9_-]{{1,256}}")
        require(set(c["artifacts"]) == {"application_zip", "database_backup", "original_key_file", *MEDIA_ROOTS}, "Provide the application ZIP, database export, original-key JSON and all three media roots explicitly (empty directories are allowed).")
        for value in c["artifacts"].values():
            require(isinstance(value, str) and Path(value).is_absolute(), "Artifact paths must be absolute.")
    except (KeyError, TypeError):
        raise GuardError("Required deployment inputs are missing or have the wrong type; follow install-config.example.json.") from None
    return c


def key_value(path):
    value = read_json(private_file(path))
    key = value.get("APP_KEY") if isinstance(value, dict) else None
    require(isinstance(key, str) and len(key.encode()) >= 32 and bool(key.strip()) and not any(ord(c) < 32 for c in key), "The recovered-key JSON must contain the exact original valid APP_KEY.")
    return key


def inventory(c):
    files = []
    for setting, (visibility, prefix) in MEDIA_ROOTS.items():
        root = Path(c["artifacts"][setting])
        require(root.is_dir() and not root.is_symlink(), "All media roots must be existing real directories; use explicit empty directories when none were recovered.")
        for path in sorted(root.rglob("*")):
            require(not path.is_symlink(), "Media inventory refuses symbolic links.")
            if path.is_dir():
                continue
            require(path.is_file(), "Media inventory accepts only regular files.")
            relative = path.relative_to(root).as_posix()
            if path.name in {".htaccess", ".gitkeep"}:
                continue
            require(all(part not in {"", ".", ".."} for part in Path(relative).parts) and not any(ord(ch) < 32 for ch in relative) and "\\" not in relative, "Unsafe media path.")
            require(path.suffix.lower() in MEDIA_EXTENSIONS and not any(part.startswith(".") for part in Path(relative).parts), "An unrecognized or hidden file is present in a media root; inspect it before restoration.")
            size = path.stat().st_size
            require(0 < size <= 5_000_000_000, "Media must be nonempty and fit a single S3 PutObject (5 GB); larger files need a separately verified multipart import.")
            files.append({"source": str(path), "visibility": visibility, "key": prefix + relative, "bytes": size, "sha256": file_hash(path), "content_type": mimetypes.guess_type(path.name)[0] or "application/octet-stream"})
    return files


def artifact_evidence(c):
    key = key_value(c["artifacts"]["original_key_file"])
    files = {"original_key_sha256": hashlib.sha256(key.encode()).hexdigest()}
    for setting in ("original_key_file", "database_backup"):
        require(not Path(c["artifacts"][setting]).resolve().is_relative_to(ROOT), "Recovered keys and SQL exports must be outside the application source/document root.")
        private_file(c["artifacts"][setting])
    for key in ("application_zip", "database_backup"):
        path = Path(c["artifacts"][key])
        require(path.is_file() and not path.is_symlink() and path.stat().st_size > 0, "A nonempty recovered export and application ZIP are required.")
        files[key] = {"path": str(path), "bytes": path.stat().st_size, "sha256": file_hash(path)}
    try:
        with zipfile.ZipFile(c["artifacts"]["application_zip"]) as archive:
            names = set(archive.namelist())
            require({"index.php", ".htaccess", "composer.lock", "vendor/autoload.php", ".platform/hooks/postdeploy/30_worker.sh"}.issubset(names), "Use deployment/application.zip with locked dependencies and platform hooks, not the outer source archive.")
            markers = {"storage/.gitkeep", "storage/.htaccess", "storage/release-proofs/.htaccess", "assets/uploads/.gitkeep", "assets/uploads/.htaccess"}
            require(not any(name.startswith("/") or "\\" in name or name in {"app/config.php", ".env"} or ".." in Path(name).parts or name.startswith((".git/", ".aws/")) or (name.startswith(("storage/", "assets/uploads/")) and not name.endswith("/") and name not in markers) for name in names), "Application archive includes private or unsafe paths.")
            require(not any(stat.S_ISLNK(info.external_attr >> 16) for info in archive.infolist()) and len(names) == len(archive.infolist()), "Archive symlinks and duplicate members are refused.")
            for migration in (ROOT / "app/migrations").glob("*.sql"):
                member = "app/migrations/" + migration.name
                require(member in names and hashlib.sha256(archive.read(member)).hexdigest() == file_hash(migration), "The application ZIP and locally reviewed migrations differ. Use source and application from the same release.")
    except zipfile.BadZipFile:
        raise GuardError("Application archive is not a ZIP file.") from None
    files["migration_sha256"] = digest({p.name: file_hash(p) for p in sorted((ROOT / "app/migrations").glob("*.sql"))})
    files["media"] = inventory(c)
    files["media_sha256"] = digest(files["media"])
    return files


def state_path(c):
    return Path(c["state_dir"]) / "state.json"


def load_state(c):
    path = state_path(c)
    if not path.exists():
        return {"schema_version": 1, "config_sha256": digest(c), "completed": {}}
    state = read_json(private_file(path))
    require(state.get("config_sha256") == digest(c), "Configuration differs from this deployment state. Do not repurpose an existing state directory/account.")
    return state


def save_state(c, state):
    directory = Path(c["state_dir"])
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    require(not directory.is_symlink() and stat.S_IMODE(directory.stat().st_mode) & 0o077 == 0, "Deployment state directory must be mode 0700.")
    write_json(state_path(c), state, overwrite=True)


def receipt_template(c, kind, evidence, state):
    value = {"schema_version": 1, "kind": kind, "deployment_id": c["deployment_id"], "account_id": c["account_id"], "region": c["region"],
             "artifacts_sha256": digest(evidence), "verified_by": "", "verified_at": "", "checks": {key: False for key in RECEIPT_CHECKS[kind]},
             "meaning": "Operator attestation of checks actually performed; this file is not independent proof or a provider verification."}
    if kind == "restore":
        value.update(database_host=state["completed"].get("foundation", {}).get("DatabaseHost", ""), database_name=c["database"]["name"],
                     backup_sha256=evidence["database_backup"]["sha256"], migration_sha256=evidence["migration_sha256"],
                     source_counts={table: None for table in ("users", "posts", "contributions", "media_releases", "settings")}, target_counts={})
    return value


def receipt(c, kind, evidence, state):
    path = Path(c["state_dir"]) / (kind + "-receipt.json")
    value = read_json(private_file(path))
    expected = receipt_template(c, kind, evidence, state)
    for key in ("schema_version", "kind", "deployment_id", "account_id", "region", "artifacts_sha256"):
        require(value.get(key) == expected[key], f"The {kind} receipt does not match this exact deployment and backup/media/release inventory.")
    real_text(value.get("verified_by"), "receipt verifier")
    try:
        checked = dt.datetime.fromisoformat(value.get("verified_at", "").replace("Z", "+00:00"))
        require(checked.tzinfo is not None and -300 <= (dt.datetime.now(dt.timezone.utc) - checked).total_seconds() <= 7 * 86400, "Receipt must be dated within the last seven days and not in the future.")
    except ValueError:
        raise GuardError("Receipt needs an ISO-8601 verification timestamp with timezone.") from None
    require(all(value.get("checks", {}).get(k) is True for k in RECEIPT_CHECKS[kind]), f"The {kind} receipt has uncompleted checks.")
    if kind == "restore":
        for key in ("database_host", "database_name", "backup_sha256", "migration_sha256"):
            require(value.get(key) == expected[key] and value.get(key), "Restore receipt targets the wrong database, backup or migrations.")
        source, target = value.get("source_counts"), value.get("target_counts")
        require(isinstance(source, dict) and isinstance(target, dict) and set(expected["source_counts"]).issubset(source) and source == target, "Compare source and restored counts for all recovered tables; required core table counts must match exactly.")
        require(all(type(v) is int and v >= 0 for v in source.values()) and source["users"] >= 1, "Restoration cannot be accepted as an empty replacement website; preserve the existing Writer account(s).")
    return {"file": str(path), "sha256": file_hash(path)}


def data_parameters(c, distribution=""):
    n, d = c["network"], c["database"]
    return {"VpcId": n["vpc_id"], "PrivateSubnetIds": ",".join(n["database_subnet_ids"]), "ApplicationSecurityGroupId": n["application_security_group_id"],
            "DatabaseName": d["name"], "DatabaseClass": d["instance_class"], "DatabaseEngineVersion": d["engine_version"], "MultiAZ": str(d["multi_az"]).lower(), "MediaDistributionArn": distribution}


def app_options(c, data):
    n, a, h = c["network"], c["application"], c["domains"]
    replacements = {"REPLACE_EB_SERVICE_ROLE": c["roles"]["service_role"], "REPLACE_INSTANCE_PROFILE": c["roles"]["instance_profile"], "REPLACE_APP_SECURITY_GROUP": n["application_security_group_id"],
                    "REPLACE_VPC": n["vpc_id"], "REPLACE_APPLICATION_SUBNETS": ",".join(n["application_subnet_ids"]), "REPLACE_ALB_SUBNETS": ",".join(n["alb_subnet_ids"]),
                    "REPLACE_REGIONAL_CERTIFICATE_ARN": h["regional_certificate_arn"], "REPLACE_ALB_SECURITY_GROUP": n["alb_security_group_id"], "REPLACE_RDS_HOST": data["DatabaseHost"],
                    "REPLACE_ACTUAL_PROXY_CIDRS": ",".join(n["trusted_proxy_cidrs"]), "REPLACE_PUBLIC_MEDIA_BUCKET": data["PublicMedia"], "REPLACE_PRIVATE_MEDIA_BUCKET": data["PrivateMedia"],
                    "REPLACE_REGION": c["region"], "REPLACE_APP_SECRET_ARN": c["secret_arn"]}
    options = read_json(HERE / "beanstalk-options.template.json")
    env = {"APP_ENV": c["environment"], "APP_READY": "0", "WORKER_ENABLED": "0", "APP_URL": "https://" + h["canonical"], "APP_ALLOWED_HOSTS": ",".join(h["aliases"]),
           "MEDIA_PUBLIC_URL": "https://" + h["canonical"], "DB_DATABASE": c["database"]["name"], "MAIL_TRANSPORT": "disabled"}
    for option in options:
        for old, new in replacements.items():
            option["Value"] = option["Value"].replace(old, new)
        if option["Namespace"] == "aws:elasticbeanstalk:application:environment" and option["OptionName"] in env:
            option["Value"] = env[option["OptionName"]]
        if option["OptionName"] == "AssociatePublicIpAddress":
            option["Value"] = str(n["associate_public_ip"]).lower()
        if option["Namespace"] == "aws:autoscaling:asg" and option["OptionName"] in {"MinSize", "MaxSize"}:
            option["Value"] = str(a["min_instances"] if option["OptionName"] == "MinSize" else a["max_instances"])
    require("REPLACE" not in json.dumps(options), "An unresolved application option remains.")
    return options


def runtime_policy(c, data, mail=False):
    policy = {"Version": "2012-10-17", "Statement": [
        {"Sid": "ApplicationSecretOnly", "Effect": "Allow", "Action": ["secretsmanager:GetSecretValue"], "Resource": c["secret_arn"]},
        {"Sid": "PublicMediaOnly", "Effect": "Allow", "Action": ["s3:GetObject", "s3:GetObjectVersion", "s3:PutObject"], "Resource": f"arn:aws:s3:::{data['PublicMedia']}/assets/uploads/*"},
        {"Sid": "PrivateMediaOnly", "Effect": "Allow", "Action": ["s3:GetObject", "s3:GetObjectVersion", "s3:PutObject"], "Resource": [f"arn:aws:s3:::{data['PrivateMedia']}/{prefix}/*" for prefix in ("release-proofs", "media-library")]},
    ]}
    if mail:
        m = c["mail"]
        policy["Statement"].extend([
            {"Sid": "AcceptedSesIdentity", "Effect": "Allow", "Action": ["ses:SendEmail"], "Resource": [m["identity_arn"], f"arn:aws:ses:{c['region']}:{c['account_id']}:configuration-set/{m['configuration_set']}"], "Condition": {"StringEquals": {"ses:FromAddress": m["from_address"]}}},
            {"Sid": "AcceptedFeedbackTopic", "Effect": "Allow", "Action": ["sns:ConfirmSubscription"], "Resource": m["sns_topic_arn"]},
        ])
    return policy


def read_dns_snapshot(c):
    value = read_json(private_file(Path(c["state_dir"]) / "dns-snapshot.json"))
    require(value.get("config_sha256") == digest(c), "DNS snapshot does not match this configuration.")
    require(0 <= time.time() - value.get("created_at", 0) <= 86400, "Capture DNS read-only again; the snapshot is older than 24 hours.")
    return value


def dns_changes(c, state, snapshot):
    batches = {}
    for host in c["domains"]["aliases"]:
        zone = c["dns"]["zones"][host]
        old = snapshot["records"][host]
        require(all(r.get("Type") in {"A", "AAAA", "CNAME"} and r.get("Name", "").rstrip(".") == host and not any(k in r for k in ("SetIdentifier", "Failover", "Weight", "Region", "GeoLocation", "MultiValueAnswer", "CidrRoutingConfig", "GeoProximityLocation", "TrafficPolicyInstanceId")) for r in old), "Special DNS routing requires a separate reviewed migration; this helper only changes ordinary address records.")
        changes = batches.setdefault(zone, [])
        for record in old:
            if record["Type"] == "CNAME":
                changes.append({"Action": "DELETE", "ResourceRecordSet": record})
        for record_type in ("A", "AAAA"):
            changes.append({"Action": "UPSERT", "ResourceRecordSet": {"Name": host + ".", "Type": record_type, "AliasTarget": {"HostedZoneId": CF_ZONE, "DNSName": state["completed"]["edge"]["DistributionDomain"] + ".", "EvaluateTargetHealth": False}}})
    return [{"zone_id": zone, "change_batch": {"Comment": "Reviewed Waithaka recovery " + c["deployment_id"], "Changes": changes}} for zone, changes in sorted(batches.items())]


def build_plan(c, phase, state=None):
    validate_config(c)
    state = state if state is not None else load_state(c)
    evidence = artifact_evidence(c)
    done = state["completed"]
    for prerequisite in DEPENDENCIES[phase]:
        require(prerequisite in done, f"Complete {prerequisite} and record its outputs before planning {phase}.")
    if "application" in done:
        require(done["application"]["release_sha256"] == evidence["application_zip"]["sha256"], "This install journal is bound to its deployed release. Use the ordinary reviewed version-update procedure for a new application build.")
    plan = {"schema_version": 1, "phase": phase, "config": c, "config_sha256": digest(c), "state_sha256": digest(state), "artifacts": evidence,
            "template_sha256": {name: file_hash(HERE / name) for name in ("data-stack.json", "edge-stack.json", "beanstalk-options.template.json")},
            "receipts": {}, "actions": [], "guards": ["Explicit STS account check before writes", "No SQL import or key generation", "No automatic deletion, resource replacement or media overwrite", "Workers and mail paused until their own acceptance phases"]}
    def gate(kind):
        plan["receipts"][kind] = receipt(c, kind, evidence, state)
    data = done.get("foundation", {})
    if phase == "foundation":
        gate("infrastructure")
        plan["actions"] = [{"kind": "cloudformation-create-only", "stack": c["deployment_id"] + "-data", "template": "data-stack.json", "parameters": data_parameters(c)}]
    elif phase == "media":
        plan["actions"] = [{"kind": "put-if-absent-verify-sha256", "public_bucket": data["PublicMedia"], "private_bucket": data["PrivateMedia"], "objects": evidence["media"]}]
    elif phase == "application":
        require(done["media"]["media_sha256"] == evidence["media_sha256"], "Copy and verify the current media inventory first.")
        plan["actions"] = [{"kind": "compare-original-key-to-runtime-secret", "secret_arn": c["secret_arn"]},
                           {"kind": "owned-inline-runtime-policy", "role": c["roles"]["instance_role"], "name": c["deployment_id"] + "-runtime", "policy": runtime_policy(c, data)},
                           {"kind": "new-beanstalk-application-and-environment", "application": c["application"], "options": app_options(c, data), "release_bucket": data["ReleaseArtifacts"], "release_key": "releases/" + evidence["application_zip"]["sha256"] + ".zip"}]
    elif phase == "edge":
        gate("origin")
        plan["actions"] = [{"kind": "cloudformation-create-only", "stack": c["deployment_id"] + "-edge", "template": "edge-stack.json", "parameters": {"CanonicalHost": c["domains"]["canonical"], "Aliases": ",".join(c["domains"]["aliases"]), "ViewerCertificateArn": c["domains"]["viewer_certificate_arn"], "OriginDomain": c["domains"]["origin"], "MediaBucketRegionalDomain": data["PublicMedia"] + ".s3." + c["region"] + ".amazonaws.com"}}]
    elif phase == "bind-media":
        plan["actions"] = [{"kind": "cloudformation-policy-only-update", "stack": c["deployment_id"] + "-data", "template": "data-stack.json", "parameters": data_parameters(c, done["edge"]["DistributionArn"]), "only_resource": "PublicMediaTlsPolicy"}]
    elif phase == "ready":
        gate("restore")
        require(done["media"]["media_sha256"] == evidence["media_sha256"], "Copy and verify the final media inventory before readiness.")
        plan["actions"] = [{"kind": "compare-original-key-to-runtime-secret", "secret_arn": c["secret_arn"]}, {"kind": "environment-options", "values": {"APP_READY": "1", "WORKER_ENABLED": "0", "MAIL_TRANSPORT": "disabled"}}]
    elif phase == "cutover":
        require(done["ready"]["artifacts_sha256"] == digest(evidence), "Readiness was accepted for different recovered data; repeat media, restore receipt and ready for the final snapshot.")
        gate("acceptance")
        if c["environment"] == "production":
            gate("cutover")
        if c["dns"]["provider"] == "route53":
            snapshot = read_dns_snapshot(c)
            plan["dns_snapshot"] = snapshot
            plan["actions"] = [{"kind": "dns-address-records-only", **batch} for batch in dns_changes(c, state, snapshot)]
        else:
            gate("traffic")
            plan["actions"] = [{"kind": "record-external-dns-acceptance", "aliases": c["domains"]["aliases"], "target": done["edge"]["DistributionDomain"]}]
    elif phase in {"mail-setup", "worker", "mail"}:
        require(c["environment"] == "production", "Staging delivery remains paused; do not activate restored production credentials.")
        require(done["ready"]["artifacts_sha256"] == digest(evidence), "Recovered inputs changed after readiness.")
        if phase != "mail-setup":
            gate("traffic"); gate("worker")
        if phase == "worker":
            plan["actions"] = [{"kind": "environment-options", "values": {"APP_READY": "1", "WORKER_ENABLED": "1", "MAIL_TRANSPORT": "disabled"}}]
        else:
            require(c["mail"]["transport"] == "ses", "Explicit SES configuration is required for mail activation.")
            if phase == "mail":
                gate("mail")
            m = c["mail"]
            plan["actions"] = [{"kind": "owned-inline-runtime-policy", "role": c["roles"]["instance_role"], "name": c["deployment_id"] + "-runtime", "policy": runtime_policy(c, data, True)},
                               {"kind": "environment-options", "values": {"APP_READY": "1", "WORKER_ENABLED": "1" if phase == "mail" else "0", "MAIL_TRANSPORT": "ses" if phase == "mail" else "disabled", "MAIL_FROM": m["from_address"], "MAIL_FROM_NAME": m["from_name"], "MAIL_POSTAL_ADDRESS": m["postal_address"], "SES_REGION": c["region"], "SES_CONFIGURATION_SET": m["configuration_set"], "SES_SNS_TOPIC_ARN": m["sns_topic_arn"]}}]
    plan["sha256"] = digest(plan)
    return plan


class Aws:
    def __init__(self, config):
        self.c = config

    def call(self, service, operation, *arguments, region=None):
        require(shutil.which("aws") is not None, "AWS CLI v2 is required for explicit live checks/apply; offline plans need only Python.")
        command = ["aws", "--profile", self.c["profile"], "--region", region or self.c["region"], "--no-cli-pager", "--cli-error-format", "legacy", "--output", "json", service, operation, *map(str, arguments)]
        env = os.environ.copy()
        # Do not allow an inherited custom endpoint to receive credentials or secrets.
        for key in list(env):
            if key.startswith("AWS_ENDPOINT_URL"):
                del env[key]
        env.update(AWS_IGNORE_CONFIGURED_ENDPOINT_URLS="true", AWS_PAGER="", AWS_CLI_AUTO_PROMPT="off")
        result = subprocess.run(command, capture_output=True, text=True, env=env, timeout=180)
        if result.returncode:
            code = re.search(r"\(([A-Za-z0-9_.-]{1,80})\) when calling", result.stderr)
            raise AwsError(service, operation, code.group(1) if code else "RequestFailed")
        try:
            return json.loads(result.stdout) if result.stdout.strip() else {}
        except ValueError:
            raise GuardError("AWS CLI returned an unexpected format; output suppressed.") from None

    def identity(self):
        result = self.call("sts", "get-caller-identity")
        require(result.get("Account") == self.c["account_id"] and result.get("Arn", "").startswith("arn:aws:"), "AWS caller is not in the explicitly approved account/partition. No writes were made.")
        return {"account_id": result["Account"], "region": self.c["region"], "identity_checked": True}


def preflight(c, aws):
    result = aws.identity()
    n = c["network"]
    subnets = sorted(set(n["database_subnet_ids"] + n["application_subnet_ids"] + n["alb_subnet_ids"]))
    rows = aws.call("ec2", "describe-subnets", "--subnet-ids", *subnets)["Subnets"]
    require({r["SubnetId"] for r in rows} == set(subnets) and all(r["VpcId"] == n["vpc_id"] for r in rows), "Subnets are missing or do not belong to the intended VPC.")
    for kind in ("database_subnet_ids", "application_subnet_ids", "alb_subnet_ids"):
        require(len({r["AvailabilityZone"] for r in rows if r["SubnetId"] in n[kind]}) >= 2, "Each subnet role needs at least two availability zones.")
    groups = aws.call("ec2", "describe-security-groups", "--group-ids", n["application_security_group_id"], n["alb_security_group_id"])["SecurityGroups"]
    require(len(groups) == 2 and all(g["VpcId"] == n["vpc_id"] for g in groups), "Security groups do not match the intended VPC.")
    platform = aws.call("elasticbeanstalk", "describe-platform-version", "--platform-arn", c["application"]["platform_arn"])["PlatformDescription"]
    require(platform.get("PlatformStatus") == "Ready" and str(platform.get("DateCreated", ""))[:10] >= "2026-01-13" and platform.get("PlatformLifecycleState") != "deprecated", "Select a supported Ready platform released after JSON-key secret support (2026-01-13).")
    versions = aws.call("rds", "describe-db-engine-versions", "--engine", "mysql", "--engine-version", c["database"]["engine_version"])["DBEngineVersions"]
    require(any(v.get("EngineVersion") == c["database"]["engine_version"] and v.get("Status", "available") == "available" for v in versions), "The exact MySQL engine version is not available in this region.")
    for key, region, hosts in (("viewer_certificate_arn", "us-east-1", c["domains"]["aliases"]), ("regional_certificate_arn", c["region"], [c["domains"]["origin"], c["domains"]["canonical"]])):
        cert = aws.call("acm", "describe-certificate", "--certificate-arn", c["domains"][key], region=region)["Certificate"]
        require(cert.get("Status") == "ISSUED", "An ACM certificate is not issued.")
        names = cert.get("SubjectAlternativeNames", [])
        for host in hosts:
            require(any(host == name or (name.startswith("*.") and host.split(".", 1)[-1] == name[2:] and host.count(".") == name.count(".")) for name in names), "An ACM certificate does not cover every required host.")
    profile = aws.call("iam", "get-instance-profile", "--instance-profile-name", c["roles"]["instance_profile"])["InstanceProfile"]
    require(any(r["RoleName"] == c["roles"]["instance_role"] for r in profile["Roles"]), "Instance profile does not contain the chosen runtime role.")
    aws.call("iam", "get-role", "--role-name", c["roles"]["service_role"])
    aws.call("secretsmanager", "describe-secret", "--secret-id", c["secret_arn"])
    result.update(subnets_and_groups_match_vpc=True, certificates_cover_hosts=True, engine_available=True, platform_ready=True,
                  limitations=["No costs, route/NAT availability, security-group ingress, role trust, origin TLS handshake or live backup restoration are proved by this check.", "No secret value was fetched by readiness preflight."])
    return result


def compare_key(c, aws):
    result = aws.call("secretsmanager", "get-secret-value", "--secret-id", c["secret_arn"])
    try:
        values = json.loads(result.get("SecretString", ""))
    except ValueError:
        raise GuardError("The application secret must be a JSON string; no values are shown.") from None
    require(isinstance(values, dict) and isinstance(values.get("APP_KEY"), str) and hmac.compare_digest(key_value(c["artifacts"]["original_key_file"]).encode(), values["APP_KEY"].encode()), "Runtime APP_KEY is not identical to the recovered original key. Do not generate a replacement.")
    require(isinstance(values.get("DB_USERNAME"), str) and re.fullmatch(r"[A-Za-z0-9_]{1,32}", values["DB_USERNAME"]) and values["DB_USERNAME"] != "wk_restore_admin" and isinstance(values.get("DB_PASSWORD"), str) and values["DB_PASSWORD"], "Runtime database secret must contain a dedicated username and password, not the restore administrator.")


def wait_for(fetch, done, failed, label, seconds=3600):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        value = fetch()
        if done(value):
            return value
        require(not failed(value), f"{label} failed. Inspect the private AWS console; no destructive cleanup is attempted.")
        print(f"Waiting for {label}; delivery remains controlled by its explicit activation phase.", flush=True)
        time.sleep(15)
    raise GuardError(f"{label} has not completed within the wait window. Keep the journal and reconcile the existing resource before retrying.")


def stack_description(aws, name):
    try:
        return aws.call("cloudformation", "describe-stacks", "--stack-name", name)["Stacks"][0]
    except AwsError as error:
        # CloudFormation uses ValidationError for a missing named stack. Existence
        # is rechecked when creating the change set; no delete/overwrite fallback.
        if error.code == "ValidationError":
            return None
        raise


def owned_stack(c, row):
    return any(t.get("Key") == "WaithakaDeployment" and t.get("Value") == c["deployment_id"] for t in row.get("Tags", []))


def validate_change_set(changes, update=False):
    require(bool(changes), "No resource changes were proposed.")
    for change in changes:
        resource = change.get("ResourceChange", {})
        require(resource.get("Action") not in {"Remove", "Import"} and resource.get("Replacement") not in {"True", "Conditional"}, "Resource deletion/import/replacement is refused. Prepare a separate reviewed migration.")
        if update:
            require(resource.get("Action") == "Modify" and resource.get("LogicalResourceId") == "PublicMediaTlsPolicy", "Media binding may only modify the existing public-media bucket policy.")
        else:
            require(resource.get("Action") == "Add", "Initial foundation/edge provisioning is create-only.")


def stack_execution_observation(aws, stack_id, change_id):
    # Query the exact created identities, not just a reusable stack/change-set name.
    change = aws.call("cloudformation", "describe-change-set", "--stack-name", stack_id, "--change-set-name", change_id)
    return {"change": change, "stack": stack_description(aws, stack_id)}


def stack_execution_complete(observation, stack_id, change_id, parameters, update):
    change, stack = observation["change"], observation["stack"]
    if not stack or change.get("ChangeSetId") != change_id or change.get("StackId") != stack_id or stack.get("StackId") != stack_id:
        return False
    if change.get("Status") != "CREATE_COMPLETE" or change.get("ExecutionStatus") != "EXECUTE_COMPLETE":
        return False
    expected_status = "UPDATE_COMPLETE" if update else "CREATE_COMPLETE"
    actual_parameters = {p["ParameterKey"]: p.get("ParameterValue", "") for p in stack.get("Parameters", [])}
    return stack.get("StackStatus") == expected_status and actual_parameters == parameters


def stack_execution_failed(observation, stack_id, change_id):
    change, stack = observation["change"], observation["stack"]
    if change.get("ChangeSetId") != change_id or change.get("StackId") != stack_id or (stack and stack.get("StackId") != stack_id):
        return True
    if change.get("ExecutionStatus") in {"EXECUTE_FAILED", "OBSOLETE"}:
        return True
    if any(word in change.get("Status", "") for word in ("FAILED", "DELETE")):
        return True
    return bool(stack and any(word in stack.get("StackStatus", "") for word in ("FAILED", "ROLLBACK", "DELETE")))


def apply_stack(c, aws, action, plan):
    name = action["stack"]
    update = action["kind"] == "cloudformation-policy-only-update"
    existing = stack_description(aws, name)
    if existing:
        require(owned_stack(c, existing), "A stack with this name already exists and is not owned by this deployment journal.")
        if not update and existing["StackStatus"] == "CREATE_COMPLETE":
            existing_parameters = {p["ParameterKey"]: p.get("ParameterValue", "") for p in existing.get("Parameters", [])}
            require(existing_parameters == action["parameters"], "Owned existing stack parameters differ; it cannot be adopted silently.")
            template = aws.call("cloudformation", "get-template", "--stack-name", name, "--template-stage", "Original").get("TemplateBody")
            if isinstance(template, str):
                try:
                    template = json.loads(template)
                except ValueError:
                    raise GuardError("The existing stack template is not the matching reviewed JSON template.") from None
            require(template == read_json(HERE / action["template"]), "Owned existing stack template differs; it cannot be adopted silently.")
            return {o["OutputKey"]: o["OutputValue"] for o in existing.get("Outputs", [])}
        require(update or existing["StackStatus"] == "REVIEW_IN_PROGRESS", "Creation is already running or failed; reconcile its existing state before retrying. No duplicate stack is created.")
    require(existing is not None or not update, "The data stack must already exist for media binding.")
    change_name = "wk-" + plan["sha256"][:24]
    arguments = ["--stack-name", name, "--change-set-name", change_name, "--change-set-type", "UPDATE" if update else "CREATE",
                 "--template-body", "file://" + str(HERE / action["template"]), "--parameters", json.dumps([{"ParameterKey": k, "ParameterValue": v} for k, v in action["parameters"].items()]),
                 "--tags", json.dumps([{"Key": "WaithakaDeployment", "Value": c["deployment_id"]}]), "--client-token", plan["sha256"]]
    created = aws.call("cloudformation", "create-change-set", *arguments)
    change_id, stack_id = created.get("Id"), created.get("StackId")
    arn_prefix = "arn:aws:cloudformation:" + c["region"] + ":" + c["account_id"] + ":"
    require(isinstance(change_id, str) and change_id.startswith(arn_prefix + "changeSet/" + change_name + "/")
            and isinstance(stack_id, str) and stack_id.startswith(arn_prefix + "stack/" + name + "/"), "Created change set returned unexpected AWS identities; no execution was requested.")
    description = wait_for(lambda: aws.call("cloudformation", "describe-change-set", "--stack-name", stack_id, "--change-set-name", change_id), lambda r: r["Status"] == "CREATE_COMPLETE", lambda r: r["Status"] == "FAILED", "CloudFormation change set", 600)
    require(description.get("ChangeSetId") == change_id and description.get("StackId") == stack_id, "The reviewed change-set identities changed; no execution was requested.")
    validate_change_set(description.get("Changes", []), update)
    evidence_path = Path(c["state_dir"]) / (plan["phase"] + "-changeset-" + plan["sha256"][:12] + ".json")
    evidence = {"plan_sha256": plan["sha256"], "change_set_id": change_id, "stack_id": stack_id, "changes": description["Changes"]}
    write_json(evidence_path, evidence, overwrite=True)
    aws.call("cloudformation", "execute-change-set", "--stack-name", stack_id, "--change-set-name", change_id, "--client-request-token", plan["sha256"])
    observed = wait_for(lambda: stack_execution_observation(aws, stack_id, change_id),
                        lambda result: stack_execution_complete(result, stack_id, change_id, action["parameters"], update),
                        lambda result: stack_execution_failed(result, stack_id, change_id), "CloudFormation change-set execution and stack")
    row = observed["stack"]
    require(owned_stack(c, row), "The completed stack ownership tag changed; phase completion was not recorded.")
    evidence.update(execution_status="EXECUTE_COMPLETE", stack_status=row["StackStatus"], parameters_verified=True,
                    verified_at=dt.datetime.now(dt.timezone.utc).isoformat())
    write_json(evidence_path, evidence, overwrite=True)
    return {o["OutputKey"]: o["OutputValue"] for o in row.get("Outputs", [])}


def put_verified(c, aws, bucket, item):
    expected = base64.b64encode(bytes.fromhex(item["sha256"])).decode()
    try:
        head = aws.call("s3api", "head-object", "--bucket", bucket, "--key", item["key"], "--checksum-mode", "ENABLED", "--expected-bucket-owner", c["account_id"])
    except AwsError as error:
        require(error.code in {"404", "NoSuchKey", "NotFound"}, "Cannot establish whether the target object exists; upload refused.")
        require(Path(item["source"]).stat().st_size == item["bytes"] and file_hash(item["source"]) == item["sha256"], "A source media/release file changed after planning.")
        aws.call("s3api", "put-object", "--bucket", bucket, "--key", item["key"], "--body", item["source"], "--content-type", item["content_type"], "--server-side-encryption", "AES256", "--checksum-sha256", expected, "--if-none-match", "*", "--expected-bucket-owner", c["account_id"])
        head = aws.call("s3api", "head-object", "--bucket", bucket, "--key", item["key"], "--checksum-mode", "ENABLED", "--expected-bucket-owner", c["account_id"])
    require(head.get("ContentLength") == item["bytes"] and head.get("ChecksumSHA256") == expected and head.get("VersionId") not in {None, "null"}, "Target object hash/length/version does not match. No object is overwritten; reconcile any previous version explicitly.")
    return {"bucket": bucket, "key": item["key"], "bytes": item["bytes"], "sha256": item["sha256"], "version_id": head["VersionId"]}


def ensure_policy(c, aws, action, state):
    try:
        old = aws.call("iam", "get-role-policy", "--role-name", action["role"], "--policy-name", action["name"])["PolicyDocument"]
        allowed = [action["policy"]]
        if "application" in state["completed"]:
            allowed.append(runtime_policy(c, state["completed"]["foundation"]))
        require(old in allowed, "An existing same-named IAM inline policy differs from this journal's allowed policies; refusing to replace it.")
        if old == action["policy"]:
            return
    except AwsError as error:
        if error.code != "NoSuchEntity":
            raise
    aws.call("iam", "put-role-policy", "--role-name", action["role"], "--policy-name", action["name"], "--policy-document", json.dumps(action["policy"]))


def env_description(c, aws):
    rows = aws.call("elasticbeanstalk", "describe-environments", "--application-name", c["application"]["name"], "--environment-names", c["application"]["environment_name"], "--no-include-deleted")["Environments"]
    return rows[0] if rows else None


def check_environment_owner(c, aws, row):
    tags = aws.call("elasticbeanstalk", "list-tags-for-resource", "--resource-arn", row["EnvironmentArn"])["ResourceTags"]
    require(any(t["Key"] == "WaithakaDeployment" and t["Value"] == c["deployment_id"] for t in tags), "The existing application environment is not owned by this deployment.")


def wait_environment(c, aws):
    return wait_for(lambda: env_description(c, aws), lambda r: r and r["Status"] == "Ready", lambda r: r and r["Status"] in {"Terminated", "Terminating"}, "Beanstalk environment")


def check_environment_options(c, aws, expected):
    """Ready alone also describes a rolled-back update. Verify deployed values."""
    settings = aws.call("elasticbeanstalk", "describe-configuration-settings", "--application-name", c["application"]["name"], "--environment-name", c["application"]["environment_name"])["ConfigurationSettings"]
    deployed = [row for row in settings if row.get("DeploymentStatus") == "deployed" and row.get("ApplicationName") == c["application"]["name"] and row.get("EnvironmentName") == c["application"]["environment_name"]]
    require(len(deployed) == 1 and not any(row.get("DeploymentStatus") == "pending" for row in settings), "A stable deployed environment configuration is not available; no phase completion is recorded.")
    actual = {}
    for option in deployed[0].get("OptionSettings", []):
        actual.setdefault((option.get("Namespace"), option.get("OptionName")), []).append(str(option.get("Value", "")))
    for option in expected:
        key = (option["Namespace"], option["OptionName"])
        values = actual.get(key, [])
        wanted = option["Value"]
        # AWS may normalize list order or a named role/profile to its exact ARN.
        if key[1] in {"Subnets", "ELBSubnets", "SecurityGroups"}:
            matches = len(values) == 1 and set(values[0].split(",")) == set(wanted.split(","))
        elif key[1] in {"ServiceRole", "IamInstanceProfile"}:
            resource = "role" if key[1] == "ServiceRole" else "instance-profile"
            matches = len(values) == 1 and (values[0] == wanted or bool(re.fullmatch(r"arn:aws:iam::" + c["account_id"] + ":" + resource + r"/(?:[A-Za-z0-9+=,.@_-]+/)*" + re.escape(wanted), values[0])))
        else:
            matches = values == [wanted]
        require(matches, "Deployed environment settings differ from the reviewed plan. Work is not claimed paused/activated; inspect configuration and reconcile before continuing. Values are not printed.")


def environment_expected_update(c, data, values):
    options = app_options(c, data)
    namespace = "aws:elasticbeanstalk:application:environment"
    replacement = {(namespace, key): value for key, value in values.items()}
    for option in options:
        key = (option["Namespace"], option["OptionName"])
        if key in replacement:
            option["Value"] = replacement.pop(key)
    options.extend({"Namespace": key[0], "OptionName": key[1], "Value": value} for key, value in replacement.items())
    return options


def apply_application(c, aws, action, plan):
    a = c["application"]
    tags = json.dumps([{"Key": "WaithakaDeployment", "Value": c["deployment_id"]}])
    applications = aws.call("elasticbeanstalk", "describe-applications", "--application-names", a["name"])["Applications"]
    if applications:
        existing_tags = aws.call("elasticbeanstalk", "list-tags-for-resource", "--resource-arn", applications[0]["ApplicationArn"])["ResourceTags"]
        require(any(t["Key"] == "WaithakaDeployment" and t["Value"] == c["deployment_id"] for t in existing_tags), "Existing Beanstalk application is not owned by this deployment.")
    else:
        aws.call("elasticbeanstalk", "create-application", "--application-name", a["name"], "--tags", tags)
    artifact = plan["artifacts"]["application_zip"]
    version = "recovery-" + artifact["sha256"][:40]
    put_verified(c, aws, action["release_bucket"], {"source": artifact["path"], "key": action["release_key"], "bytes": artifact["bytes"], "sha256": artifact["sha256"], "content_type": "application/zip"})
    versions = aws.call("elasticbeanstalk", "describe-application-versions", "--application-name", a["name"], "--version-labels", version)["ApplicationVersions"]
    if versions:
        require(versions[0]["SourceBundle"] == {"S3Bucket": action["release_bucket"], "S3Key": action["release_key"]}, "Application version label already points to another artifact.")
    else:
        aws.call("elasticbeanstalk", "create-application-version", "--application-name", a["name"], "--version-label", version, "--source-bundle", json.dumps({"S3Bucket": action["release_bucket"], "S3Key": action["release_key"]}))
    row = env_description(c, aws)
    if row:
        check_environment_owner(c, aws, row)
        require(row["VersionLabel"] == version and row.get("PlatformArn") == a["platform_arn"], "Existing environment differs from the planned release/platform.")
        check_environment_options(c, aws, action["options"])
    else:
        aws.call("elasticbeanstalk", "create-environment", "--application-name", a["name"], "--environment-name", a["environment_name"], "--version-label", version, "--platform-arn", a["platform_arn"], "--option-settings", json.dumps(action["options"]), "--tags", tags)
    row = wait_environment(c, aws)
    check_environment_options(c, aws, action["options"])
    resources = aws.call("elasticbeanstalk", "describe-environment-resources", "--environment-id", row["EnvironmentId"])["EnvironmentResources"]
    return {"environment_id": row["EnvironmentId"], "environment_arn": row["EnvironmentArn"], "cname": row["CNAME"], "load_balancers": resources.get("LoadBalancers", []), "release_sha256": artifact["sha256"], "version_label": version, "work_paused": True}


def capture_dns(c, aws):
    require(c["dns"]["provider"] == "route53", "DNS snapshots through AWS are only available when Route 53 is explicitly selected.")
    aws.identity()
    records = {}
    for zone in set(c["dns"]["zones"].values()):
        hosted = aws.call("route53", "get-hosted-zone", "--id", zone)["HostedZone"]
        require(not hosted.get("Config", {}).get("PrivateZone", False), "CloudFront needs public hosted zones.")
        name = hosted["Name"].rstrip(".")
        rows = aws.call("route53", "list-resource-record-sets", "--hosted-zone-id", zone)["ResourceRecordSets"]
        for host, target_zone in c["dns"]["zones"].items():
            if target_zone == zone:
                require(host == name or host.endswith("." + name), "Hosted zone does not contain its configured hostname.")
                records[host] = sorted([r for r in rows if r["Name"].rstrip(".") == host and r["Type"] in {"A", "AAAA", "CNAME"}], key=canonical)
    return {"schema_version": 1, "config_sha256": digest(c), "created_at": int(time.time()), "records": records}


def apply_plan(path, expected_hash):
    saved = read_json(private_file(path))
    require(isinstance(saved, dict) and saved.get("phase") in PHASES, "Invalid deployment plan.")
    claimed = saved.pop("sha256", None)
    require(isinstance(expected_hash, str) and re.fullmatch(r"[a-f0-9]{64}", expected_hash) and hmac.compare_digest(digest(saved), expected_hash) and claimed == expected_hash, "Plan confirmation hash does not match; nothing was applied.")
    saved["sha256"] = claimed
    c = validate_config(saved["config"])
    directory = Path(c["state_dir"])
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    require(not directory.is_symlink() and stat.S_IMODE(directory.stat().st_mode) & 0o077 == 0, "Use a private state directory with mode 0700.")
    lock_path = directory / "apply.lock"
    require(not lock_path.is_symlink(), "Refusing a symlinked deployment lock.")
    with lock_path.open("a") as lock:
        os.chmod(lock_path, 0o600)
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise GuardError("Another deployment phase owns this journal.") from None
        state = load_state(c)
        fresh = build_plan(c, saved["phase"], state)
        require(fresh == saved, "Configuration, artifacts, templates, receipts or deployment state changed after review. Generate a new plan.")
        aws = Aws(c)
        preflight(c, aws)  # Entirely read-only; all early guards precede any mutation.
        save_state(c, state)
        if saved["phase"] == "cutover" and c["dns"]["provider"] == "route53":
            current = capture_dns(c, aws)
            require(current["records"] == saved["dns_snapshot"]["records"], "DNS changed since the reviewed snapshot; no records were changed. Capture and review a new plan.")
        result = {}
        for action in saved["actions"]:
            kind = action["kind"]
            if kind in {"cloudformation-create-only", "cloudformation-policy-only-update"}:
                result.update(apply_stack(c, aws, action, saved))
            elif kind == "put-if-absent-verify-sha256":
                objects = [put_verified(c, aws, action["public_bucket"] if item["visibility"] == "public" else action["private_bucket"], item) for item in action["objects"]]
                receipt_path = directory / ("media-copy-" + saved["sha256"][:12] + ".json")
                write_json(receipt_path, {"plan_sha256": saved["sha256"], "objects": objects}, overwrite=True)
                result.update(media_sha256=saved["artifacts"]["media_sha256"], object_count=len(objects), receipt=str(receipt_path))
            elif kind == "compare-original-key-to-runtime-secret":
                compare_key(c, aws)
            elif kind == "owned-inline-runtime-policy":
                ensure_policy(c, aws, action, state)
            elif kind == "new-beanstalk-application-and-environment":
                result.update(apply_application(c, aws, action, saved))
            elif kind == "environment-options":
                row = env_description(c, aws)
                require(row is not None, "Target application environment is missing.")
                check_environment_owner(c, aws, row)
                require(row["EnvironmentId"] == state["completed"]["application"]["environment_id"], "Environment identity changed; update refused.")
                require(row.get("VersionLabel") == state["completed"]["application"]["version_label"] and row.get("PlatformArn") == c["application"]["platform_arn"], "Environment code/platform changed outside the install plan; activation refused.")
                immutable = [option for option in app_options(c, state["completed"]["foundation"]) if not (option["Namespace"] == "aws:elasticbeanstalk:application:environment" and option["OptionName"] in action["values"])]
                check_environment_options(c, aws, immutable)
                aws.call("elasticbeanstalk", "update-environment", "--environment-id", row["EnvironmentId"], "--option-settings", json.dumps([{"Namespace": "aws:elasticbeanstalk:application:environment", "OptionName": k, "Value": v} for k, v in action["values"].items()]))
                wait_environment(c, aws)
                check_environment_options(c, aws, environment_expected_update(c, state["completed"]["foundation"], action["values"]))
                result["environment_values"] = action["values"]
            elif kind == "dns-address-records-only":
                response = aws.call("route53", "change-resource-record-sets", "--hosted-zone-id", action["zone_id"], "--change-batch", json.dumps(action["change_batch"]))
                change_id = response["ChangeInfo"]["Id"]
                # Journal each zone separately: a failure never hides an earlier accepted change.
                state.setdefault("dns_changes", []).append({"zone_id": action["zone_id"], "change_id": change_id, "plan_sha256": saved["sha256"]})
                save_state(c, state)
                wait_for(lambda: aws.call("route53", "get-change", "--id", change_id), lambda r: r["ChangeInfo"]["Status"] == "INSYNC", lambda r: False, "Route 53 change", 900)
            elif kind == "record-external-dns-acceptance":
                result["external_dns_attested"] = True
            else:
                raise GuardError("Unrecognized planned action; no arbitrary command execution is supported.")
        result.update(plan_sha256=saved["sha256"], artifacts_sha256=digest(saved["artifacts"]), completed_at=dt.datetime.now(dt.timezone.utc).isoformat())
        state["completed"][saved["phase"]] = result
        save_state(c, state)
        print(f"Completed phase {saved['phase']}. State and evidence saved privately. This is not a substitute for live acceptance.")


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    plan = commands.add_parser("plan", help="Offline only: validate inputs and write an exact phase plan.")
    plan.add_argument("--config", required=True); plan.add_argument("--phase", choices=PHASES, required=True); plan.add_argument("--out", required=True)
    apply = commands.add_parser("apply", help="Perform the reviewed phase; requires its exact SHA-256.")
    apply.add_argument("--plan", required=True); apply.add_argument("--confirm-sha256", required=True)
    for name in ("inventory", "receipt-template", "check-aws", "capture-dns"):
        p = commands.add_parser(name)
        p.add_argument("--config", required=True); p.add_argument("--out", required=True)
        if name == "receipt-template":
            p.add_argument("--kind", required=True, choices=tuple(RECEIPT_CHECKS))
    args = parser.parse_args(argv)
    try:
        if args.command == "apply":
            apply_plan(args.plan, args.confirm_sha256)
            return 0
        c = validate_config(read_json(args.config))
        if args.command == "plan":
            value = build_plan(c, args.phase)
        elif args.command == "inventory":
            value = artifact_evidence(c)
        elif args.command == "receipt-template":
            value = receipt_template(c, args.kind, artifact_evidence(c), load_state(c))
        elif args.command == "check-aws":
            value = preflight(c, Aws(c))
        else:
            value = capture_dns(c, Aws(c))
        require(not Path(args.out).resolve().is_relative_to(ROOT), "Save private plans, inventories and receipts outside the application source tree.")
        write_json(Path(args.out).absolute(), value)
        print("Saved " + str(Path(args.out).absolute()))
        if args.command == "plan":
            print("Offline plan SHA-256: " + value["sha256"])
            print("No AWS request was made. Review the entire plan before apply --confirm-sha256.")
        elif args.command == "receipt-template":
            print("All checks start false. Fill only after performing the named checks; a receipt is an operator attestation.")
        return 0
    except (GuardError, OSError, subprocess.TimeoutExpired, KeyError, TypeError, ValueError, AttributeError) as error:
        # Files and remote responses may contain secrets: never print their content.
        message = str(error) if isinstance(error, GuardError) else "A local file/command failed or timed out. Details suppressed; inspect private inputs and the AWS console."
        print("Stopped: " + message, file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
