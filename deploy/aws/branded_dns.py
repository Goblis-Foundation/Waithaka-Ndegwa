#!/usr/bin/env python3
"""Reviewable Route 53 vanity DNS preparation; never changes a registrar or DS.

Python 3.10+, AWS CLI v2 and dig. Offline planning needs no AWS credentials.
See BRANDED-NAMESERVERS.md. All evidence/state files are private operator files.
"""
from __future__ import annotations

import argparse
import copy
import datetime as dt
import fcntl
import ipaddress
import json
import os
from pathlib import Path
import re
import stat
import sys
import time

from install_cutover import (Aws, AwsError, GuardError, ROOT, canonical, digest,
                            file_hash, private_file, read_json, real_text,
                            require, write_json)
from dns_probe import DNSProbeError, DigProbe, verify_delegation

DOMAINS = ("waithakandegwa.com", "waithakandegwa.co.ke")
NAMES = tuple(f"ns{i}.{DOMAINS[0]}." for i in range(1, 5))
RECORD_TYPES = {"A", "AAAA", "CAA", "CNAME", "DS", "HTTPS", "MX", "NAPTR", "NS", "PTR", "SOA", "SPF", "SRV", "SSHFP", "SVCB", "TLSA", "TXT"}


def now():
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def fresh(value, seconds=86400):
    try:
        stamp = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
        age = (dt.datetime.now(dt.timezone.utc) - stamp).total_seconds() if stamp.tzinfo is not None else None
        require(age is not None and age >= -60 and (seconds is None or age <= seconds),
                "Evidence is stale or future-dated; capture it again before changing DNS.")
    except (ValueError, TypeError, AttributeError):
        raise GuardError("Evidence requires an ISO-8601 timestamp with timezone.") from None


def fqdn(value):
    require(isinstance(value, str) and value and not any(ord(c) < 33 for c in value), "Invalid DNS name.")
    name = value.lower().rstrip(".") + "."
    # Route 53 returns an escaped wildcard owner; compare it to imported '*'.
    return "*." + name[5:] if name.startswith("\\052.") else name


def identifier(value, kind):
    prefix = "/hostedzone/" if kind == "zone" else "/delegationset/"
    require(isinstance(value, str), "A real AWS resource ID is required.")
    value = value.removeprefix(prefix)
    return real_text(value, kind + " ID", r"Z[A-Z0-9]{4,31}" if kind == "zone" else r"N[A-Z0-9]{4,31}")


def validate_config(c):
    try:
        require(c["schema_version"] == 1, "DNS configuration schema_version must be 1.")
        real_text(c["deployment_id"], "deployment ID", r"[a-z][a-z0-9-]{5,35}")
        real_text(c["account_id"], "personal AWS account", r"[0-9]{12}")
        require(c["account_id"] not in {"000000000000", "123456789012"}, "Select the actual personal AWS account.")
        real_text(c["profile"], "AWS profile", r"[A-Za-z0-9_.-]{1,64}")
        real_text(c["region"], "AWS region", r"[a-z]{2}(?:-[a-z]+)+-[1-9]")
        require(not c["region"].startswith(("cn-", "us-gov-")), "Only the commercial AWS partition is supported.")
        state = Path(c["state_dir"])
        require(state.is_absolute() and not state.is_symlink() and not state.resolve().is_relative_to(ROOT), "Use a private DNS state directory outside the application repository.")
        ds = c["delegation_set"]
        require(set(ds) == {"id", "adopt_zone_id"} and not (ds["id"] and ds["adopt_zone_id"]), "Choose an existing reusable set, an adoption zone, or neither for a new set.")
        if ds["id"]:
            identifier(ds["id"], "set")
        if ds["adopt_zone_id"]:
            identifier(ds["adopt_zone_id"], "zone")
        require(set(c["zones"]) == set(DOMAINS), "Configure exactly the .com and .co.ke hosted zones.")
        for name, zone in c["zones"].items():
            require(set(zone) == {"hosted_zone_id", "source_export"}, "Each zone needs hosted_zone_id and source_export.")
            if zone["hosted_zone_id"]:
                identifier(zone["hosted_zone_id"], "zone")
            path = Path(zone["source_export"])
            require(path.is_absolute() and not path.is_symlink() and not path.resolve().is_relative_to(ROOT), "Keep complete DNS exports outside the public source tree.")
        if ds["adopt_zone_id"]:
            require(identifier(ds["adopt_zone_id"], "zone") in [identifier(z["hosted_zone_id"], "zone") for z in c["zones"].values() if z["hosted_zone_id"]], "The adoption zone must be one of the explicitly retained target zones.")
        require(not any(z["hosted_zone_id"] for z in c["zones"].values()) or ds["id"] or ds["adopt_zone_id"], "Retaining an existing zone requires its reusable set ID or explicit adoption; a newly assigned set cannot be attached to an existing zone.")
    except (KeyError, TypeError, ValueError):
        raise GuardError("Missing or malformed DNS configuration; follow branded-dns-config.example.json.") from None
    return c


