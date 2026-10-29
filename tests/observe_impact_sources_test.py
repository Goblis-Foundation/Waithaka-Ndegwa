#!/usr/bin/env python3
"""Offline contract and privacy tests for the public approved-impact status observer."""
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
import urllib.error
from unittest.mock import patch

LOCATION = Path(__file__).resolve().parents[1] / "deploy/aws/observe_impact_sources.py"
SPEC = importlib.util.spec_from_file_location("impact_probe", LOCATION)
probe = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(probe)

PRIVATE = "PRIVATE_MARKER_NEVER_RECORD"
FIXTURE = {
    "schema_version": 2,
    "source": "goblis_group",
    "approved_for_publication": True,
    "publication_status": "approved",
    "measurement_period_end": "2026-09-30",
    "report_id": PRIVATE,
    "metrics": {"startups": 3, "private_raw_record": PRIVATE}
}


class Response:
    status = 200
    headers = {"Content-Type": "application/json"}

    def __init__(self, body):
        self.body = body

    def read(self, size):
        return self.body[:size]

    def __enter__(self):
        return self

    def __exit__(self, *_):
        return False


class Opener:
    def __init__(self, result):
        self.result = result
        self.method = None
        self.url = None
        self.timeout = None

    def open(self, request, timeout):
        self.method = request.get_method()
        self.url = request.full_url
        self.timeout = timeout
        if isinstance(self.result, Exception):
            raise self.result
        return self.result


class ProbeTests(unittest.TestCase):
    def test_fixed_https_owned_source_endpoints(self):
        self.assertEqual(set(probe.SOURCES), {
            "goblis_group", "ubunifu", "afritrace", "goblis_foundation"
        })
        for url in probe.SOURCES.values():
            self.assertTrue(url.startswith("https://www."))
            self.assertTrue(url.endswith("/api/public/impact/waithaka/"))
            self.assertNotIn("@", url)
            self.assertNotIn("?", url)

    def test_private_fields_never_enter_status_report(self):
        body = json.dumps(FIXTURE).encode()
        opener = Opener(Response(body))
        report = probe.observe("goblis_group", probe.SOURCES["goblis_group"], opener=opener)
        self.assertEqual(report["state"], "approved_wire_report_seen")
        self.assertEqual(opener.method, "GET")
        self.assertEqual(opener.timeout, 12)
        self.assertNotIn(PRIVATE, json.dumps(report))
        self.assertNotIn("metrics", json.dumps(report))
        self.assertNotIn("report_id", json.dumps(report))

    def test_withdrawal_and_unavailable_are_not_zero(self):
        withdrawn = dict(FIXTURE, approved_for_publication=False, publication_status="withdrawn")
        withdrawn.pop("metrics")
        report = probe.classify("goblis_group", 200, "application/json", json.dumps(withdrawn).encode())
        self.assertEqual(report["state"], "source_withheld")
        for status, expected in [
            (503, "publication_not_available"),
            (401, "authorization_required"),
            (403, "authorization_required"),
            (404, "route_not_ready"),
            (302, "redirect_not_followed"),
        ]:
            self.assertEqual(probe.classify("goblis_group", status)["state"], expected)
        self.assertEqual(probe.classify("goblis_group", None)["state"], "unreachable")

    def test_wrong_identity_malformed_json_and_oversized_body(self):
        for input_data in [
            dict(FIXTURE, source="ubunifu"),
            dict(FIXTURE, schema_version=1),
            dict(FIXTURE, approved_for_publication="yes"),
            dict(FIXTURE, metrics=None),
            dict(FIXTURE, measurement_period_end="2026-99-99"),
        ]:
            state = probe.classify("goblis_group", 200, "application/json", json.dumps(input_data).encode())
            self.assertEqual(state["state"], "invalid_contract")
        self.assertEqual(probe.classify("goblis_group", 200, "text/html", b"nope")["state"], "unexpected_content_type")
        self.assertEqual(probe.classify("goblis_group", 200, "application/json", b"broken")["state"], "invalid_json")
        self.assertEqual(probe.classify("goblis_group", 200, "application/json", b"{}", oversized=True)["state"], "response_too_large")

    def test_http_error_body_not_logged(self):
        url = probe.SOURCES["goblis_group"]
        error = urllib.error.HTTPError(url, 503, PRIVATE, {}, io.BytesIO(PRIVATE.encode()))
        status = probe.observe("goblis_group", url, opener=Opener(error))
        self.assertEqual(status["state"], "publication_not_available")
        self.assertNotIn(PRIVATE, json.dumps(status))

    def test_report_artifact_omits_sensitive_source_details(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "status.json"
            def fake_observe(source, url):
                self.assertEqual(url, probe.SOURCES[source])
                return {"source": source, "http_status": 503, "state": "publication_not_available"}
            with patch.dict(os.environ, {"IMPACT_SOURCE_PROBE_OUT": str(output)}), patch.object(probe, "observe", side_effect=fake_observe):
                self.assertEqual(probe.main(), 0)
            report = json.loads(output.read_text())
            self.assertEqual(len(report["checks"]), 4)
            self.assertTrue(all(set(item)=={"source","http_status","state"} for item in report["checks"]), "The artifact may store only status fields, no metric values or source records.")
            self.assertNotIn("PRIVATE_MARKER", output.read_text())
            self.assertEqual({x["state"] for x in report["checks"]}, {"publication_not_available"})


if __name__ == "__main__":
    unittest.main(verbosity=2)
