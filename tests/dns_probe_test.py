#!/usr/bin/env python3
"""Offline DNS verification regressions. No subprocess or DNS request escapes."""
import collections
import copy
import importlib.util
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("branded_dns_probe", ROOT / "deploy/aws/dns_probe.py")
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

COM = "waithakandegwa.com."
KE = "waithakandegwa.co.ke."
ZONES = {COM[:-1]: "ZCOMFIXTURE", KE[:-1]: "ZKEFIXTURE"}
MAPPING = [
    {"name": f"ns{index}." + COM, "aws_name": f"ns-{index}.awsdns-0{index}.net.", "ipv4": [f"205.251.{191 + index}.1"], "ipv6": [f"2600:9000:530{index}::1"]}
    for index in range(1, 5)
]
DS = {COM[:-1]: ["12345 13 2 " + "A" * 64], KE[:-1]: []}
PARENT_NAMES = {"com.": ["a.gtld-servers.net.", "b.gtld-servers.net."], "ke.": ["a.fixture.kenic.or.ke.", "b.fixture.kenic.or.ke."]}
PARENT_IPS = {"a.gtld-servers.net.": "192.5.6.30", "b.gtld-servers.net.": "192.33.14.30", "a.fixture.kenic.or.ke.": "185.38.108.108", "b.fixture.kenic.or.ke.": "204.61.216.7"}


def rr(name, kind, value, ttl=300):
    return {"name": name, "type": kind, "ttl": ttl, "value": value}


def soa(name, primary=None):
    return rr(name, "SOA", f"{primary or 'ns1.' + COM} hostmaster.{name} 2026102101 3600 600 1209600 300")


def response(answer=(), authority=(), additional=(), flags=("qr", "aa"), status="NOERROR"):
    return {"status": status, "flags": list(flags), "answer": list(answer), "authority": list(authority), "additional": list(additional)}


def dig_output(value, opt=True):
    result = [
        f";; ->>HEADER<<- opcode: QUERY, status: {value['status']}, id: 4172",
        f";; flags: {' '.join(value['flags'])}; QUERY: 1, ANSWER: {len(value['answer'])}, AUTHORITY: {len(value['authority'])}, ADDITIONAL: {len(value['additional']) + int(opt)}",
    ]
    if opt:
        result += ["", ";; OPT PSEUDOSECTION:", "; EDNS: version: 0, flags: do; udp: 1232"]
    for section in ("answer", "authority", "additional"):
        if value[section]:
            result += ["", ";; " + section.upper() + " SECTION:"]
            for record in value[section]:
                result.append(f"{record['name']}\t{record['ttl']}\tIN\t{record['type']}\t{record['value']}")
    return "\n".join(result) + "\n"


