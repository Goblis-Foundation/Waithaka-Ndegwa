"""Read-only DNS checks for a reviewed Route 53 branded delegation.

Python 3.10+ standard library and an existing BIND ``dig`` executable only.
No AWS requests, DNS changes, package installation or DNSSEC signing occur here.
An ``ok`` report records agreement at the queried endpoints; it does not prove
cryptographic DNSSEC validation, registrar ownership or worldwide propagation.

Parsing/flag semantics: https://bind9.readthedocs.io/en/stable/manpages.html
Zone-cut authority: https://www.rfc-editor.org/rfc/rfc2181.html#section-6.1
"""
from __future__ import annotations

import datetime as dt
import ipaddress
import os
import re
import subprocess


RECURSIVE_RESOLVERS = ("1.1.1.1", "8.8.8.8")
_QUERY_TYPES = {"A", "AAAA", "NS", "SOA", "DS", "DNSKEY", "TXT", "CNAME", "MX", "CAA"}
_STATUSES = {"NOERROR", "FORMERR", "SERVFAIL", "NXDOMAIN", "NOTIMP", "REFUSED", "YXDOMAIN", "YXRRSET", "NXRRSET", "NOTAUTH", "NOTZONE", "BADVERS", "BADKEY", "BADTIME", "BADMODE", "BADNAME", "BADALG", "BADTRUNC", "BADCOOKIE"}
_LIMITATIONS = [
    "This report observes the selected authoritative servers, two parent nameserver endpoints and two recursive resolvers at one time; it does not establish worldwide propagation.",
    "DS records are compared with the supplied active target KSK records. No independent DNSSEC signature validation, registrar ownership or AWS account ownership is established.",
    "Direct authority probes use the mapped IPv4 endpoints. AAAA/glue agreement is checked, but IPv6 network reachability is not exercised.",
    "No DNS records or DNSSEC settings are changed. A failed or incomplete check must not authorize cutover.",
]


class DNSProbeError(RuntimeError):
    """A DNS query, response or required input could not be safely verified."""


def _fqdn(value, *, root=False, record=False):
    if not isinstance(value, str) or not value or value != value.strip():
        raise DNSProbeError("A nonempty DNS name without surrounding whitespace is required.")
    if value == "." and root:
        return value
    name = value[:-1] if value.endswith(".") else value
    if not name or len(name) > 253 or not name.isascii():
        raise DNSProbeError("A bounded ASCII DNS name is required.")
    labels = name.lower().split(".")
    for index, label in enumerate(labels):
        if record and index == 0 and label == "*":
            continue
        pattern = r"[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?" if record else r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?"
        if not re.fullmatch(pattern, label):
            raise DNSProbeError("The DNS name contains an invalid label.")
    return ".".join(labels) + "."


def _global_ip(value, version=None):
    if not isinstance(value, str) or value != value.strip() or "%" in value:
        raise DNSProbeError("A global literal IP address is required.")
    try:
        address = ipaddress.ip_address(value)
    except ValueError:
        raise DNSProbeError("A global literal IP address is required.") from None
    if version is not None and address.version != version:
        raise DNSProbeError("The address family does not match the DNS record type.")
    if not address.is_global or address.is_multicast or address.is_unspecified or address.is_loopback or address.is_link_local or address.is_reserved or getattr(address, "ipv4_mapped", None) is not None:
        raise DNSProbeError("Nameserver addresses must be globally routable unicast literals.")
    return str(address)


def _address_list(value, version, *, required=False):
    if not isinstance(value, list) or len(value) > 8 or (required and not value):
        raise DNSProbeError("Each nameserver needs a bounded explicit address list in the required family.")
    result = [_global_ip(item, version) for item in value]
    if len(set(result)) != len(result):
        raise DNSProbeError("Duplicate nameserver addresses are not allowed.")
    return sorted(result, key=lambda item: int(ipaddress.ip_address(item)))


def _ds(value):
    if not isinstance(value, str):
        raise DNSProbeError("A textual DS record is required.")
    fields = value.split()
    if len(fields) != 4 or not all(re.fullmatch(r"[0-9]+", item) for item in fields[:3]):
        raise DNSProbeError("The DS record must contain key tag, algorithm, digest type and digest.")
    key_tag, algorithm, digest_type = map(int, fields[:3])
    length = {1: 40, 2: 64, 4: 96}.get(digest_type)
    if not 0 <= key_tag <= 65535 or not 1 <= algorithm <= 255 or length is None or not re.fullmatch(r"[A-Fa-f0-9]{" + str(length) + r"}", fields[3]):
        raise DNSProbeError("The DS record fields or digest length are invalid.")
    return f"{key_tag} {algorithm} {digest_type} {fields[3].upper()}"


