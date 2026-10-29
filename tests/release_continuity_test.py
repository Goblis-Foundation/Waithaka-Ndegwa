#!/usr/bin/env python3
"""Offline no-AWS/no-network regression for deployment continuity and Start Here."""
from __future__ import annotations
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "release_continuity", ROOT / "deploy/aws/release_continuity.py")
assert SPEC and SPEC.loader
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)


def page(marker: str) -> bytes:
    return ('<!doctype html><html><main><h1>' + marker + '</h1>' +
            '<p>Genuine public content and published journey steps. </p>' * 45 +
            '</main></html>').encode()


class ContinuityTests(unittest.TestCase):
    def test_complete_start_here_and_home_accepted(self):
        start = page('Welcome to the journey. Explore My Thoughts. '
                     'Explore Startup Journeys. Choose a practical resource. '
                     'Read Founder Stories')
        self.assertIsNone(m.verdict("/start-here", 200, start))
        self.assertIsNone(m.verdict("/", 200, page("My Thoughts")))
        self.assertIsNone(m.verdict("/healthz", 200, b'{"ok":true}'))

    def test_blank_http_and_failed_database_are_rejected(self):
        self.assertEqual(m.verdict("/start-here", 502, b""), "http_502")
        self.assertEqual(m.verdict("/start-here", 200, b""), "page_empty_or_missing_landmarks")
        self.assertEqual(m.verdict("/start-here", 200, page("My Thoughts")),
                         "start_here_missing_required_content")
        self.assertEqual(m.verdict("/healthz", 503, b""), "http_503")
        self.assertEqual(m.verdict("/healthz", 200, b'{"ok":false}'), "health_not_ready")
        self.assertEqual(m.verdict("/healthz", 200, b"<html>error</html>"), "health_invalid_json")

    def test_bounded_read_only_sample_detects_a_mid_release_502(self):
        def good(path):
            if path == "/healthz":
                return 200, b'{"ok":true}'
            return 200, page("My Thoughts" if path == "/" else
                "Welcome to the journey. Explore My Thoughts. Explore Startup Journeys. "
                "Choose a practical resource. Read Founder Stories")
        self.assertTrue(m.sample(good)["ok"])
        def fails(path):
            return (502, b"") if path == "/start-here" else good(path)
        observed = m.sample(fails)
        self.assertFalse(observed["ok"])
        self.assertEqual(observed["checks"]["/start-here"]["failure"], "http_502")
        self.assertEqual(observed["checks"]["/"]["status"], 200)

    def test_immutable_policy_with_unchanged_permanent_capacity(self):
        options = json.loads((ROOT / "deploy/aws/beanstalk-options.template.json").read_text())
        selected = {(x["Namespace"], x["OptionName"]): x["Value"] for x in options}
        self.assertEqual(selected[("aws:elasticbeanstalk:command", "DeploymentPolicy")],
                         "Immutable")
        self.assertEqual(selected[("aws:autoscaling:asg", "MinSize")], "1")
        self.assertEqual(selected[("aws:autoscaling:asg", "MaxSize")], "2")
        self.assertEqual(selected[("aws:elasticbeanstalk:environment", "EnvironmentType")],
                         "LoadBalanced")

    def test_routine_release_does_not_reapply_bootstrap_or_dns(self):
        application = (ROOT / "deploy/aws/fresh_application.sh").read_text()
        self.assertIn('if [[ "$ROUTINE_RELEASE" == 1 ]]; then', application)
        self.assertIn('aws elasticbeanstalk update-environment --environment-name "$ENV_NAME" --version-label "$VERSION" --region', application)
        self.assertIn('CURRENT_POLICY', application)
        self.assertIn('[[ "$CURRENT_POLICY" != Immutable ]]', application)
        self.assertIn('MAIL_AFTER', application)
        self.assertIn('WORKER_AFTER', application)
        self.assertIn('if [[ "$ROUTINE_RELEASE" == 0 ]]; then\n# Add origin DNS alias', application)
        self.assertIn('APPROVED_MIGRATION_DEPLOYMENT', application)
        hosting = (ROOT / "deploy/aws/fresh_hosting.sh").read_text()
        self.assertIn('existing CloudFront edge preserved', hosting)
        self.assertIn('elif [[ -z "$edge_state" || "$edge_state" == None ]]', hosting)
        config = (ROOT / ".github/workflows/deploy-production.yml").read_text()
        self.assertEqual(config.count('deploy/aws/release_continuity.py'), 2)
        self.assertIn('Retain public outage evidence', config)

    def test_start_here_is_a_real_website_page_not_a_placeholder(self):
        page_source = (ROOT / "views/editorial/start.php").read_text()
        for marker in ("Welcome to the journey.", "Explore My Thoughts",
                       "Explore Startup Journeys", "Choose a practical resource",
                       "Read Founder Stories"):
            self.assertIn(marker, page_source)
        live = (ROOT / "tests/live_site_sentinel.cjs").read_text()
        self.assertIn('Start Here is empty or missing its substantive public content', live)
        self.assertIn("'/start-here'", live)


if __name__ == "__main__":
    unittest.main(verbosity=2)