class FixtureProbe(m.DigProbe):
    """Use the real address validation with deterministic DNS reply fixtures."""

    def __init__(self, ds=None):
        super().__init__()
        self.mapping = copy.deepcopy(MAPPING)
        self.ds = {m._fqdn(name): value for name, value in (DS if ds is None else ds).items()}
        self.overrides = {}
        self.calls = []

    def query(self, name, rrtype, server=None):
        name, rrtype = m._fqdn(name, root=True, record=True), rrtype.upper()
        key = (server, name, rrtype)
        self.calls.append(key)
        if key in self.overrides:
            value = self.overrides[key]
            if isinstance(value, Exception):
                raise value
            return copy.deepcopy(value)
        return copy.deepcopy(self.normal(name, rrtype, server))

    def normal(self, name, rrtype, server):
        ns_records = lambda owner: [rr(owner, "NS", item["name"]) for item in self.mapping]
        assigned = {ip for item in self.mapping for ip in item["ipv4"]}
        if server is None:
            for item in self.mapping:
                if name == item["aws_name"] and rrtype in {"A", "AAAA"}:
                    family = "ipv4" if rrtype == "A" else "ipv6"
                    return response([rr(name, rrtype, value) for value in item[family]], flags=("qr", "rd", "ra"))
            if name in PARENT_IPS and rrtype == "A":
                return response([rr(name, "A", PARENT_IPS[name])], flags=("qr", "rd", "ra"))
            if name in PARENT_IPS and rrtype == "AAAA":
                owner = "gtld-servers.net." if name.endswith("gtld-servers.net.") else "kenic.or.ke."
                return response(authority=[soa(owner, "ns1." + owner)], flags=("qr", "rd", "ra"))
        if server in assigned:
            if name in {COM, KE} and rrtype == "NS":
                return response(ns_records(name))
            if name in {COM, KE} and rrtype == "SOA":
                return response([soa(name)])
            for item in self.mapping:
                if name == item["name"] and rrtype in {"A", "AAAA"}:
                    family = "ipv4" if rrtype == "A" else "ipv6"
                    return response([rr(name, rrtype, value) for value in item[family]])
        if server in m.RECURSIVE_RESOLVERS:
            flags = ("qr", "rd", "ra")
            if name in PARENT_NAMES and rrtype == "NS":
                return response([rr(name, "NS", value) for value in PARENT_NAMES[name]], flags=flags)
            if name == "co.ke." and rrtype == "NS":
                return response(authority=[soa("ke.", "mzizi.kenic.or.ke.")], flags=flags)
            if name in {COM, KE}:
                if rrtype == "NS":
                    return response(ns_records(name), flags=flags)
                if rrtype == "SOA":
                    return response([soa(name)], flags=flags)
                if rrtype == "DS":
                    parent = "com." if name == COM else "ke."
                    return response([rr(name, "DS", value) for value in self.ds[name]], authority=[] if self.ds[name] else [soa(parent, "a." + parent)], flags=flags)
        for parent, names in PARENT_NAMES.items():
            if server in {PARENT_IPS[value] for value in names}:
                child = COM if parent == "com." else KE
                if name == parent and rrtype == "SOA":
                    return response([soa(parent, names[0])])
                if name == child and rrtype == "NS":
                    glue = [rr(item["name"], typ, value) for item in self.mapping for typ, family in (("A", "ipv4"), ("AAAA", "ipv6")) for value in item[family]] if child == COM else []
                    return response(authority=ns_records(child), additional=glue, flags=("qr",))
                if name == child and rrtype == "DS":
                    return response([rr(child, "DS", value) for value in self.ds[child]], authority=[] if self.ds[child] else [soa(parent, names[0])])
        raise AssertionError("Unexpected offline fixture query: " + repr((server, name, rrtype)))