def _validate_response(response):
    if not isinstance(response, dict) or response.get("status") not in _STATUSES:
        raise DNSProbeError("DNS response status is missing or unknown.")
    flags = response.get("flags")
    if not isinstance(flags, list) or any(not isinstance(flag, str) or not re.fullmatch(r"[a-z]+", flag) for flag in flags) or "qr" not in flags or "tc" in flags:
        raise DNSProbeError("DNS response flags are missing, malformed or truncated.")
    result = {"status": response["status"], "flags": list(flags)}
    for section in ("answer", "authority", "additional"):
        records = response.get(section)
        if not isinstance(records, list) or len(records) > 256:
            raise DNSProbeError("DNS response sections are missing or oversized.")
        result[section] = []
        for record in records:
            if not isinstance(record, dict) or not isinstance(record.get("ttl"), int) or isinstance(record["ttl"], bool) or not 0 <= record["ttl"] <= 4294967295 or not isinstance(record.get("type"), str) or not re.fullmatch(r"[A-Z][A-Z0-9]*", record["type"]) or not isinstance(record.get("value"), str) or not record["value"] or len(record["value"]) > 32768:
                raise DNSProbeError("DNS response contains a malformed resource record.")
            result[section].append({"name": _fqdn(record.get("name"), root=True, record=True), "type": record["type"], "ttl": record["ttl"], "value": record["value"]})
    return result


def _parse_dig(output):
    """Parse sectioned, single-line BIND output; never infer AA from prose."""
    if not isinstance(output, str) or len(output) > 1048576:
        raise DNSProbeError("dig output is missing or oversized.")
    headers = re.findall(r"^;; ->>HEADER<<- opcode: (\w+), status: (\w+), id: \d+\s*$", output, re.M)
    flag_lines = re.findall(r"^;; flags: ([a-z ]*);\s*QUERY: (\d+), ANSWER: (\d+), AUTHORITY: (\d+), ADDITIONAL: (\d+)\s*$", output, re.M)
    if len(headers) != 1 or headers[0][0] != "QUERY" or len(flag_lines) != 1:
        raise DNSProbeError("dig did not return one complete QUERY response header.")
    if re.search(r"^;;.*(?:communications error|timed out|no servers could be reached|malformed message|connection refused)", output, re.M | re.I):
        raise DNSProbeError("dig reported a transport or malformed-response error.")
    raw_flags, qd, an, ns, ar = flag_lines[0]
    if int(qd) != 1:
        raise DNSProbeError("dig returned an unexpected question count.")
    result = {"status": headers[0][1], "flags": raw_flags.split(), "answer": [], "authority": [], "additional": []}
    section = None
    opt_count = 0
    for line in output.splitlines():
        heading = re.fullmatch(r";; (ANSWER|AUTHORITY|ADDITIONAL) SECTION:", line)
        if heading:
            section = heading[1].lower()
            continue
        if line == ";; OPT PSEUDOSECTION:":
            opt_count += 1
            section = None
            continue
        if not line.strip() or line.startswith(";"):
            continue
        if section is None:
            raise DNSProbeError("dig returned record data outside a recognized section.")
        match = re.fullmatch(r"(\S+)\s+(\d+)\s+IN\s+([A-Z][A-Z0-9]*)\s+(.+)", line)
        if not match:
            raise DNSProbeError("dig returned malformed, wrapped or non-IN record data.")
        result[section].append({"name": match[1], "ttl": int(match[2]), "type": match[3], "value": match[4].strip()})
    if len(result["answer"]) != int(an) or len(result["authority"]) != int(ns) or len(result["additional"]) + opt_count != int(ar) or opt_count > 1:
        raise DNSProbeError("dig section counts do not match the response header.")
    return _validate_response(result)


def _no_alias(response, name):
    if any(record["type"] in {"CNAME", "DNAME"} for record in response["answer"]):
        raise DNSProbeError(f"{name} must be a direct DNS owner, not an alias.")