def state_path(c):
    return Path(c["state_dir"]) / "branded-dns-state.json"


def state(c):
    if not state_path(c).exists():
        return {"schema_version": 1, "config_sha256": digest(c), "resources": {}, "applications": {}}
    value = read_json(private_file(state_path(c)))
    require(value.get("config_sha256") == digest(c), "DNS state belongs to another configuration; do not change account or resource inputs midway.")
    return value


def save(c, value):
    write_json(state_path(c), value, overwrite=True)


def application_started(journal):
    return bool(journal.get("completed_at") or any(zone.get("completed", 0) or any(change.get("submitted") for change in zone.get("changes", {}).values()) for zone in journal.get("zones", {}).values()))


class StateLock:
    def __init__(self, c):
        self.directory = Path(c["state_dir"])

    def __enter__(self):
        self.directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        require(not self.directory.is_symlink() and self.directory.stat().st_uid == os.getuid() and stat.S_IMODE(self.directory.stat().st_mode) & 0o077 == 0, "DNS state directory must be owned by this operator and mode 0700.")
        path = self.directory / "branded-dns.lock"
        fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        self.handle = os.fdopen(fd, "a")
        try:
            fcntl.flock(self.handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            self.handle.close()
            raise GuardError("Another DNS operation holds this state lock.") from None
        return self

    def __exit__(self, *args):
        self.handle.close()


def list_all(aws, operation, result_key):
    rows, arguments, seen = [], [], set()
    while True:
        result = aws.call("route53", operation, "--no-paginate", *arguments)
        require(isinstance(result.get(result_key), list), "Unexpected Route 53 inventory response.")
        rows.extend(result[result_key])
        if not result.get("IsTruncated"):
            return rows
        marker = result.get("NextMarker")
        require(isinstance(marker, str) and marker and marker not in seen, "Incomplete or looping Route 53 pagination.")
        seen.add(marker)
        arguments = ["--marker", marker]


def records(aws, zone_id):
    rows, arguments, seen = [], [], set()
    while True:
        result = aws.call("route53", "list-resource-record-sets", "--hosted-zone-id", zone_id, "--no-paginate", *arguments)
        require(isinstance(result.get("ResourceRecordSets"), list), "Unexpected DNS record inventory.")
        rows.extend(result["ResourceRecordSets"])
        if not result.get("IsTruncated"):
            return normalized(rows)
        name, rrtype = result.get("NextRecordName"), result.get("NextRecordType")
        require(isinstance(name, str) and isinstance(rrtype, str), "Truncated DNS export has no continuation key.")
        marker = (name, rrtype, result.get("NextRecordIdentifier", ""))
        require(marker not in seen, "DNS record pagination repeated; export refused.")
        seen.add(marker)
        arguments = ["--start-record-name", name, "--start-record-type", rrtype]
        if marker[2]:
            arguments += ["--start-record-identifier", marker[2]]


def record_key(record):
    return (fqdn(record["Name"]), record["Type"], record.get("SetIdentifier", ""))


def normalized(rows):
    require(isinstance(rows, list), "DNS records must be an array of Route 53 record sets.")
    result, seen = [], set()
    for original in rows:
        require(isinstance(original, dict), "Malformed record set.")
        r = copy.deepcopy(original)
        require(isinstance(r.get("Name"), str) and r.get("Type") in RECORD_TYPES, "Unsupported or malformed DNS record; review and convert explicitly.")
        r["Name"] = fqdn(r["Name"])
        if "ResourceRecords" in r:
            require(isinstance(r["ResourceRecords"], list) and r["ResourceRecords"] and type(r.get("TTL")) is int and 0 <= r["TTL"] <= 2147483647, "Record values and TTL are required.")
            require(all(isinstance(v, dict) and set(v) == {"Value"} and isinstance(v["Value"], str) and v["Value"] for v in r["ResourceRecords"]), "Malformed record value.")
            r["ResourceRecords"].sort(key=lambda v: v["Value"])
        else:
            require(isinstance(r.get("AliasTarget"), dict) and "TTL" not in r, "Records need explicit values or an alias target.")
        require(not ("AliasTarget" in r and "ResourceRecords" in r), "A DNS record cannot be both an alias and a value set.")
        if "AliasTarget" in r:
            alias = r["AliasTarget"]
            require(set(alias) == {"HostedZoneId", "DNSName", "EvaluateTargetHealth"} and type(alias["EvaluateTargetHealth"]) is bool, "An alias needs its resource zone, DNS target and explicit health evaluation.")
            alias["HostedZoneId"] = identifier(alias["HostedZoneId"], "zone")
            alias["DNSName"] = fqdn(alias["DNSName"])
        key = record_key(r)
        require(key not in seen, "Duplicate DNS record identity in export.")
        seen.add(key)
        result.append(r)
    by_name = {}
    for r in result:
        by_name.setdefault(r["Name"], []).append(r)
    for group in by_name.values():
        require(not any(r["Type"] == "CNAME" for r in group) or all(r["Type"] == "CNAME" for r in group), "A CNAME cannot coexist with another record type at the same name.")
        for rrtype in {r["Type"] for r in group}:
            same = [r for r in group if r["Type"] == rrtype]
            require(len(same) == 1 or all(r.get("SetIdentifier") for r in same), "Simple and policy record sets cannot coexist for one name/type.")
    return sorted(result, key=record_key)


def owned_zone(aws, zone_id, domain):
    result = aws.call("route53", "get-hosted-zone", "--id", zone_id)
    z = result.get("HostedZone", {})
    require(identifier(z.get("Id", ""), "zone") == zone_id and fqdn(z.get("Name", "")) == fqdn(domain) and z.get("Config", {}).get("PrivateZone") is False, "Hosted zone identity/name/public status does not match the intended domain.")
    return result


def server_names(value):
    names = sorted(fqdn(n) for n in value.get("NameServers", []))
    require(len(names) == len(set(names)) == 4 and all(re.fullmatch(r"ns-[0-9]+\.awsdns-[0-9]+\.(?:com|net|org|co\.uk)\.", n) for n in names), "Expected four actual Route 53 authoritative nameservers.")
    return names


def provision_plan(c):
    return {"kind": "waithaka-branded-dns-provision", "schema_version": 1, "config_sha256": digest(c), "account_id": c["account_id"],
            "delegation_set": c["delegation_set"], "zones": {d: c["zones"][d]["hosted_zone_id"] or "CREATE" for d in DOMAINS},
            "caller_prefix": c["deployment_id"] + "-" + digest(c)[:20], "nameservers": list(NAMES),
            "effect": "Create or adopt one reusable delegation set and create missing public zones. Existing zones must already use that physical set. No registrar, record, DS or deletion actions."}


def provision(c, plan, sha256, aws):
    require(digest(plan) == sha256 and plan == provision_plan(c), "Provision plan/hash does not match this configuration.")
    aws.identity()
    s = state(c)
    res = s["resources"]
    wanted = c["delegation_set"]
    caller = plan["caller_prefix"]
    if "delegation_set_id" not in res:
        if wanted["id"]:
            chosen = aws.call("route53", "get-reusable-delegation-set", "--id", identifier(wanted["id"], "set"))["DelegationSet"]
            require(identifier(chosen.get("Id", ""), "set") == identifier(wanted["id"], "set"), "AWS returned a different reusable delegation-set identity.")
        else:
            adoption = wanted["adopt_zone_id"]
            expected = None
            if adoption:
                target_domain = next(d for d in DOMAINS if c["zones"][d]["hosted_zone_id"] and identifier(c["zones"][d]["hosted_zone_id"], "zone") == identifier(adoption, "zone"))
                expected = server_names(owned_zone(aws, identifier(adoption, "zone"), target_domain)["DelegationSet"])
            found = [v for v in list_all(aws, "list-reusable-delegation-sets", "DelegationSets") if v.get("CallerReference") == caller or (expected and server_names(v) == expected)]
            require(len(found) <= 1, "Reusable delegation set recovery is ambiguous.")
            if found:
                chosen = found[0]
            else:
                args = ["--caller-reference", caller]
                if adoption:
                    args += ["--hosted-zone-id", identifier(adoption, "zone")]
                chosen = aws.call("route53", "create-reusable-delegation-set", *args)["DelegationSet"]
            if expected:
                require(server_names(chosen) == expected, "Adoption returned another physical server set.")
        res.update(delegation_set_id=identifier(chosen["Id"], "set"), aws_nameservers=server_names(chosen), zones={})
        save(c, s)
    actual = aws.call("route53", "get-reusable-delegation-set", "--id", res["delegation_set_id"])["DelegationSet"]
    require(identifier(actual.get("Id", ""), "set") == res["delegation_set_id"] and server_names(actual) == res["aws_nameservers"], "Reusable delegation set identity drifted.")
    for domain in DOMAINS:
        if domain not in res["zones"]:
            configured = c["zones"][domain]["hosted_zone_id"]
            if configured:
                zone_id = identifier(configured, "zone")
            else:
                reference = caller + "-" + domain
                found = [v for v in list_all(aws, "list-hosted-zones", "HostedZones") if v.get("CallerReference") == reference]
                require(len(found) <= 1, "Hosted-zone recovery is ambiguous.")
                if found:
                    zone_id = identifier(found[0]["Id"], "zone")
                else:
                    created = aws.call("route53", "create-hosted-zone", "--name", domain, "--caller-reference", reference, "--delegation-set-id", res["delegation_set_id"], "--hosted-zone-config", json.dumps({"Comment": "Waithaka personal site: " + c["deployment_id"], "PrivateZone": False}))
                    zone_id = identifier(created["HostedZone"]["Id"], "zone")
                    # Persist identity before any subsequent read/wait can fail.
                    res["zones"][domain] = zone_id
                    save(c, s)
            zone = owned_zone(aws, zone_id, domain)
            require(server_names(zone["DelegationSet"]) == res["aws_nameservers"], "Existing zone uses another physical delegation set. Create a replacement and migrate its complete export; changing NS alone cannot reassign it.")
            res["zones"][domain] = zone_id
            save(c, s)
        zone = owned_zone(aws, res["zones"][domain], domain)
        require(server_names(zone["DelegationSet"]) == res["aws_nameservers"], "Target zone and reusable delegation set differ.")
    return res


def export_zone(c, aws, domain, zone_id):
    require(domain in DOMAINS, "Only this project's two domains may be exported.")
    aws.identity()
    owned_zone(aws, zone_id, domain)
    return {"schema_version": 1, "domain": domain, "source_provider": "route53", "source_zone_id": zone_id, "exported_at": now(),
            "records": records(aws, zone_id), "review": {"reviewed_by": "", "domain_control_confirmed": False, "complete_zone_export": False, "dnssec_transition_reviewed": False, "new_zone": False},
            "meaning": "Complete API inventory, not proof that this zone is the current public authority. Complete the review from real provider/registrar evidence."}


def validate_mapping(mapping, expected):
    require(isinstance(mapping, list) and len(mapping) == 4 and [m.get("name") for m in mapping] == list(NAMES), "Incomplete or reordered branded nameserver mapping.")
    require([m.get("aws_name") for m in mapping] == sorted(expected), "Mapping differs from the pinned AWS server set.")
    addresses = set()
    for m in mapping:
        for field, version in (("ipv4", 4), ("ipv6", 6)):
            values = m.get(field)
            require(isinstance(values, list) and values and len(set(values)) == len(values), "Each server needs observed IPv4 and IPv6 addresses.")
            for value in values:
                try:
                    ip = ipaddress.ip_address(value)
                except ValueError:
                    raise GuardError("Nameserver address is invalid.") from None
                require(ip.version == version and ip.is_global and not ip.is_multicast and str(ip) == value and value not in addresses, "Nameserver addresses must be unique canonical public IPs, not examples or website addresses.")
                addresses.add(value)


def capture(c, aws, probe=None):
    aws.identity()
    probe = probe or DigProbe()
    s = state(c)
    res = s["resources"]
    require(set(res.get("zones", {})) == set(DOMAINS) and res.get("delegation_set_id"), "Complete the reviewed provisioning phase first.")
    ds = aws.call("route53", "get-reusable-delegation-set", "--id", res["delegation_set_id"])["DelegationSet"]
    names = server_names(ds)
    require(identifier(ds.get("Id", ""), "set") == res["delegation_set_id"] and names == res["aws_nameservers"], "Assigned nameservers/set identity changed; inspect the existing state.")
    mapping = [{"name": NAMES[i], "aws_name": name, **probe.addresses(name)} for i, name in enumerate(names)]
    validate_mapping(mapping, names)
    if s.get("mapping"):
        require(s["mapping"] == mapping, "Previously pinned authoritative addresses changed. Review glue and existing delegation before any rotation.")
    result = {"kind": "waithaka-branded-dns-snapshot", "schema_version": 1, "captured_at": now(), "config_sha256": digest(c),
              "account_id": c["account_id"], "delegation_set_id": res["delegation_set_id"], "mapping": mapping, "zones": {}}
    for domain in DOMAINS:
        zone_id = res["zones"][domain]
        zone = owned_zone(aws, zone_id, domain)
        require(server_names(zone["DelegationSet"]) == names, "Target zone is assigned to a different server set.")
        security = aws.call("route53", "get-dnssec", "--hosted-zone-id", zone_id)
        require(security.get("Status", {}).get("ServeSignature") in {"SIGNING", "NOT_SIGNING"}, "DNSSEC state is transitional or unhealthy; finish that operation first.")
        require(all(k.get("Status") in {"ACTIVE", "INACTIVE"} for k in security.get("KeySigningKeys", [])), "A DNSSEC key needs attention before migration.")
        result["zones"][domain] = {"hosted_zone_id": zone_id, "records": records(aws, zone_id), "dnssec": security}
    s["mapping"] = mapping
    save(c, s)
    return result


def managed(domain, r):
    return (r["Name"] == fqdn(domain) and r["Type"] in {"NS", "SOA"}) or (domain == DOMAINS[0] and r["Name"] in NAMES and r["Type"] in {"A", "AAAA"})


def source_records(c, domain, target_id, allow_stale=False):
    path = private_file(c["zones"][domain]["source_export"])
    source = read_json(path)
    require(source.get("schema_version") == 1 and source.get("domain") == domain, "Complete source export belongs to another domain/schema.")
    real_text(source.get("source_provider"), "source DNS provider")
    fresh(source.get("exported_at"), None if allow_stale else 7 * 86400)
    review = source.get("review", {})
    real_text(review.get("reviewed_by"), "DNS export reviewer")
    require(all(review.get(k) is True for k in ("domain_control_confirmed", "complete_zone_export", "dnssec_transition_reviewed")), "Provider export, registrar control and DNSSEC transition must be reviewed; public DNS enumeration is not a complete export.")
    rows = normalized(source.get("records"))
    if not rows:
        require(domain == DOMAINS[1] and review.get("new_zone") is True, "Only a confirmed genuinely new .co.ke zone may have an empty source export.")
    old_id = source.get("source_zone_id", "")
    if old_id:
        old_id = identifier(old_id, "zone")
    for r in rows:
        require(r["Name"] == fqdn(domain) or r["Name"].endswith("." + fqdn(domain)), "An export record lies outside its zone.")
        require("TrafficPolicyInstanceId" not in r, "Traffic Flow-managed records require a separate policy migration, not raw record copying.")
        if "AliasTarget" in r:
            alias = r["AliasTarget"]
            target_name = fqdn(alias.get("DNSName", ""))
            if target_name == fqdn(domain) or target_name.endswith("." + fqdn(domain)):
                require(old_id, "An internal-zone alias requires the actual source_zone_id so its reference can be migrated safely.")
            if old_id and alias.get("HostedZoneId", "").removeprefix("/hostedzone/") == old_id:
                alias["HostedZoneId"] = target_id
    return normalized(rows), {"path": str(path), "sha256": file_hash(path), "review": review}


def rr(name, rrtype, values, ttl):
    return {"Name": fqdn(name), "Type": rrtype, "TTL": ttl, "ResourceRecords": [{"Value": v} for v in sorted(values)]}


def batches(changes):
    # Reserve UPSERT's double counting even for CREATE, so ambiguous retries
    # can safely restate the same intended values without exceeding quotas.
    result, current, count, chars = [], [], 0, 0
    for change in changes:
        values = change["ResourceRecordSet"].get("ResourceRecords", [])
        n, length = 2 * max(1, len(values)), 2 * sum(len(v["Value"]) for v in values)
        require(n <= 1000 and length <= 32000, "A record set exceeds a Route 53 change-batch limit; resolve it explicitly.")
        if current and (count + n > 1000 or chars + length > 32000):
            result.append(current)
            current, count, chars = [], 0, 0
        current.append(change)
        count, chars = count + n, chars + length
    if current:
        result.append(current)
    return result


def apply_values(rows, changes):
    result = {record_key(r): r for r in rows}
    for change in changes:
        result[record_key(change["ResourceRecordSet"])] = change["ResourceRecordSet"]
    return normalized(list(result.values()))


def build_plan(c, snap, mode="prepare", allow_stale=False, verification_evidence=None):
    require(mode in {"prepare", "stabilize"}, "Select prepare or stabilize.")
    require(snap.get("kind") == "waithaka-branded-dns-snapshot" and snap.get("schema_version") == 1 and snap.get("config_sha256") == digest(c) and snap.get("account_id") == c["account_id"], "Snapshot identity/configuration differs.")
    fresh(snap.get("captured_at"), None if allow_stale else 86400)
    require(set(snap.get("zones", {})) == set(DOMAINS), "Snapshot must include both full zones.")
    s = state(c)
    require(snap["delegation_set_id"] == s["resources"].get("delegation_set_id") and {d: v["hosted_zone_id"] for d, v in snap["zones"].items()} == s["resources"].get("zones"), "Snapshot does not match provisioned physical resources.")
    validate_mapping(snap["mapping"], s["resources"]["aws_nameservers"])
    require(snap["mapping"] == s.get("mapping"), "Snapshot mapping is not the pinned mapping.")
    verification = None
    if mode == "stabilize":
        verification = verification_evidence if allow_stale and verification_evidence is not None else s.get("verified", {})
        require(verification.get("ok") is True and verification.get("zones") == s["resources"]["zones"], "Verify both domains' live delegation before restoring normal TTLs.")
        fresh(verification.get("checked_at"), None if allow_stale else 86400)
        require(verification.get("mapping_sha256") == digest(snap["mapping"]) and verification.get("dnssec_sha256") == digest({d: snap["zones"][d]["dnssec"] for d in DOMAINS}), "Mapping or DNSSEC differs from the successful delegation observation; verify again before raising TTLs.")
    ttl = {"ns": 60, "address": 60, "negative": 60} if mode == "prepare" else {"ns": 172800, "address": 3600, "negative": 900}
    plan = {"kind": "waithaka-branded-dns-records", "schema_version": 1, "config_sha256": digest(c), "account_id": c["account_id"],
            "snapshot": snap, "mode": mode, "ttl": ttl, "zones": {}, "sources": {}, "nameservers": list(NAMES)}
    if verification is not None:
        plan["verification"] = copy.deepcopy(verification)
    for domain in DOMAINS:
        item = snap["zones"][domain]
        before = normalized(item["records"])
        desired = {record_key(r): r for r in before}
        if mode == "prepare":
            imported, evidence = source_records(c, domain, item["hosted_zone_id"], allow_stale=allow_stale)
            plan["sources"][domain] = evidence
            for r in imported:
                if managed(domain, r):
                    continue
                k = record_key(r)
                require(k not in desired or desired[k] == r, "Source and target record values conflict. Reconcile explicitly; preparation never overwrites unrelated website/mail records.")
                desired[k] = r
        for r in desired.values():
            if domain == DOMAINS[0] and r["Name"] in NAMES:
                require(r["Type"] in {"A", "AAAA"} and set(r) == {"Name", "Type", "TTL", "ResourceRecords"}, "A nameserver name has an incompatible record/routing policy; resolve it first.")
        ns = [r for r in before if r["Name"] == fqdn(domain) and r["Type"] == "NS"]
        soa = [r for r in before if r["Name"] == fqdn(domain) and r["Type"] == "SOA"]
        require(len(ns) == len(soa) == 1 and set(ns[0]) == set(soa[0]) == {"Name", "Type", "TTL", "ResourceRecords"}, "Expected one simple apex NS and SOA record.")
        require(len(soa[0]["ResourceRecords"]) == 1, "Malformed SOA.")
        fields = soa[0]["ResourceRecords"][0]["Value"].split()
        require(len(fields) == 7 and all(v.isdigit() for v in fields[2:]), "Malformed SOA fields.")
        fields[0], fields[-1] = NAMES[0], str(ttl["negative"])
        wanted = [rr(domain, "NS", NAMES, ttl["ns"]), rr(domain, "SOA", [" ".join(fields)], soa[0]["TTL"])]
        if domain == DOMAINS[0]:
            for m in snap["mapping"]:
                wanted += [rr(m["name"], "A", m["ipv4"], ttl["address"]), rr(m["name"], "AAAA", m["ipv6"], ttl["address"])]
        for r in wanted:
            desired[record_key(r)] = r
        after = normalized(list(desired.values()))
        original = {record_key(r): r for r in before}
        changes = [{"Action": "UPSERT" if record_key(r) in original else "CREATE", "ResourceRecordSet": r} for r in after if original.get(record_key(r)) != r]
        plan["zones"][domain] = {"hosted_zone_id": item["hosted_zone_id"], "before_sha256": digest(before), "after_sha256": digest(after), "after": after, "batches": batches(changes)}
    plan["registrar_handoff"] = {"hosts_to_register_at_com_registrar": snap["mapping"], "delegation_order": list(DOMAINS), "nameservers_for_both_domains": list(NAMES),
        "gate": "After AWS preparation and full-record comparison, register matching IPv4 AND IPv6 host/glue entries. Check current parent DS and prior TTLs; verify .com first, then .co.ke. No registrar or DS write is automated.",
        "dnssec": {d: snap["zones"][d]["dnssec"] for d in DOMAINS}}
    return plan


def wait_change(aws, change_id):
    for _ in range(80):
        status = aws.call("route53", "get-change", "--id", change_id).get("ChangeInfo", {}).get("Status")
        if status == "INSYNC":
            return
        require(status == "PENDING", "Unexpected Route 53 change state; retain the journal.")
        print("Waiting for Route 53 authoritative propagation; registrar delegation remains separate.", flush=True)
        time.sleep(5)
    raise GuardError("Route 53 is still pending. Retry this same reviewed plan; its change ID is saved.")


def apply_plan(c, plan, sha256, aws, probe=None):
    require(digest(plan) == sha256, "Reviewed DNS plan SHA-256 differs.")
    prior = state(c)
    for other_hash, other in prior["applications"].items():
        require(other_hash == sha256 or other.get("completed_at") or not application_started(other), "Another DNS plan has an unfinished submitted change. Resume that exact saved plan and wait for its change IDs; a new snapshot cannot bypass INSYNC.")
    resuming = application_started(prior["applications"].get(sha256, {}))
    # Exact interrupted plans retain their immutable export hashes and still
    # undergo fresh account/set/address/full-zone/DNSSEC checks. Age alone must
    # not strand a known pending change, or encourage a no-op replacement plan.
    require(plan == build_plan(c, plan.get("snapshot", {}), plan.get("mode"), allow_stale=resuming, verification_evidence=plan.get("verification") if resuming else None), "Plan no longer matches its snapshot, complete exports or configuration.")
    current = capture(c, aws, probe)
    snap = plan["snapshot"]
    require(current["mapping"] == snap["mapping"] and current["delegation_set_id"] == snap["delegation_set_id"], "Physical nameserver identity/address drift detected.")
    s = state(c)
    journal = s["applications"].setdefault(sha256, {"zones": {}})
    previously_completed = bool(journal.get("completed_at"))
    # Preflight BOTH complete zones before writing either. A partial interrupted
    # run can resume only at its journaled batch boundary (or that batch's result).
    for domain in DOMAINS:
        require(current["zones"][domain]["dnssec"] == snap["zones"][domain]["dnssec"], "DNSSEC status/key drift detected; recapture and review.")
        item = plan["zones"][domain]
        j = journal["zones"].setdefault(domain, {"completed": 0, "changes": {}})
        expected = normalized(snap["zones"][domain]["records"])
        for batch in item["batches"][:j["completed"]]:
            expected = apply_values(expected, batch)
        allowed = [digest(expected)]
        if j["completed"] < len(item["batches"]) and str(j["completed"]) in j["changes"]:
            allowed.append(digest(apply_values(expected, item["batches"][j["completed"]])))
        require(digest(current["zones"][domain]["records"]) in allowed, "Full DNS zone drift detected. No unrelated record will be overwritten; reconcile and create a fresh plan.")
    save(c, s)
    for domain in DOMAINS:
        item, j = plan["zones"][domain], journal["zones"][domain]
        expected = normalized(snap["zones"][domain]["records"])
        for index, batch in enumerate(item["batches"]):
            after = apply_values(expected, batch)
            if index < j["completed"]:
                expected = after
                continue
            entry = j["changes"].setdefault(str(index), {"submitted": False})
            observed = records(aws, item["hosted_zone_id"])
            require(digest(observed) in {digest(expected), digest(after)}, "DNS changed during apply; stop and reconcile.")
            if not entry.get("change_id"):
                # Persist an intent before the network call. If the response was
                # lost, restate identical records only when the whole zone equals
                # this batch's result; never retry a conflicting outcome.
                submitted = copy.deepcopy(batch)
                if digest(observed) == digest(after):
                    for change in submitted:
                        change["Action"] = "UPSERT"
                entry["submitted"] = True
                save(c, s)
                response = aws.call("route53", "change-resource-record-sets", "--hosted-zone-id", item["hosted_zone_id"], "--change-batch", json.dumps({"Comment": c["deployment_id"] + " " + plan["mode"], "Changes": submitted}))
                change_id = response.get("ChangeInfo", {}).get("Id", "")
                require(isinstance(change_id, str) and re.fullmatch(r"(?:/change/)?[A-Z0-9]+", change_id), "Change response has no usable ID; retain the intent and reconcile.")
                entry["change_id"] = change_id
                save(c, s)
            wait_change(aws, entry["change_id"])
            require(records(aws, item["hosted_zone_id"]) == after, "Complete-zone read-back differs after INSYNC; retain old DNS and investigate.")
            j["completed"] = index + 1
            entry["insync_at"] = now()
            save(c, s)
            expected = after
        require(records(aws, item["hosted_zone_id"]) == item["after"], "Final complete-zone comparison failed.")
    journal["completed_at"] = now()
    journal["meaning"] = "AWS records prepared and INSYNC; registrar delegation, DNSSEC chain and website traffic are separate checks."
    if not previously_completed:
        s.pop("verified", None)
    save(c, s)
    return journal


def verify(c, aws, probe=None, only_domain=None):
    require(only_domain is None or only_domain in DOMAINS, "Unknown domain.")
    # A failed new observation must not leave an older success usable for TTL
    # stabilization, including failures before DNS queries can start.
    s = state(c)
    s.pop("verified", None)
    save(c, s)
    require(not any(application_started(j) and not j.get("completed_at") for j in s["applications"].values()), "An applied DNS plan is unfinished. Resume its exact saved plan and saved change IDs before issuing a readiness report.")
    snap = capture(c, aws, probe)
    s = state(c)
    applied = [j for j in s["applications"].values() if j.get("completed_at")]
    require(applied, "Apply and verify the complete record-preparation plan before registrar closeout.")
    domains = [only_domain] if only_domain else DOMAINS
    zones = {d: snap["zones"][d]["hosted_zone_id"] for d in domains}
    expected_ds = {}
    for domain in domains:
        security = snap["zones"][domain]["dnssec"]
        expected_ds[domain] = sorted(k["DSRecord"] for k in security.get("KeySigningKeys", []) if k.get("Status") == "ACTIVE" and security["Status"]["ServeSignature"] == "SIGNING" and k.get("DSRecord"))
        if security["Status"]["ServeSignature"] == "SIGNING":
            require(expected_ds[domain], "Signing is enabled without a usable active DS record.")
    report = verify_delegation(zones, snap["mapping"], expected_ds, probe=probe)
    report.update(kind="waithaka-branded-dns-verification", schema_version=1, scope="authoritative-parent-recursive", account_id=c["account_id"],
                  delegation_set_id=snap["delegation_set_id"], nameservers=list(NAMES), zones=zones, config_sha256=digest(c), snapshot_sha256=digest(snap))
    if report["ok"] and set(zones) == set(DOMAINS):
        s["verified"] = {"ok": True, "checked_at": report["checked_at"], "zones": zones, "report_sha256": digest(report),
                         "mapping_sha256": digest(snap["mapping"]), "dnssec_sha256": digest({d: snap["zones"][d]["dnssec"] for d in DOMAINS})}
        save(c, s)
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", required=True, type=Path)
    commands = parser.add_subparsers(dest="command", required=True)
    for name in ("provision-plan", "capture"):
        commands.add_parser(name).add_argument("--output", required=True, type=Path)
    for name in ("provision", "apply"):
        command = commands.add_parser(name)
        command.add_argument("--plan", required=True, type=Path)
        command.add_argument("--sha256", required=True)
    export = commands.add_parser("export")
    export.add_argument("--domain", choices=DOMAINS, required=True)
    export.add_argument("--zone-id", required=True)
    export.add_argument("--output", type=Path, required=True)
    plan = commands.add_parser("plan")
    plan.add_argument("--snapshot", type=Path, required=True)
    plan.add_argument("--mode", choices=("prepare", "stabilize"), default="prepare")
    plan.add_argument("--output", type=Path, required=True)
    verification = commands.add_parser("verify")
    verification.add_argument("--domain", choices=DOMAINS)
    verification.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    try:
        c = validate_config(read_json(private_file(args.config)))
        with StateLock(c):
            aws = Aws(c)
            if args.command == "provision-plan":
                result = provision_plan(c)
            elif args.command == "provision":
                result = provision(c, read_json(private_file(args.plan)), args.sha256, aws)
            elif args.command == "export":
                result = export_zone(c, aws, args.domain, identifier(args.zone_id, "zone"))
            elif args.command == "capture":
                result = capture(c, aws)
            elif args.command == "plan":
                result = build_plan(c, read_json(private_file(args.snapshot)), args.mode)
            elif args.command == "apply":
                result = apply_plan(c, read_json(private_file(args.plan)), args.sha256, aws)
            else:
                result = verify(c, aws, only_domain=args.domain)
            if hasattr(args, "output"):
                write_json(args.output, result)
                print("Saved private DNS evidence: " + str(args.output))
            if args.command in {"provision-plan", "plan"}:
                print("Reviewed content SHA-256: " + digest(result))
            elif args.command == "verify":
                print("Delegation observations: " + ("PASS" if result["ok"] else "FAIL; inspect the saved checks/errors"))
                return 0 if result["ok"] else 1
            else:
                print("Completed " + args.command + ". No registrar or DS changes were made.")
        return 0
    except (GuardError, DNSProbeError, OSError, KeyError, TypeError, ValueError) as error:
        print("DNS operation stopped: " + (str(error) if isinstance(error, (GuardError, DNSProbeError)) else "Input or local operation failed; inspect private evidence without exposing credentials."), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