class DigParserTests(unittest.TestCase):
    def run_query(self, output, **kwargs):
        process = subprocess.CompletedProcess([], 0, output, "")
        with patch.object(m.subprocess, "run", return_value=process) as run:
            value = m.DigProbe().query(COM, "NS", **kwargs)
        return value, run.call_args

    def test_sections_status_flags_and_fqdn_are_parsed(self):
        value = response([rr(COM.upper(), "NS", MAPPING[0]["name"])], [soa("com.")], [rr(MAPPING[0]["name"], "A", MAPPING[0]["ipv4"][0])])
        actual, _ = self.run_query(dig_output(value), server=MAPPING[0]["ipv4"][0])
        self.assertEqual(actual["status"], "NOERROR")
        self.assertEqual(actual["flags"], ["qr", "aa"])
        self.assertEqual(actual["answer"][0]["name"], COM)
        self.assertEqual(actual["authority"][0]["type"], "SOA")
        self.assertIsInstance(actual["additional"][0]["ttl"], int)

    def test_negative_response_and_servfail_remain_statuses(self):
        for status in ("NXDOMAIN", "SERVFAIL", "REFUSED"):
            with self.subTest(status=status):
                actual, _ = self.run_query(dig_output(response(status=status, flags=("qr", "rd", "ra"))))
                self.assertEqual(actual["status"], status)

    def test_authoritative_flag_is_not_inferred_from_record_text(self):
        actual, _ = self.run_query(dig_output(response([rr(COM, "NS", "aa.example.net.")], flags=("qr", "rd", "ra"))))
        self.assertNotIn("aa", actual["flags"])

    def test_explicit_authority_uses_norecurse_and_ignores_digrc(self):
        _, call = self.run_query(dig_output(response()), server="192.5.6.30")
        argv = call.args[0]
        self.assertEqual(argv[:3], ["dig", "-r", "@192.5.6.30"])
        self.assertIn("+norecurse", argv)
        self.assertIn("+dnssec", argv)
        self.assertIn("+nocdflag", argv)
        self.assertIn("+nosplit", argv)
        self.assertNotIn("shell", call.kwargs)
        self.assertLessEqual(call.kwargs["timeout"], 30)

    def test_system_and_independent_resolvers_use_recursion(self):
        for server in (None, *m.RECURSIVE_RESOLVERS):
            with self.subTest(server=server):
                _, call = self.run_query(dig_output(response()), server=server)
                self.assertIn("+recurse", call.args[0])
                self.assertNotIn("+norecurse", call.args[0])

    def test_missing_unknown_duplicate_headers_fail(self):
        valid = dig_output(response())
        for output in ("", ";; no answer", valid.replace("NOERROR", "MYSTERY"), valid + valid, valid.replace("opcode: QUERY", "opcode: UPDATE")):
            with self.subTest(output=output[:70]), self.assertRaises(m.DNSProbeError):
                self.run_query(output)

    def test_missing_flags_truncation_and_count_mismatch_fail(self):
        valid = dig_output(response([rr(COM, "NS", MAPPING[0]["name"])]))
        for output in (valid.replace("qr aa", "aa"), valid.replace("qr aa", "qr aa tc"), valid.replace("ANSWER: 1", "ANSWER: 2"), valid.replace("IN\tNS", "CH\tNS"), valid.replace("300\tIN", "1h\tIN")):
            with self.subTest(output=output[:70]), self.assertRaises(m.DNSProbeError):
                self.run_query(output)

    def test_transport_diagnostics_do_not_become_success(self):
        with self.assertRaises(m.DNSProbeError):
            self.run_query(";; communications error to 192.5.6.30#53: timed out\n" + dig_output(response()))

    def test_long_ds_stays_one_record(self):
        value = response([rr(COM, "DS", DS[COM[:-1]][0])])
        actual, _ = self.run_query(dig_output(value))
        self.assertEqual(actual["answer"][0]["value"], DS[COM[:-1]][0])

    def test_subprocess_failures_and_stderr_are_closed(self):
        for side_effect in (FileNotFoundError(), OSError(), subprocess.TimeoutExpired(["dig"], 12)):
            with self.subTest(error=type(side_effect).__name__), patch.object(m.subprocess, "run", side_effect=side_effect), self.assertRaises(m.DNSProbeError):
                m.DigProbe().query(COM, "NS")
        for process in (subprocess.CompletedProcess([], 9, "", "private fixture diagnostic"), subprocess.CompletedProcess([], 0, dig_output(response()), "warning")):
            with patch.object(m.subprocess, "run", return_value=process), self.assertRaises(m.DNSProbeError) as raised:
                m.DigProbe().query(COM, "NS")
            self.assertNotIn("private fixture diagnostic", str(raised.exception))

    def test_invalid_query_or_server_never_starts_a_process(self):
        for name, typ, server in (("example.com;id", "NS", None), ("+trace", "NS", None), (COM, "AXFR", None), (COM, "NS", "127.0.0.1"), (COM, "NS", "169.254.169.254"), (COM, "NS", "ns.example.com")):
            with self.subTest(name=name, typ=typ, server=server), patch.object(m.subprocess, "run") as run, self.assertRaises(m.DNSProbeError):
                m.DigProbe().query(name, typ, server)
            run.assert_not_called()