def _values(response, section, name, rrtype, *, exact_owner=False):
    records = [record for record in response[section] if record["type"] == rrtype]
    if exact_owner and any(record["name"] != name for record in records):
        raise DNSProbeError("The response answers for a different DNS owner.")
    return [record["value"] for record in records if record["name"] == name]


def _soa(response, name, section="answer"):
    values = _values(response, section, name, "SOA", exact_owner=True)
    if len(values) != 1:
        raise DNSProbeError(f"Expected exactly one SOA for {name}.")
    fields = values[0].split()
    if len(fields) != 7 or not all(re.fullmatch(r"[0-9]+", value) and int(value) <= 4294967295 for value in fields[2:]):
        raise DNSProbeError("The SOA record is malformed.")
    return {"mname": _fqdn(fields[0]), "rname": _fqdn(fields[1], record=True), "serial": int(fields[2]), "timers": [int(value) for value in fields[3:]]}


def _is_below(name, zone):
    return name != zone and (zone == "." or name.endswith("." + zone))


class DigProbe:
    """Bounded subprocess queries. Explicit authorities are queried without RD.

    ``server=None`` uses the system recursive resolver. The two fixed independent
    resolver IPs use RD; every other explicit server must be a global IP literal
    and is queried with ``+norecurse``. ``-r`` disables user dig configuration.
    """

    def __init__(self, executable="dig", timeout=12):
        if not isinstance(executable, str) or not executable or not isinstance(timeout, (int, float)) or isinstance(timeout, bool) or not 1 <= timeout <= 30:
            raise DNSProbeError("An existing dig executable and bounded timeout are required.")
        self.executable = executable
        self.timeout = timeout

    def query(self, name, rrtype, server=None):
        name = _fqdn(name, root=True, record=True)
        if not isinstance(rrtype, str) or rrtype.upper() not in _QUERY_TYPES:
            raise DNSProbeError("Unsupported DNS query type.")
        rrtype = rrtype.upper()
        if server is not None:
            server = _global_ip(server)
        arguments = [self.executable, "-r"]
        if server is not None:
            arguments.append("@" + server)
        arguments += [name, rrtype, "+time=3", "+tries=1", "+nosearch", "+nocookie", "+dnssec", "+nocdflag", "+noshort", "+noall", "+comments", "+answer", "+authority", "+additional", "+nomultiline", "+nosplit", "+ttlid", "+nottlunits", "+cl", "+norrcomments", "+recurse" if server is None or server in RECURSIVE_RESOLVERS else "+norecurse"]
        try:
            process = subprocess.run(arguments, capture_output=True, text=True, timeout=self.timeout, check=False, env={**os.environ, "LC_ALL": "C"})
        except FileNotFoundError:
            raise DNSProbeError("dig is not installed or the configured executable is unavailable; no installation was attempted.") from None
        except subprocess.TimeoutExpired:
            raise DNSProbeError(f"DNS query timed out for {name} {rrtype}.") from None
        except (OSError, UnicodeError):
            raise DNSProbeError("dig could not be executed or its response could not be decoded.") from None
        if process.returncode != 0:
            raise DNSProbeError(f"dig failed for {name} {rrtype} (exit {process.returncode}); response is unverified.")
        if process.stderr.strip():
            raise DNSProbeError("dig reported a diagnostic on stderr; response is unverified.")
        return _parse_dig(process.stdout)

    def addresses(self, name):
        """Return only direct, exact-owner, global A/AAAA answers, never aliases."""
        name = _fqdn(name)
        result = {}
        for rrtype, key, version in (("A", "ipv4", 4), ("AAAA", "ipv6", 6)):
            response = _validate_response(self.query(name, rrtype))
            if response["status"] != "NOERROR":
                raise DNSProbeError(f"Address lookup for {name} {rrtype} returned {response['status']}.")
            _no_alias(response, name)
            values = _values(response, "answer", name, rrtype, exact_owner=True)
            result[key] = sorted({_global_ip(value, version) for value in values}, key=lambda item: int(ipaddress.ip_address(item)))
            if not values:
                # A negative address answer needs a zone SOA, not an empty reply.
                owners = {record["name"] for record in response["authority"] if record["type"] == "SOA"}
                if len(owners) != 1 or not any(owner == name or _is_below(name, owner) for owner in owners):
                    raise DNSProbeError("An empty address answer lacks an enclosing-zone SOA.")
                _soa(response, next(iter(owners)), "authority")
        if not result["ipv4"] and not result["ipv6"]:
            raise DNSProbeError(f"No direct nameserver addresses were returned for {name}.")
        return result


