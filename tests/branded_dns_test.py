#!/usr/bin/env python3
"""Offline DNS migration regressions: synthetic records, fake AWS, no DNS/network."""
import contextlib
import copy
import datetime as dt
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "deploy/aws"))
import branded_dns as m
import install_cutover as installer

AWS_NAMES = ["ns-1.awsdns-1.com.", "ns-2.awsdns-2.net.", "ns-3.awsdns-3.org.", "ns-4.awsdns-4.co.uk."]
ZONE_IDS = {m.DOMAINS[0]: "ZTARGETCOM", m.DOMAINS[1]: "ZTARGETKE"}
SET_ID = "NTESTSET001"


def record(name, kind, values, ttl=300, **extra):
    return {"Name": name.rstrip(".") + ".", "Type": kind, "TTL": ttl,
            "ResourceRecords": [{"Value": value} for value in values], **extra}


def initial_records(domain):
    return [record(domain, "NS", AWS_NAMES, 172800),
            record(domain, "SOA", [AWS_NAMES[0] + " hostmaster.awsdns.test. 2026010101 7200 900 1209600 86400"], 900)]


def key(row):
    return (row["Name"].lower().rstrip("."), row["Type"], row.get("SetIdentifier", ""))


class FakeProbe:
    def __init__(self):
        # These are synthetic fixture mappings; no address is contacted.
        self.mapping = {name: {"ipv4": [f"8.8.4.{index + 10}"], "ipv6": [f"2606:4700::{index + 10}"]}
                        for index, name in enumerate(AWS_NAMES)}

    def addresses(self, name):
        return copy.deepcopy(self.mapping[name])

    def query(self, *args, **kwargs):
        raise AssertionError("The integration suite must not execute DNS queries.")


class FakeAWS:
    def __init__(self, config):
        self.config = config
        self.calls = []
        self.sets = {}
        self.zones = {}
        self.rows = {}
        self.security = {}
        self.lost_set_response = False
        self.lost_zone_response = False
        self.lost_record_response = False
        self.missing_change_id = False
        self.fail_zone_once = None
        self.accepted_changes = []
        self.created_sets = 0
        self.created_zones = 0

    def identity(self):
        self.calls.append(("sts", "get-caller-identity", ()))
        return {"account_id": self.config["account_id"], "identity_checked": True}

    def zone(self, domain, caller="fixture-zone", names=None):
        zone_id = ZONE_IDS[domain]
        self.zones[zone_id] = {"HostedZone": {"Id": "/hostedzone/" + zone_id, "Name": domain + ".",
                              "CallerReference": caller, "Config": {"PrivateZone": False}},
                              "DelegationSet": {"Id": "/delegationset/" + SET_ID, "NameServers": names or AWS_NAMES}}
        self.rows[zone_id] = initial_records(domain)
        self.security[zone_id] = {"Status": {"ServeSignature": "NOT_SIGNING"}, "KeySigningKeys": []}
        return zone_id

    def call(self, service, operation, *arguments, **kwargs):
        self.calls.append((service, operation, arguments))
        if service != "route53":
            raise AssertionError("No registry, other project or non-Route53 operation is permitted.")
        args = list(arguments)
        def option(name):
            return args[args.index(name) + 1]
        if operation == "list-reusable-delegation-sets":
            return {"DelegationSets": copy.deepcopy(list(self.sets.values())), "IsTruncated": False}
        if operation == "get-reusable-delegation-set":
            return {"DelegationSet": copy.deepcopy(self.sets[option("--id")])}
        if operation == "create-reusable-delegation-set":
            self.created_sets += 1
            value = {"Id": "/delegationset/" + SET_ID, "CallerReference": option("--caller-reference"), "NameServers": AWS_NAMES}
            self.sets[SET_ID] = copy.deepcopy(value)
            if self.lost_set_response:
                self.lost_set_response = False
                raise TimeoutError("Synthetic response lost after set creation")
            return {"DelegationSet": copy.deepcopy(value)}
        if operation == "list-hosted-zones":
            return {"HostedZones": [copy.deepcopy(z["HostedZone"]) for z in self.zones.values()], "IsTruncated": False}
        if operation == "create-hosted-zone":
            self.created_zones += 1
            zone_id = self.zone(option("--name"), option("--caller-reference"))
            if self.lost_zone_response:
                self.lost_zone_response = False
                raise TimeoutError("Synthetic response lost after zone creation")
            return copy.deepcopy(self.zones[zone_id])
        if operation == "get-hosted-zone":
            return copy.deepcopy(self.zones[option("--id")])
        if operation == "get-dnssec":
            return copy.deepcopy(self.security[option("--hosted-zone-id")])
        if operation == "list-resource-record-sets":
            return {"ResourceRecordSets": copy.deepcopy(self.rows[option("--hosted-zone-id")]), "IsTruncated": False}
        if operation == "change-resource-record-sets":
            zone_id = option("--hosted-zone-id")
            if self.fail_zone_once == zone_id:
                self.fail_zone_once = None
                raise TimeoutError("Synthetic request failed before acceptance")
            batch = json.loads(option("--change-batch"))["Changes"]
            existing = {key(row): copy.deepcopy(row) for row in self.rows[zone_id]}
            for change in batch:
                action, row = change["Action"], change["ResourceRecordSet"]
                if action not in {"CREATE", "UPSERT"}:
                    raise AssertionError("Deletion is never permitted in this migration.")
                if action == "CREATE" and key(row) in existing:
                    raise AssertionError("A repeated CREATE would conflict at Route 53.")
                existing[key(row)] = copy.deepcopy(row)
            self.rows[zone_id] = list(existing.values())
            self.accepted_changes.append((zone_id, copy.deepcopy(batch)))
            if self.lost_record_response:
                self.lost_record_response = False
                raise TimeoutError("Synthetic response lost after acceptance")
            if self.missing_change_id:
                self.missing_change_id = False
                return {"ChangeInfo": {}}
            return {"ChangeInfo": {"Id": "/change/C" + str(len(self.accepted_changes))}}
        if operation == "get-change":
            return {"ChangeInfo": {"Status": "INSYNC"}}
        raise AssertionError("Unexpected or unsafe AWS operation: " + operation)


class BrandedDnsTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="waithaka-branded-dns-test-")
        self.directory = Path(self.temp.name)
        self.config = {"schema_version": 1, "deployment_id": "waithaka-fixture-dns", "account_id": "111122223333",
                       "region": "eu-west-1", "profile": "fixture", "state_dir": str(self.directory / "state"),
                       "delegation_set": {"id": "", "adopt_zone_id": ""},
                       "zones": {domain: {"hosted_zone_id": "", "source_export": str(self.directory / ("source-" + domain + ".json"))} for domain in m.DOMAINS}}
        Path(self.config["state_dir"]).mkdir(mode=0o700)
        self.probe = FakeProbe()
        self.aws = FakeAWS(self.config)
        self.sources = {}
        for domain in m.DOMAINS:
            source = {"schema_version": 1, "domain": domain, "source_provider": "route53", "source_zone_id": "ZSOURCECOM" if domain == m.DOMAINS[0] else "ZSOURCEKE",
                      "exported_at": m.now(), "records": initial_records(domain) + [record(domain, "MX", ["10 mail.provider.invalid."], 3600)],
                      "review": {"reviewed_by": "Offline fixture operator", "domain_control_confirmed": True, "complete_zone_export": True, "dnssec_transition_reviewed": True, "new_zone": False}}
            self.sources[domain] = source
            self.write_source(domain)
        self.no_network = patch.object(subprocess, "run", side_effect=AssertionError("No external process/network in mocked DNS tests"))
        self.no_network.start()

    def tearDown(self):
        self.no_network.stop()
        self.temp.cleanup()

    def write_source(self, domain):
        m.write_json(self.config["zones"][domain]["source_export"], self.sources[domain], overwrite=True)

    def provision(self):
        plan = m.provision_plan(self.config)
        return m.provision(self.config, plan, m.digest(plan), self.aws)

    def prepared(self):
        self.provision()
        snap = m.capture(self.config, self.aws, self.probe)
        return snap, m.build_plan(self.config, snap)

    def stabilization_plan(self):
        _, prepared = self.prepared()
        self.apply(prepared)
        with patch.object(m, "verify_delegation", return_value={"ok": True, "checked_at": m.now(), "checks": [{"ok": True}], "errors": []}):
            m.verify(self.config, self.aws, self.probe)
        return m.build_plan(self.config, m.capture(self.config, self.aws, self.probe), "stabilize")

    def apply(self, plan):
        return m.apply_plan(self.config, plan, m.digest(plan), self.aws, self.probe)

    def write_count(self):
        return len([call for call in self.aws.calls if call[1] == "change-resource-record-sets"])

    def test_cli_help_works_without_any_cloud_or_probe_access(self):
        with patch.object(m.sys, "argv", ["branded_dns.py", "--help"]), contextlib.redirect_stdout(io.StringIO()), self.assertRaises(SystemExit) as result:
            m.main()
        self.assertEqual(result.exception.code, 0)

    def test_full_paginated_record_inventory_preserves_routing_identifiers(self):
        domain = m.DOMAINS[0]
        first = record("route." + domain, "A", ["8.8.8.1"], SetIdentifier="route-a", Weight=50)
        second = record("route." + domain, "A", ["8.8.8.2"], SetIdentifier="route-b", Weight=50)
        pages = [{"ResourceRecordSets": [first], "IsTruncated": True, "NextRecordName": second["Name"], "NextRecordType": "A", "NextRecordIdentifier": "route-b"},
                 {"ResourceRecordSets": [second, record(domain, "MX", ["10 mail.provider.invalid."])], "IsTruncated": False}]
        with patch.object(self.aws, "call", side_effect=pages) as calls:
            exported = m.records(self.aws, ZONE_IDS[domain])
        self.assertEqual(len(exported), 3)
        self.assertEqual({r["SetIdentifier"] for r in exported if "SetIdentifier" in r}, {"route-a", "route-b"})
        self.assertIn("--start-record-identifier", calls.call_args_list[1].args)
        self.assertIn("route-b", calls.call_args_list[1].args)
        self.assertTrue(all("--no-paginate" in call.args for call in calls.call_args_list))

    def test_pagination_missing_or_repeated_continuation_is_not_a_complete_inventory(self):
        missing = {"ResourceRecordSets": [], "IsTruncated": True}
        repeated = {"ResourceRecordSets": [], "IsTruncated": True, "NextRecordName": "next.waithakandegwa.com.", "NextRecordType": "TXT", "NextRecordIdentifier": "routed"}
        for pages in ([missing], [repeated, repeated]):
            with self.subTest(pages=pages), patch.object(self.aws, "call", side_effect=pages), self.assertRaises(m.GuardError):
                m.records(self.aws, ZONE_IDS[m.DOMAINS[0]])
        with patch.object(self.aws, "call", side_effect=[{"HostedZones": [{"Id": "ZONE1"}], "IsTruncated": True, "NextMarker": "next"}, {"HostedZones": [{"Id": "ZONE2"}], "IsTruncated": False}]) as calls:
            self.assertEqual(len(m.list_all(self.aws, "list-hosted-zones", "HostedZones")), 2)
        self.assertIn("--marker", calls.call_args_list[1].args)
        with patch.object(self.aws, "call", return_value={"HostedZones": [], "IsTruncated": True, "NextMarker": "loop"}), self.assertRaises(m.GuardError):
            m.list_all(self.aws, "list-hosted-zones", "HostedZones")

    def test_provision_is_idempotent_and_pins_both_actual_resources(self):
        self.assertEqual(m.validate_config(self.config), self.config)
        first = self.provision()
        self.assertEqual(first["zones"], ZONE_IDS)
        self.assertEqual(first["delegation_set_id"], SET_ID)
        self.assertEqual(self.provision(), first)
        self.assertEqual((self.aws.created_sets, self.aws.created_zones), (1, 2))
        self.assertTrue(all(service in {"sts", "route53"} and not operation.startswith("delete") for service, operation, _ in self.aws.calls))

    def test_provision_recovers_remote_set_and_zone_creation_after_lost_responses(self):
        self.aws.lost_set_response = True
        with self.assertRaises(TimeoutError):
            self.provision()
        self.aws.lost_zone_response = True
        with self.assertRaises(TimeoutError):
            self.provision()
        self.assertEqual(self.provision()["zones"], ZONE_IDS)
        self.assertEqual((self.aws.created_sets, self.aws.created_zones), (1, 2))

    def test_explicit_reusable_set_cannot_silently_switch_returned_identity(self):
        self.config["delegation_set"]["id"] = SET_ID
        self.aws.sets[SET_ID] = {"Id": "/delegationset/NUNEXPECTED", "NameServers": AWS_NAMES}
        self.aws.sets["NUNEXPECTED"] = copy.deepcopy(self.aws.sets[SET_ID])
        with self.assertRaises(m.GuardError):
            self.provision()
        self.assertEqual(self.aws.created_zones, 0)

    def test_adopted_zone_must_match_public_name_and_physical_server_set(self):
        self.config["delegation_set"]["id"] = SET_ID
        self.config["zones"][m.DOMAINS[0]]["hosted_zone_id"] = ZONE_IDS[m.DOMAINS[0]]
        self.aws.sets[SET_ID] = {"Id": "/delegationset/" + SET_ID, "NameServers": AWS_NAMES}
        self.aws.zone(m.DOMAINS[0], names=["ns-9.awsdns-9.com.", *AWS_NAMES[1:]])
        with self.assertRaises(m.GuardError):
            self.provision()
        self.assertEqual(self.aws.created_zones, 0)
        self.aws.zones[ZONE_IDS[m.DOMAINS[0]]]["DelegationSet"]["NameServers"] = AWS_NAMES
        self.aws.zones[ZONE_IDS[m.DOMAINS[0]]]["HostedZone"]["Config"]["PrivateZone"] = True
        with self.assertRaises(m.GuardError):
            self.provision()

    def test_wrong_provision_hash_and_changed_config_stop_before_aws(self):
        plan = m.provision_plan(self.config)
        with self.assertRaises(m.GuardError):
            m.provision(self.config, plan, "0" * 64, self.aws)
        self.assertEqual(self.aws.calls, [])
        self.provision()
        self.config["account_id"] = "999988887777"
        with self.assertRaises(m.GuardError):
            m.state(self.config)

    def test_prepare_preserves_mail_dkim_ds_alias_and_routed_records_exactly(self):
        domain = m.DOMAINS[0]
        extras = [record(domain, "TXT", ['"v=spf1 include:mail.provider.invalid -all"']),
                  record("selector._domainkey." + domain, "TXT", ['"v=DKIM1; p=fixture"']),
                  record("_dmarc." + domain, "TXT", ['"v=DMARC1; p=reject"']),
                  record("child." + domain, "DS", ["12345 13 2 " + "A" * 64]),
                  record("child." + domain, "NS", ["ns.child-provider.invalid."]),
                  record(domain, "CAA", ['0 issue "fixture-ca.invalid"']),
                  record("route." + domain, "A", ["8.8.8.1"], SetIdentifier="east", Weight=10, HealthCheckId="fixture-health"),
                  record("route." + domain, "A", ["8.8.8.2"], SetIdentifier="west", Weight=90),
                  record("latency." + domain, "A", ["8.8.8.3"], SetIdentifier="eu", Region="eu-west-1"),
                  record("geo." + domain, "A", ["8.8.8.4"], SetIdentifier="kenya", GeoLocation={"CountryCode": "KE"}),
                  {"Name": "cdn." + domain + ".", "Type": "A", "AliasTarget": {"HostedZoneId": "Z2FDTNDATAQYW2", "DNSName": "fixture.cloudfront.net.", "EvaluateTargetHealth": False}},
                  {"Name": "foreign." + domain + ".", "Type": "A", "AliasTarget": {"HostedZoneId": "ZFOREIGN", "DNSName": "record.other.invalid.", "EvaluateTargetHealth": False}},
                  {"Name": "internal." + domain + ".", "Type": "A", "AliasTarget": {"HostedZoneId": "ZSOURCECOM", "DNSName": "route." + domain + ".", "EvaluateTargetHealth": True}}]
        self.sources[domain]["records"].extend(extras)
        self.write_source(domain)
        snap, plan = self.prepared()
        before_source = m.read_json(self.config["zones"][domain]["source_export"])
        expected = copy.deepcopy(extras)
        expected[-1]["AliasTarget"]["HostedZoneId"] = ZONE_IDS[domain]
        after = {key(row): row for row in plan["zones"][domain]["after"]}
        for row in expected:
            self.assertEqual(after[key(row)], row)
        self.apply(plan)
        self.assertEqual(m.read_json(self.config["zones"][domain]["source_export"]), before_source)
        self.assertEqual(m.records(self.aws, ZONE_IDS[domain]), plan["zones"][domain]["after"])
        self.assertTrue(all(change["Action"] in {"CREATE", "UPSERT"} for _, batch in self.aws.accepted_changes for change in batch))

    def test_prepare_changes_only_managed_ns_soa_and_eight_nameserver_addresses(self):
        snap, plan = self.prepared()
        for domain in m.DOMAINS:
            after = {key(row): row for row in plan["zones"][domain]["after"]}
            ns = after[(domain, "NS", "")]
            self.assertEqual(ns["TTL"], 60)
            self.assertEqual({item["Value"] for item in ns["ResourceRecords"]}, set(m.NAMES))
            old = next(row for row in snap["zones"][domain]["records"] if row["Type"] == "SOA")
            soa = after[(domain, "SOA", "")]
            original = old["ResourceRecords"][0]["Value"].split()
            actual = soa["ResourceRecords"][0]["Value"].split()
            self.assertEqual(actual[1:-1], original[1:-1])
            self.assertEqual((actual[0], actual[-1], soa["TTL"]), (m.NAMES[0], "60", old["TTL"]))
        addresses = [row for row in plan["zones"][m.DOMAINS[0]]["after"] if row["Name"] in m.NAMES]
        self.assertEqual(len(addresses), 8)
        self.assertTrue(all(row["TTL"] == 60 for row in addresses))
        self.assertFalse(any(row["Name"] in m.NAMES for row in plan["zones"][m.DOMAINS[1]]["after"]))

    def test_missing_review_stale_source_and_non_new_empty_export_are_rejected(self):
        self.provision()
        snap = m.capture(self.config, self.aws, self.probe)
        domain = m.DOMAINS[0]
        original = copy.deepcopy(self.sources[domain])
        invalid = [dict(original, exported_at="2000-01-01T00:00:00Z"), dict(original, records=[]),
                   dict(original, domain="other.invalid"), dict(original, review={**original["review"], "complete_zone_export": False}),
                   dict(original, review={**original["review"], "reviewed_by": ""})]
        for source in invalid:
            self.sources[domain] = source
            self.write_source(domain)
            with self.subTest(source=source), self.assertRaises(m.GuardError):
                m.build_plan(self.config, snap)
        self.assertEqual(self.write_count(), 0)

    def test_empty_new_secondary_zone_needs_explicit_review(self):
        self.sources[m.DOMAINS[1]]["records"] = []
        self.write_source(m.DOMAINS[1])
        self.provision()
        snap = m.capture(self.config, self.aws, self.probe)
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, snap)
        self.sources[m.DOMAINS[1]]["review"]["new_zone"] = True
        self.write_source(m.DOMAINS[1])
        self.assertEqual(len(m.build_plan(self.config, snap)["zones"][m.DOMAINS[1]]["after"]), 2)

    def test_conflicting_existing_mail_and_nameserver_routing_are_rejected_offline(self):
        self.provision()
        domain = m.DOMAINS[0]
        self.aws.rows[ZONE_IDS[domain]].append(record(domain, "MX", ["10 different.invalid."]))
        snap = m.capture(self.config, self.aws, self.probe)
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, snap)
        self.aws.rows[ZONE_IDS[domain]] = initial_records(domain) + [record(m.NAMES[0], "CNAME", ["somewhere.invalid."])]
        snap = m.capture(self.config, self.aws, self.probe)
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, snap)
        self.assertEqual(self.write_count(), 0)

    def test_malformed_export_records_and_traffic_flow_are_not_plannable(self):
        domain = m.DOMAINS[0]
        malformed = [record(domain, "TXT", ['"fixture"'], ttl=True), {"Name": domain, "Type": "A", "AliasTarget": {}},
                     record("outside.invalid", "A", ["8.8.8.8"]), record(domain, "A", ["8.8.8.8"], TrafficPolicyInstanceId="managed")]
        self.provision()
        snap = m.capture(self.config, self.aws, self.probe)
        for bad in malformed:
            self.sources[domain]["records"] = initial_records(domain) + [bad]
            self.write_source(domain)
            with self.subTest(record=bad), self.assertRaises(m.GuardError):
                m.build_plan(self.config, snap)
        duplicate = record(domain, "TXT", ['"fixture"'])
        with self.assertRaises(m.GuardError):
            m.normalized([duplicate, duplicate])

    def test_batch_quotas_reserve_double_counting_for_unknown_outcome_upserts(self):
        changes = [{"Action": "CREATE", "ResourceRecordSet": record(f"record{i}.waithakandegwa.com", "A", ["8.8.8.8"])} for i in range(501)]
        result = m.batches(changes)
        self.assertEqual([len(batch) for batch in result], [500, 1])
        large = [{"Action": "CREATE", "ResourceRecordSet": record("a.waithakandegwa.com", "TXT", ["x" * 9000])},
                 {"Action": "CREATE", "ResourceRecordSet": record("b.waithakandegwa.com", "TXT", ["x" * 9000])}]
        self.assertEqual([len(batch) for batch in m.batches(large)], [1, 1])
        for row in (record("many.waithakandegwa.com", "TXT", [str(i) for i in range(501)]), record("large.waithakandegwa.com", "TXT", ["x" * 16001])):
            with self.assertRaises(m.GuardError):
                m.batches([{"Action": "CREATE", "ResourceRecordSet": row}])

    def test_both_zones_preflight_blocks_any_write_on_other_zone_drift(self):
        snap, plan = self.prepared()
        self.aws.rows[ZONE_IDS[m.DOMAINS[1]]].append(record("new.waithakandegwa.co.ke", "TXT", ['"unrelated edit"']))
        with self.assertRaises(m.GuardError):
            self.apply(plan)
        self.assertEqual(self.write_count(), 0)

    def test_mapping_and_dnssec_drift_block_any_record_mutation(self):
        snap, plan = self.prepared()
        original = copy.deepcopy(self.probe.mapping[AWS_NAMES[0]])
        self.probe.mapping[AWS_NAMES[0]]["ipv4"] = ["8.8.4.99"]
        with self.assertRaises(m.GuardError):
            self.apply(plan)
        self.probe.mapping[AWS_NAMES[0]] = original
        self.aws.security[ZONE_IDS[m.DOMAINS[1]]] = {"Status": {"ServeSignature": "SIGNING"}, "KeySigningKeys": [{"Status": "ACTIVE", "DSRecord": "12345 13 2 " + "A" * 64}]}
        with self.assertRaises(m.GuardError):
            self.apply(plan)
        self.assertEqual(self.write_count(), 0)

    def test_unhealthy_dnssec_and_nonpublic_missing_address_families_are_rejected(self):
        self.provision()
        self.aws.security[ZONE_IDS[m.DOMAINS[0]]]["Status"]["ServeSignature"] = "INTERNAL_FAILURE"
        with self.assertRaises(m.GuardError):
            m.capture(self.config, self.aws, self.probe)
        self.aws.security[ZONE_IDS[m.DOMAINS[0]]]["Status"]["ServeSignature"] = "NOT_SIGNING"
        for family, values in (("ipv4", ["127.0.0.1"]), ("ipv6", []), ("ipv4", ["8.8.4.11"])):
            probe = FakeProbe()
            probe.mapping[AWS_NAMES[0]][family] = values
            with self.subTest(family=family, values=values), self.assertRaises(m.GuardError):
                m.capture(self.config, self.aws, probe)

    def test_partial_two_zone_retry_preserves_completed_zone_without_reposting(self):
        _, plan = self.prepared()
        self.aws.fail_zone_once = ZONE_IDS[m.DOMAINS[1]]
        with self.assertRaises(TimeoutError):
            self.apply(plan)
        journal = m.state(self.config)["applications"][m.digest(plan)]
        self.assertEqual(journal["zones"][m.DOMAINS[0]]["completed"], len(plan["zones"][m.DOMAINS[0]]["batches"]))
        self.assertNotIn("completed_at", journal)
        self.apply(plan)
        self.assertEqual([zone for zone, _ in self.aws.accepted_changes].count(ZONE_IDS[m.DOMAINS[0]]), 1)
        self.assertEqual([zone for zone, _ in self.aws.accepted_changes].count(ZONE_IDS[m.DOMAINS[1]]), 1)

    def test_response_loss_reconciles_exact_result_with_upsert_not_repeated_create(self):
        _, plan = self.prepared()
        self.aws.lost_record_response = True
        with self.assertRaises(TimeoutError):
            self.apply(plan)
        entry = m.state(self.config)["applications"][m.digest(plan)]["zones"][m.DOMAINS[0]]["changes"]["0"]
        self.assertTrue(entry["submitted"])
        self.assertNotIn("change_id", entry)
        self.apply(plan)
        self.assertTrue(all(change["Action"] == "UPSERT" for change in self.aws.accepted_changes[1][1]))
        self.assertEqual(m.records(self.aws, ZONE_IDS[m.DOMAINS[0]]), plan["zones"][m.DOMAINS[0]]["after"])

    def test_missing_change_id_never_claims_insync_and_can_reconcile_exact_result(self):
        _, plan = self.prepared()
        self.aws.missing_change_id = True
        with self.assertRaises(m.GuardError):
            self.apply(plan)
        journal = m.state(self.config)["applications"][m.digest(plan)]
        self.assertEqual(journal["zones"][m.DOMAINS[0]]["completed"], 0)
        self.assertNotIn("completed_at", journal)
        self.apply(plan)
        self.assertTrue(all(change["Action"] == "UPSERT" for change in self.aws.accepted_changes[1][1]))

    def test_saved_change_id_is_polled_on_retry_without_submitting_again(self):
        _, plan = self.prepared()
        with patch.object(m, "wait_change", side_effect=m.GuardError("Synthetic pending change")), self.assertRaises(m.GuardError):
            self.apply(plan)
        count = self.write_count()
        self.apply(plan)
        self.assertEqual(self.write_count(), count + 1)  # only the second zone is submitted

    def test_multi_batch_response_loss_resumes_at_the_recorded_boundary(self):
        domain = m.DOMAINS[0]
        self.sources[domain]["records"].extend(record(f"entry{index:04d}." + domain, "A", ["8.8.8.8"]) for index in range(501))
        self.write_source(domain)
        _, plan = self.prepared()
        self.assertEqual(len(plan["zones"][domain]["batches"]), 2)
        original = self.aws.call
        submissions = 0
        def lose_second_response(service, operation, *arguments, **kwargs):
            nonlocal submissions
            if operation == "change-resource-record-sets":
                submissions += 1
                if submissions == 2:
                    self.aws.lost_record_response = True
            return original(service, operation, *arguments, **kwargs)
        with patch.object(self.aws, "call", side_effect=lose_second_response), self.assertRaises(TimeoutError):
            self.apply(plan)
        journal = m.state(self.config)["applications"][m.digest(plan)]["zones"][domain]
        self.assertEqual(journal["completed"], 1)
        self.assertIn("change_id", journal["changes"]["0"])
        self.assertNotIn("change_id", journal["changes"]["1"])
        self.apply(plan)
        com_batches = [batch for zone, batch in self.aws.accepted_changes if zone == ZONE_IDS[domain]]
        self.assertEqual(len(com_batches), 3)
        self.assertTrue(all(change["Action"] == "UPSERT" for change in com_batches[-1]))
        self.assertEqual(m.records(self.aws, ZONE_IDS[domain]), plan["zones"][domain]["after"])

    def test_unknown_outcome_plus_unrelated_change_is_never_overwritten(self):
        _, plan = self.prepared()
        self.aws.lost_record_response = True
        with self.assertRaises(TimeoutError):
            self.apply(plan)
        self.aws.rows[ZONE_IDS[m.DOMAINS[0]]].append(record("new.waithakandegwa.com", "TXT", ['"operator edit"']))
        count = self.write_count()
        with self.assertRaises(m.GuardError):
            self.apply(plan)
        self.assertEqual(self.write_count(), count)

    def test_changed_export_or_stale_snapshot_invalidates_reviewed_plan_before_aws(self):
        snap, plan = self.prepared()
        domain = m.DOMAINS[0]
        source_path = Path(self.config["zones"][domain]["source_export"])
        source_path.write_bytes(source_path.read_bytes() + b"\n")
        count = len(self.aws.calls)
        with self.assertRaises(m.GuardError):
            self.apply(plan)
        self.assertEqual(len(self.aws.calls), count)
        snap["captured_at"] = "2000-01-01T00:00:00Z"
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, snap)

    def test_stabilization_requires_fresh_complete_two_domain_verification(self):
        _, plan = self.prepared()
        self.apply(plan)
        snap = m.capture(self.config, self.aws, self.probe)
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, snap, "stabilize")
        with patch.object(m, "verify_delegation", return_value={"ok": True, "checked_at": m.now(), "checks": [{"ok": True}], "errors": []}):
            m.verify(self.config, self.aws, self.probe)
        s = m.state(self.config)
        good = copy.deepcopy(s["verified"])
        for verified in ({**good, "zones": {m.DOMAINS[0]: ZONE_IDS[m.DOMAINS[0]]}},
                         {**good, "checked_at": "2000-01-01T00:00:00Z"}):
            s["verified"] = verified
            m.save(self.config, s)
            with self.assertRaises(m.GuardError):
                m.build_plan(self.config, snap, "stabilize")
        s["verified"] = good
        m.save(self.config, s)
        stabilized = m.build_plan(self.config, snap, "stabilize")
        ns = next(row for row in stabilized["zones"][m.DOMAINS[0]]["after"] if row["Type"] == "NS" and row["Name"] == m.DOMAINS[0] + ".")
        self.assertEqual(ns["TTL"], 172800)
        self.assertEqual(stabilized["ttl"], {"ns": 172800, "address": 3600, "negative": 900})
        self.assertEqual(next(row for row in stabilized["zones"][m.DOMAINS[0]]["after"] if row["Type"] == "MX"), next(row for row in snap["zones"][m.DOMAINS[0]]["records"] if row["Type"] == "MX"))

    def test_verification_producer_report_matches_installer_gate_and_partial_is_rejected(self):
        _, plan = self.prepared()
        self.apply(plan)
        def verified(zones, mapping, expected_ds, probe=None):
            self.assertEqual(expected_ds, {domain: [] for domain in zones})
            return {"ok": True, "checked_at": m.now(), "checks": [{"id": "fixture-observation", "ok": True, "context": {}, "expected": True, "observed": True}], "errors": [], "observations": {"fixture_only": True}}
        with patch.object(m, "verify_delegation", side_effect=verified):
            partial = m.verify(self.config, self.aws, self.probe, only_domain=m.DOMAINS[0])
            self.assertNotIn("verified", m.state(self.config))
            complete = m.verify(self.config, self.aws, self.probe)
        report_path = self.directory / "delegation-verification.json"
        installer_config = {"environment": "production", "account_id": self.config["account_id"],
                            "dns": {"provider": "route53", "branded_delegation_report": str(report_path),
                                    "zones": {host: zone for domain, zone in ZONE_IDS.items() for host in (domain, "www." + domain)}}}
        m.write_json(report_path, partial)
        with self.assertRaises(installer.GuardError):
            installer.branded_delegation_evidence(installer_config)
        m.write_json(report_path, complete, overwrite=True)
        evidence = installer.branded_delegation_evidence(installer_config)
        self.assertEqual(evidence["zones"], ZONE_IDS)
        self.assertEqual(evidence["sha256"], m.file_hash(report_path))
        self.assertEqual(m.state(self.config)["verified"]["report_sha256"], m.digest(complete))

    def test_signed_verification_passes_only_active_target_ds_and_failed_report_cannot_verify(self):
        _, plan = self.prepared()
        self.apply(plan)
        domain = m.DOMAINS[0]
        active = "12345 13 2 " + "A" * 64
        self.aws.security[ZONE_IDS[domain]] = {"Status": {"ServeSignature": "SIGNING"}, "KeySigningKeys": [{"Status": "ACTIVE", "DSRecord": active}, {"Status": "INACTIVE", "DSRecord": "54321 13 2 " + "B" * 64}]}
        def failed(zones, mapping, expected_ds, probe=None):
            self.assertEqual(expected_ds[domain], [active])
            return {"ok": False, "checked_at": m.now(), "checks": [{"ok": False}], "errors": ["Synthetic parent mismatch"]}
        with patch.object(m, "verify_delegation", side_effect=failed):
            self.assertFalse(m.verify(self.config, self.aws, self.probe)["ok"])
        self.assertNotIn("verified", m.state(self.config))
        self.aws.security[ZONE_IDS[domain]]["KeySigningKeys"] = []
        with self.assertRaises(m.GuardError):
            m.verify(self.config, self.aws, self.probe)

    def test_later_failed_verification_revokes_prior_stabilization_evidence(self):
        _, plan = self.prepared()
        self.apply(plan)
        success = {"ok": True, "checked_at": m.now(), "checks": [{"ok": True}], "errors": []}
        failure = {"ok": False, "checked_at": m.now(), "checks": [{"ok": False}], "errors": ["Synthetic parent DS mismatch"]}
        with patch.object(m, "verify_delegation", return_value=copy.deepcopy(success)):
            m.verify(self.config, self.aws, self.probe)
        self.assertTrue(m.state(self.config)["verified"]["ok"])
        with patch.object(m, "verify_delegation", return_value=failure):
            self.assertFalse(m.verify(self.config, self.aws, self.probe)["ok"])
        self.assertNotIn("verified", m.state(self.config))
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, m.capture(self.config, self.aws, self.probe), "stabilize")

    def test_later_verification_transport_failure_revokes_prior_success(self):
        _, plan = self.prepared()
        self.apply(plan)
        success = {"ok": True, "checked_at": m.now(), "checks": [{"ok": True}], "errors": []}
        with patch.object(m, "verify_delegation", return_value=success):
            m.verify(self.config, self.aws, self.probe)
        with patch.object(m, "capture", side_effect=m.DNSProbeError("Synthetic lookup failure")), self.assertRaises(m.DNSProbeError):
            m.verify(self.config, self.aws, self.probe)
        self.assertNotIn("verified", m.state(self.config))

    def test_stabilization_cannot_reuse_verification_after_target_dnssec_changed(self):
        _, plan = self.prepared()
        self.apply(plan)
        success = {"ok": True, "checked_at": m.now(), "checks": [{"ok": True}], "errors": []}
        with patch.object(m, "verify_delegation", return_value=success):
            m.verify(self.config, self.aws, self.probe)
        self.aws.security[ZONE_IDS[m.DOMAINS[0]]] = {"Status": {"ServeSignature": "SIGNING"}, "KeySigningKeys": [{"Status": "ACTIVE", "DSRecord": "12345 13 2 " + "A" * 64}]}
        snap = m.capture(self.config, self.aws, self.probe)
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, snap, "stabilize")

    def test_new_plan_cannot_bypass_an_unfinished_change_and_its_insync_wait(self):
        _, plan = self.prepared()
        with patch.object(m, "wait_change", side_effect=m.GuardError("Synthetic pending change")), self.assertRaises(m.GuardError):
            self.apply(plan)
        replacement = m.build_plan(self.config, m.capture(self.config, self.aws, self.probe))
        self.assertNotEqual(m.digest(replacement), m.digest(plan))
        calls = len(self.aws.calls)
        with self.assertRaises(m.GuardError):
            self.apply(replacement)
        self.assertEqual(len(self.aws.calls), calls)
        self.apply(plan)
        self.assertIn("completed_at", m.state(self.config)["applications"][m.digest(plan)])

    def test_exact_started_plan_can_reconcile_its_known_change_after_evidence_ages(self):
        _, plan = self.prepared()
        with patch.object(m, "wait_change", side_effect=m.GuardError("Synthetic pending change")), self.assertRaises(m.GuardError):
            self.apply(plan)
        original_datetime = dt.datetime
        later = original_datetime.now(dt.timezone.utc) + dt.timedelta(days=8)
        class Later(original_datetime):
            @classmethod
            def now(cls, tz=None):
                return later.astimezone(tz) if tz else later.replace(tzinfo=None)
        writes = self.write_count()
        with patch.object(m.dt, "datetime", Later):
            self.apply(plan)
        self.assertEqual(self.write_count(), writes + 1)
        self.assertIn("completed_at", m.state(self.config)["applications"][m.digest(plan)])

    def test_unstarted_plan_does_not_gain_the_interrupted_resume_age_exception(self):
        _, plan = self.prepared()
        original_datetime = dt.datetime
        later = original_datetime.now(dt.timezone.utc) + dt.timedelta(days=8)
        class Later(original_datetime):
            @classmethod
            def now(cls, tz=None):
                return later.astimezone(tz) if tz else later.replace(tzinfo=None)
        calls = len(self.aws.calls)
        with patch.object(m.dt, "datetime", Later), self.assertRaises(m.GuardError):
            self.apply(plan)
        self.assertEqual(len(self.aws.calls), calls)

    def test_reapplying_a_completed_plan_does_not_erase_newer_verified_evidence(self):
        _, plan = self.prepared()
        self.apply(plan)
        with patch.object(m, "verify_delegation", return_value={"ok": True, "checked_at": m.now(), "checks": [{"ok": True}], "errors": []}):
            m.verify(self.config, self.aws, self.probe)
        verified = m.state(self.config)["verified"]
        writes = self.write_count()
        self.apply(plan)
        self.assertEqual(self.write_count(), writes)
        self.assertEqual(m.state(self.config)["verified"], verified)

    def test_pending_stabilization_blocks_new_readiness_then_resumes_its_saved_proof(self):
        plan = self.stabilization_plan()
        accepted = copy.deepcopy(plan["verification"])
        with patch.object(m, "wait_change", side_effect=m.GuardError("Synthetic pending stabilization")), self.assertRaises(m.GuardError):
            self.apply(plan)
        saved = m.state(self.config)["applications"][m.digest(plan)]
        change_id = saved["zones"][m.DOMAINS[0]]["changes"]["0"]["change_id"]
        replacement = m.build_plan(self.config, m.capture(self.config, self.aws, self.probe), "stabilize")
        self.assertNotEqual(m.digest(replacement), m.digest(plan))
        calls = len(self.aws.calls)
        with self.assertRaises(m.GuardError):
            self.apply(replacement)
        self.assertEqual(len(self.aws.calls), calls)
        with patch.object(m, "verify_delegation", side_effect=AssertionError("Readiness cannot bypass the pending Route53 change")), self.assertRaises(m.GuardError):
            m.verify(self.config, self.aws, self.probe)
        self.assertEqual(len(self.aws.calls), calls)
        self.assertNotIn("verified", m.state(self.config))
        self.assertEqual(plan["verification"], accepted)
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, m.capture(self.config, self.aws, self.probe), "stabilize")
        writes = self.write_count()
        start = len(self.aws.calls)
        self.apply(plan)
        self.assertEqual(self.write_count(), writes + 1)  # only the still-unsubmitted .co.ke batch
        self.assertIn(("route53", "get-change", ("--id", change_id)), self.aws.calls[start:])
        self.assertIn("completed_at", m.state(self.config)["applications"][m.digest(plan)])

    def test_completed_stabilization_replay_uses_immutable_proof_without_new_mutation(self):
        plan = self.stabilization_plan()
        self.apply(plan)
        self.assertNotIn("verified", m.state(self.config))
        writes = self.write_count()
        with patch.object(m, "verify_delegation", side_effect=AssertionError("Exact completed-plan replay does not fabricate a fresh observation")):
            self.apply(plan)
        self.assertEqual(self.write_count(), writes)
        self.assertIn("completed_at", m.state(self.config)["applications"][m.digest(plan)])
        for domain in m.DOMAINS:
            self.assertEqual(m.records(self.aws, ZONE_IDS[domain]), plan["zones"][domain]["after"])
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, m.capture(self.config, self.aws, self.probe), "stabilize")

if __name__ == "__main__":
    unittest.main(verbosity=2)