class AddressTests(unittest.TestCase):
    def test_exact_global_a_and_aaaa_are_returned(self):
        probe = FixtureProbe()
        self.assertEqual(probe.addresses(MAPPING[0]["aws_name"]), {"ipv4": MAPPING[0]["ipv4"], "ipv6": MAPPING[0]["ipv6"]})

    def test_cname_is_never_used_as_nameserver(self):
        probe = FixtureProbe()
        name = MAPPING[0]["aws_name"]
        probe.overrides[(None, name, "A")] = response([rr(name, "CNAME", "elsewhere.example.com."), rr("elsewhere.example.com.", "A", "205.251.192.1")])
        with self.assertRaises(m.DNSProbeError):
            probe.addresses(name)

    def test_unrelated_owner_address_is_rejected(self):
        probe = FixtureProbe()
        probe.overrides[(None, MAPPING[0]["aws_name"], "A")] = response([rr("elsewhere.example.com.", "A", "205.251.192.1")])
        with self.assertRaises(m.DNSProbeError):
            probe.addresses(MAPPING[0]["aws_name"])

    def test_non_global_multicast_reserved_and_wrong_family_fail(self):
        for value in ("127.0.0.1", "10.0.0.1", "192.0.2.1", "169.254.169.254", "224.0.0.1", "0.0.0.0", "2600:9000:5301::1", "8.8.8.8 extra"):
            probe = FixtureProbe()
            probe.overrides[(None, MAPPING[0]["aws_name"], "A")] = response([rr(MAPPING[0]["aws_name"], "A", value)])
            with self.subTest(value=value), self.assertRaises(m.DNSProbeError):
                probe.addresses(MAPPING[0]["aws_name"])

    def test_empty_family_needs_enclosing_soa(self):
        probe = FixtureProbe()
        name = MAPPING[0]["aws_name"]
        probe.overrides[(None, name, "AAAA")] = response()
        with self.assertRaises(m.DNSProbeError):
            probe.addresses(name)
        probe.overrides[(None, name, "AAAA")] = response(authority=[soa("awsdns-01.net.")])
        self.assertEqual(probe.addresses(name)["ipv6"], [])

    def test_negative_or_timeout_family_is_not_silently_ignored(self):
        for value in (response(status="NXDOMAIN"), response(status="SERVFAIL"), m.DNSProbeError("fixture timeout")):
            probe = FixtureProbe()
            probe.overrides[(None, MAPPING[0]["aws_name"], "AAAA")] = value
            with self.subTest(value=str(value)), self.assertRaises(m.DNSProbeError):
                probe.addresses(MAPPING[0]["aws_name"])