class _Verifier:
    def __init__(self, probe):
        self.probe = probe
        self.report = {"ok": False, "checked_at": dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), "checks": [], "errors": [], "observations": {"zones": {}, "mapping": [], "expected_ds": {}, "parents": {}, "queries": [], "limitations": list(_LIMITATIONS)}}

    def check(self, category, context, expected, action):
        identifier = category + "|" + "|".join(f"{key}={value}" for key, value in sorted(context.items()))
        try:
            observed = action()
            ok = observed == expected
            detail = None if ok else "observed DNS does not match the reviewed target"
        except DNSProbeError as error:
            observed, ok, detail = None, False, str(error)
        except Exception as error:
            observed, ok, detail = None, False, "Unexpected probe failure (" + type(error).__name__ + "); check is unverified"
        self.report["checks"].append({"id": identifier, "ok": ok, "context": dict(context), "expected": expected, "observed": observed})
        if not ok:
            self.report["errors"].append(identifier + ": " + detail)
        return ok

    def query(self, name, rrtype, server=None, *, authoritative=False, recursive=False):
        try:
            response = _validate_response(self.probe.query(name, rrtype, server=server))
        except Exception as error:
            self.report["observations"]["queries"].append({"name": name, "type": rrtype, "server": server, "error": str(error) if isinstance(error, DNSProbeError) else type(error).__name__})
            raise
        self.report["observations"]["queries"].append({"name": name, "type": rrtype, "server": server, **response})
        if response["status"] != "NOERROR":
            raise DNSProbeError(f"{name} {rrtype} returned {response['status']} from {server}.")
        if authoritative and "aa" not in response["flags"]:
            raise DNSProbeError("The selected server did not return an authoritative AA answer.")
        if recursive and ("ra" not in response["flags"] or "cd" in response["flags"]):
            raise DNSProbeError("The selected resolver did not provide an ordinary checking-enabled recursive response.")
        _no_alias(response, name)
        return response

    def ns(self, zone, server, *, authoritative=False, recursive=False):
        response = self.query(zone, "NS", server, authoritative=authoritative, recursive=recursive)
        return sorted({_fqdn(value) for value in _values(response, "answer", zone, "NS", exact_owner=True)})

    def soa_mname(self, zone, server, *, authoritative=False, recursive=False):
        return _soa(self.query(zone, "SOA", server, authoritative=authoritative, recursive=recursive), zone)["mname"]

    def host_addresses(self, name, rrtype, server):
        response = self.query(name, rrtype, server, authoritative=True)
        return sorted({_global_ip(value, 4 if rrtype == "A" else 6) for value in _values(response, "answer", name, rrtype, exact_owner=True)}, key=lambda item: int(ipaddress.ip_address(item)))

    def parent(self, zone):
        labels = zone.rstrip(".").split(".")
        ancestors = [".".join(labels[index:]) + "." for index in range(1, len(labels))] + ["."]
        for candidate in ancestors:
            response = self.query(candidate, "NS", RECURSIVE_RESOLVERS[0], recursive=True)
            values = _values(response, "answer", candidate, "NS", exact_owner=True)
            if not values:
                owners = {record["name"] for record in response["authority"] if record["type"] == "SOA"}
                if len(owners) != 1 or not all(_is_below(candidate, owner) for owner in owners):
                    raise DNSProbeError("An ancestor without an NS answer lacks an enclosing-zone negative SOA.")
                _soa(response, next(iter(owners)), "authority")
                continue
            names = sorted({_fqdn(value) for value in values})
            if not 2 <= len(names) <= 32:
                raise DNSProbeError("At least two bounded, distinct parent nameservers are required.")
            servers = []
            used = set()
            for name in names:
                addresses = self.probe.addresses(name)
                ipv4 = _address_list(addresses.get("ipv4"), 4)
                _address_list(addresses.get("ipv6"), 6)
                choices = [value for value in ipv4 if value not in used]
                if choices:
                    servers.append({"name": name, "ipv4": choices[0]})
                    used.add(choices[0])
                if len(servers) == 2:
                    result = {"name": candidate, "servers": servers}
                    self.report["observations"]["parents"][zone] = result
                    return True
            raise DNSProbeError("Two distinct parent nameservers with distinct IPv4 endpoints could not be found.")
        raise DNSProbeError("No exact ancestor NS answer established the actual parent zone.")

    def parent_authority(self, parent, server):
        _soa(self.query(parent, "SOA", server, authoritative=True), parent)
        return True

    def delegation(self, zone, server):
        response = self.query(zone, "NS", server)
        if "aa" in response["flags"]:
            raise DNSProbeError("The parent query answered as the child authority instead of returning a parent delegation referral.")
        if response["answer"]:
            raise DNSProbeError("A cached or direct child answer cannot substitute for a parent delegation referral.")
        values = _values(response, "authority", zone, "NS", exact_owner=True)
        return response, sorted({_fqdn(value) for value in values})

    def ds(self, zone, parent, server, *, recursive=False):
        response = self.query(zone, "DS", server, authoritative=not recursive, recursive=recursive)
        values = _values(response, "answer", zone, "DS", exact_owner=True)
        if not values:
            _soa(response, parent, "authority")
        return sorted({_ds(value) for value in values})

    def finish(self):
        checks = self.report["checks"]
        self.report["ok"] = bool(checks) and not self.report["errors"] and all(item["ok"] is True for item in checks)
        self.report["checked_at"] = dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        return self.report


def _inputs(zones, mapping, expected_ds):
    if not isinstance(zones, dict) or not 1 <= len(zones) <= 8 or not isinstance(mapping, list) or len(mapping) != 4 or not isinstance(expected_ds, dict):
        raise DNSProbeError("Provide explicit selected zones, exactly four nameservers and expected DS records for every selected zone.")
    normalized_zones = {}
    for name, zone_id in zones.items():
        name = _fqdn(name)
        if name.count(".") < 2 or name in normalized_zones or not isinstance(zone_id, str) or not re.fullmatch(r"(?:/hostedzone/)?Z[A-Z0-9]{1,64}", zone_id):
            raise DNSProbeError("Selected zone names and hosted-zone IDs must be explicit, unique and well formed.")
        normalized_zones[name] = zone_id
    normalized_mapping = []
    for item in mapping:
        if not isinstance(item, dict):
            raise DNSProbeError("Each nameserver mapping must be an object.")
        normalized_mapping.append({"name": _fqdn(item.get("name")), "aws_name": _fqdn(item.get("aws_name")), "ipv4": _address_list(item.get("ipv4"), 4, required=True), "ipv6": _address_list(item.get("ipv6"), 6, required=True)})
    normalized_mapping.sort(key=lambda item: item["name"])
    base = normalized_mapping[0]["name"].split(".", 1)[1]
    if not base.endswith(".com.") or {item["name"] for item in normalized_mapping} != {f"ns{index}." + base for index in range(1, 5)} or len({item["aws_name"] for item in normalized_mapping}) != 4:
        raise DNSProbeError("The mapping must contain ns1 through ns4 beneath one .com domain and four distinct assigned AWS names.")
    for key in ("ipv4", "ipv6"):
        values = [value for item in normalized_mapping for value in item[key]]
        if len(set(values)) != len(values):
            raise DNSProbeError("The four assigned nameservers must have distinct addresses.")
    if any(item["name"] == item["aws_name"] for item in normalized_mapping):
        raise DNSProbeError("The original assigned nameserver names must be distinct from the branded names.")
    supplied_ds = {}
    for name, records in expected_ds.items():
        name = _fqdn(name)
        if name in supplied_ds or not isinstance(records, list) or len(records) > 8:
            raise DNSProbeError("Expected DS record lists must be explicit and bounded.")
        supplied_ds[name] = sorted({_ds(value) for value in records})
    if any(name not in supplied_ds for name in normalized_zones):
        raise DNSProbeError("Missing expected DS configuration must not be interpreted as an unsigned zone.")
    return normalized_zones, normalized_mapping, {name: supplied_ds[name] for name in normalized_zones}, base