class DelegationTests(unittest.TestCase):
    def verify(self, probe=None, zones=None, mapping=None, ds=None):
        return m.verify_delegation(ZONES if zones is None else zones, MAPPING if mapping is None else mapping, DS if ds is None else ds, probe=probe or FixtureProbe(ds))

    def failed(self, report, category):
        self.assertFalse(report["ok"])
        self.assertTrue(report["errors"])
        self.assertTrue(any(not check["ok"] and check["id"].startswith(category + "|") for check in report["checks"]), report["errors"])

    def test_complete_report_covers_both_zones_and_all_endpoints(self):
        probe = FixtureProbe()
        result = self.verify(probe)
        self.assertTrue(result["ok"], result["errors"])
        self.assertEqual(result["errors"], [])
        self.assertEqual(set(result["observations"]["zones"]), {COM, KE})
        self.assertRegex(result["checked_at"], r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$")
        self.assertEqual(len({value["id"] for value in result["checks"]}), len(result["checks"]))
        counts = collections.Counter(value["id"].split("|")[0] for value in result["checks"])
        self.assertEqual(dict(counts), {"inputs": 1, "aws-addresses": 4, "authoritative-ns": 8, "authoritative-soa": 8, "authoritative-address": 32, "parent-discovery": 2, "parent-authority": 4, "parent-ns": 4, "parent-glue": 16, "parent-ds": 4, "recursive-ns": 4, "recursive-soa": 4, "recursive-ds": 4})
        self.assertEqual(result["observations"]["parents"][KE]["name"], "ke.")
        self.assertIn(("1.1.1.1", "co.ke.", "NS"), probe.calls)
        self.assertIn(("1.1.1.1", "ke.", "NS"), probe.calls)
        self.assertTrue(any("cryptographic" in value or "signature validation" in value for value in result["observations"]["limitations"]))

    def test_each_zone_can_be_checked_sequentially(self):
        for zone in ZONES:
            probe = FixtureProbe()
            result = self.verify(probe, zones={zone: ZONES[zone]})
            with self.subTest(zone=zone):
                self.assertTrue(result["ok"], result["errors"])
                self.assertEqual(set(result["observations"]["zones"]), {zone + "."})
                self.assertEqual(sum(value["id"].startswith("authoritative-address|") for value in result["checks"]), 32)

    def test_out_of_bailiwick_co_ke_does_not_require_glue(self):
        result = self.verify(zones={KE[:-1]: ZONES[KE[:-1]]})
        self.assertTrue(result["ok"], result["errors"])
        self.assertFalse(any(value["id"].startswith("parent-glue|") for value in result["checks"]))

    def test_no_queries_for_invalid_or_missing_inputs(self):
        variants = [([], MAPPING, DS), (ZONES, MAPPING[:3], DS), (ZONES, MAPPING, {COM[:-1]: DS[COM[:-1]]})]
        for zones, mapping, ds in variants:
            probe = FixtureProbe()
            with self.subTest(zones=zones, mapping_length=len(mapping)):
                self.failed(self.verify(probe, zones=zones, mapping=mapping, ds=ds), "inputs")
                self.assertEqual(probe.calls, [])

    def test_ipv6_is_mandatory_and_alias_names_are_not_the_mapping(self):
        for change in ({"ipv6": []}, {"ipv4": ["127.0.0.1"]}, {"name": "ns5." + COM}, {"aws_name": MAPPING[0]["name"]}):
            mapping = copy.deepcopy(MAPPING)
            mapping[0].update(change)
            with self.subTest(change=change):
                self.failed(self.verify(mapping=mapping), "inputs")

    def test_duplicate_server_addresses_fail_before_queries(self):
        mapping = copy.deepcopy(MAPPING)
        mapping[1]["ipv4"] = mapping[0]["ipv4"]
        self.failed(self.verify(mapping=mapping), "inputs")

    def test_malformed_ds_cannot_mean_unsigned(self):
        for value in (None, "12345 13 2 " + "A" * 64, ["12345 13 2 ABC"], ["70000 13 2 " + "A" * 64]):
            with self.subTest(value=value):
                self.failed(self.verify(ds={COM[:-1]: value, KE[:-1]: []}), "inputs")

    def test_changed_assigned_ipv4_or_ipv6_fails_binding(self):
        for rrtype, value in (("A", "205.251.199.9"), ("AAAA", "2600:9000:5309::9")):
            probe = FixtureProbe()
            probe.overrides[(None, MAPPING[0]["aws_name"], rrtype)] = response([rr(MAPPING[0]["aws_name"], rrtype, value)])
            with self.subTest(rrtype=rrtype):
                self.failed(self.verify(probe), "aws-addresses")
                self.assertFalse(any(server in {item["ipv4"][0] for item in MAPPING} for server, _, _ in probe.calls))

    def test_one_non_authoritative_or_wrong_owner_apex_fails(self):
        for value in (response([rr(COM, "NS", item["name"]) for item in MAPPING], flags=("qr", "ra")), response([rr("elsewhere.com.", "NS", item["name"]) for item in MAPPING])):
            probe = FixtureProbe()
            probe.overrides[(MAPPING[3]["ipv4"][0], COM, "NS")] = value
            with self.subTest(value=value):
                self.failed(self.verify(probe), "authoritative-ns")

    def test_missing_or_extra_branded_ns_fails(self):
        for names in ([item["name"] for item in MAPPING[:3]], [item["name"] for item in MAPPING] + ["other.example.com."]):
            probe = FixtureProbe()
            probe.overrides[(MAPPING[0]["ipv4"][0], KE, "NS")] = response([rr(KE, "NS", name) for name in names])
            with self.subTest(names=names):
                self.failed(self.verify(probe), "authoritative-ns")

    def test_soa_requires_ns1_mname_and_right_owner(self):
        for value in (soa(COM, MAPPING[0]["aws_name"]), soa("elsewhere.com."), rr(COM, "SOA", "broken")):
            probe = FixtureProbe()
            probe.overrides[(MAPPING[0]["ipv4"][0], COM, "SOA")] = response([value])
            with self.subTest(value=value):
                self.failed(self.verify(probe), "authoritative-soa")

    def test_own_host_alias_wrong_address_or_missing_aaaa_fails(self):
        name, server = MAPPING[2]["name"], MAPPING[1]["ipv4"][0]
        for rrtype, records in (("A", [rr(name, "CNAME", MAPPING[2]["aws_name"])]), ("A", [rr(name, "A", "205.251.199.9")]), ("AAAA", [])):
            probe = FixtureProbe()
            probe.overrides[(server, name, rrtype)] = response(records)
            with self.subTest(rrtype=rrtype, records=records):
                self.failed(self.verify(probe), "authoritative-address")

    def test_parent_referral_exact_ns_is_required_on_both_endpoints(self):
        probe = FixtureProbe()
        server = PARENT_IPS[PARENT_NAMES["com."][1]]
        value = probe.normal(COM, "NS", server)
        value["authority"][0]["value"] = "old.example.net."
        probe.overrides[(server, COM, "NS")] = value
        self.failed(self.verify(probe), "parent-ns")

    def test_child_aa_answer_cannot_substitute_for_parent_referral(self):
        probe = FixtureProbe()
        server = PARENT_IPS[PARENT_NAMES["com."][0]]
        probe.overrides[(server, COM, "NS")] = response([rr(COM, "NS", item["name"]) for item in MAPPING])
        self.failed(self.verify(probe), "parent-ns")

    def test_non_authoritative_cached_answer_also_cannot_substitute_for_parent_referral(self):
        probe = FixtureProbe()
        server = PARENT_IPS[PARENT_NAMES["com."][0]]
        probe.overrides[(server, COM, "NS")] = response([rr(COM, "NS", item["name"]) for item in MAPPING], flags=("qr",))
        self.failed(self.verify(probe), "parent-ns")

    def test_parent_endpoint_must_answer_authoritatively_for_parent(self):
        probe = FixtureProbe()
        server = PARENT_IPS[PARENT_NAMES["ke."][0]]
        probe.overrides[(server, "ke.", "SOA")] = response([soa("ke.")], flags=("qr", "ra"))
        self.failed(self.verify(probe), "parent-authority")

    def test_missing_wrong_or_stale_extra_glue_fails(self):
        server = PARENT_IPS[PARENT_NAMES["com."][0]]
        for mode in ("missing-a", "missing-aaaa", "wrong-a", "extra-aaaa", "alias"):
            probe = FixtureProbe()
            value = probe.normal(COM, "NS", server)
            if mode.startswith("missing"):
                kind = "A" if mode == "missing-a" else "AAAA"
                value["additional"] = [item for item in value["additional"] if not (item["name"] == MAPPING[0]["name"] and item["type"] == kind)]
            elif mode == "wrong-a":
                value["additional"][0]["value"] = "205.251.199.9"
            elif mode == "extra-aaaa":
                value["additional"].append(rr(MAPPING[0]["name"], "AAAA", "2600:9000:5309::9"))
            else:
                value["additional"].append(rr(MAPPING[0]["name"], "CNAME", MAPPING[0]["aws_name"]))
            probe.overrides[(server, COM, "NS")] = value
            with self.subTest(mode=mode):
                self.failed(self.verify(probe), "parent-glue")

    def test_parent_wrong_ds_and_unexpected_ds_on_unsigned_fail(self):
        for zone, parent, values in ((COM, "com.", ["12346 13 2 " + "B" * 64]), (KE, "ke.", DS[COM[:-1]])):
            probe = FixtureProbe()
            server = PARENT_IPS[PARENT_NAMES[parent][0]]
            probe.overrides[(server, zone, "DS")] = response([rr(zone, "DS", value) for value in values])
            with self.subTest(zone=zone):
                self.failed(self.verify(probe), "parent-ds")

    def test_unsigned_needs_parent_noerror_aa_nodata_with_parent_soa(self):
        server = PARENT_IPS[PARENT_NAMES["ke."][0]]
        for value in (response(), response(authority=[soa(KE)]), response(authority=[soa("ke.")], status="NXDOMAIN"), response(authority=[soa("ke.")], flags=("qr",))):
            probe = FixtureProbe()
            probe.overrides[(server, KE, "DS")] = value
            with self.subTest(value=value):
                self.failed(self.verify(probe), "parent-ds")

    def test_ds_normalizes_case_and_spacing_without_weakening_values(self):
        ds = copy.deepcopy(DS)
        ds[COM[:-1]] = [" 12345   13  2 " + "a" * 64]
        result = self.verify(FixtureProbe(), ds=ds)
        self.assertTrue(result["ok"], result["errors"])

    def test_parent_discovery_will_not_accept_authority_ns_as_exact_answer(self):
        probe = FixtureProbe()
        probe.overrides[("1.1.1.1", "co.ke.", "NS")] = response(authority=[rr("ke.", "NS", value) for value in PARENT_NAMES["ke."]], flags=("qr", "rd", "ra"))
        self.failed(self.verify(probe), "parent-discovery")

    def test_two_parent_hosts_sharing_one_address_are_insufficient(self):
        probe = FixtureProbe()
        second = PARENT_NAMES["ke."][1]
        probe.overrides[(None, second, "A")] = response([rr(second, "A", PARENT_IPS[PARENT_NAMES["ke."][0]])])
        self.failed(self.verify(probe), "parent-discovery")

    def test_parent_query_timeout_is_not_success_from_one_remaining_server(self):
        probe = FixtureProbe()
        server = PARENT_IPS[PARENT_NAMES["com."][1]]
        probe.overrides[(server, COM, "NS")] = m.DNSProbeError("Synthetic parent timeout")
        self.failed(self.verify(probe), "parent-ns")

    def test_recursive_servfail_wrong_ns_or_disabled_checking_fails(self):
        for value in (response(status="SERVFAIL", flags=("qr", "rd", "ra")), response([rr(COM, "NS", "old.example.com.")], flags=("qr", "rd", "ra")), response([rr(COM, "NS", item["name"]) for item in MAPPING], flags=("qr", "rd", "ra", "cd"))):
            probe = FixtureProbe()
            probe.overrides[("8.8.8.8", COM, "NS")] = value
            with self.subTest(value=value):
                self.failed(self.verify(probe), "recursive-ns")

    def test_recursive_stale_ds_also_fails(self):
        probe = FixtureProbe()
        probe.overrides[("8.8.8.8", COM, "DS")] = response(authority=[soa("com.")], flags=("qr", "rd", "ra"))
        self.failed(self.verify(probe), "recursive-ds")

    def test_malformed_probe_response_fails_closed(self):
        probe = FixtureProbe()
        probe.overrides[(MAPPING[0]["ipv4"][0], COM, "NS")] = {"status": "NOERROR", "flags": ["qr", "aa"]}
        self.failed(self.verify(probe), "authoritative-ns")


if __name__ == "__main__":
    unittest.main(verbosity=2)