def verify_delegation(zones, mapping, expected_ds, probe=None):
    """Check a selected zone subset against one exact reviewed branded mapping.

    Both address families are required. ``expected_ds[domain] = []`` explicitly
    expects unsigned delegation; missing configuration is an error. Extra DS
    entries allow a caller to select one zone from a fully reviewed plan.
    """
    verifier = _Verifier(probe if probe is not None else DigProbe())
    normalized = None

    def validate_inputs():
        nonlocal normalized
        normalized = _inputs(zones, mapping, expected_ds)
        return True

    if not verifier.check("inputs", {}, True, validate_inputs):
        return verifier.finish()
    zones, mapping, expected_ds, vanity_zone = normalized
    observations = verifier.report["observations"]
    observations.update(zones=zones, mapping=mapping, expected_ds=expected_ds)
    desired = sorted(item["name"] for item in mapping)
    mname = "ns1." + vanity_zone
    for item in mapping:
        def assigned_addresses(item=item):
            value = verifier.probe.addresses(item["aws_name"])
            return {"ipv4": _address_list(value.get("ipv4"), 4, required=True), "ipv6": _address_list(value.get("ipv6"), 6, required=True)}
        verifier.check("aws-addresses", {"name": item["name"], "aws_name": item["aws_name"]}, {"ipv4": item["ipv4"], "ipv6": item["ipv6"]}, assigned_addresses)
    if verifier.report["errors"]:
        return verifier.finish()
    servers = [value for item in mapping for value in item["ipv4"]]
    for zone in zones:
        for server in servers:
            verifier.check("authoritative-ns", {"zone": zone, "server": server}, desired, lambda z=zone, s=server: verifier.ns(z, s, authoritative=True))
            verifier.check("authoritative-soa", {"zone": zone, "server": server}, mname, lambda z=zone, s=server: verifier.soa_mname(z, s, authoritative=True))
    # Own-host records always belong to the .com zone, including a co.ke-only
    # sequential registrar verification. All four authorities must agree.
    for server in servers:
        for item in mapping:
            for rrtype, family in (("A", "ipv4"), ("AAAA", "ipv6")):
                verifier.check("authoritative-address", {"zone": vanity_zone, "name": item["name"], "server": server, "type": rrtype}, item[family], lambda n=item["name"], t=rrtype, s=server: verifier.host_addresses(n, t, s))
    for zone in zones:
        if not verifier.check("parent-discovery", {"zone": zone}, True, lambda z=zone: verifier.parent(z)):
            continue
        parent = observations["parents"][zone]
        for endpoint in parent["servers"]:
            server = endpoint["ipv4"]
            context = {"zone": zone, "parent": parent["name"], "server": server}
            verifier.check("parent-authority", context, True, lambda p=parent["name"], s=server: verifier.parent_authority(p, s))
            delegation_response = None

            def read_delegation(z=zone, s=server):
                nonlocal delegation_response
                delegation_response, values = verifier.delegation(z, s)
                return values

            delegation_ok = verifier.check("parent-ns", context, desired, read_delegation)
            for item in mapping:
                if not _is_below(item["name"], zone):
                    continue
                for rrtype, family in (("A", "ipv4"), ("AAAA", "ipv6")):
                    def glue(n=item["name"], t=rrtype):
                        if delegation_response is None or not delegation_ok:
                            raise DNSProbeError("Parent delegation was not established; glue is unverified.")
                        if any(record["name"] == n and record["type"] in {"CNAME", "DNAME"} for record in delegation_response["additional"]):
                            raise DNSProbeError("In-bailiwick nameserver glue must not be an alias.")
                        return sorted({_global_ip(value, 4 if t == "A" else 6) for value in _values(delegation_response, "additional", n, t)}, key=lambda value: int(ipaddress.ip_address(value)))
                    verifier.check("parent-glue", {**context, "name": item["name"], "type": rrtype}, item[family], glue)
            verifier.check("parent-ds", context, expected_ds[zone], lambda z=zone, p=parent["name"], s=server: verifier.ds(z, p, s))
        for server in RECURSIVE_RESOLVERS:
            context = {"zone": zone, "server": server}
            verifier.check("recursive-ns", context, desired, lambda z=zone, s=server: verifier.ns(z, s, recursive=True))
            verifier.check("recursive-soa", context, mname, lambda z=zone, s=server: verifier.soa_mname(z, s, recursive=True))
            verifier.check("recursive-ds", context, expected_ds[zone], lambda z=zone, p=parent["name"], s=server: verifier.ds(z, p, s, recursive=True))
    return verifier.finish()
