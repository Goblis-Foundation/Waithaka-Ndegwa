#!/usr/bin/env python3
"""Offline regression for recovery plans; all AWS calls are mocked and no SQL runs."""
import contextlib
import copy
import datetime as dt
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("recovery_installer", ROOT / "deploy/aws/install_cutover.py")
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class RecoveryPlanTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="waithaka-readiness-fixture-")
        self.directory = Path(self.temp.name)
        self.state_dir = self.directory / "state"
        self.state_dir.mkdir(mode=0o700)
        self.key = "local-fixture-original-key-not-for-production"
        key_file = self.directory / "original-key.json"
        m.write_json(key_file, {"APP_KEY": self.key})
        backup = self.directory / "backup.sql"
        backup.write_text("-- Synthetic offline fixture only\n")
        backup.chmod(0o600)
        bundle = self.directory / "application.zip"
        with zipfile.ZipFile(bundle, "w") as archive:
            for name in ("index.php", ".htaccess", "composer.lock", "vendor/autoload.php", ".platform/hooks/postdeploy/30_worker.sh", "storage/release-proofs/.htaccess"):
                archive.writestr(name, "fixture")
            for migration in (ROOT / "app/migrations").glob("*.sql"):
                archive.write(migration, "app/migrations/" + migration.name)
        paths = {}
        for setting in m.MEDIA_ROOTS:
            directory = self.directory / setting
            directory.mkdir()
            nested = directory / "2026/10"
            nested.mkdir(parents=True)
            (nested / ("a.jpg" if setting == "public_media_root" else "a.pdf" if setting == "private_proofs_root" else "a.mp4")).write_bytes(b"fixture-media-" + setting.encode())
            paths[setting] = str(directory)
        self.config = {
            "schema_version": 1, "deployment_id": "waithaka-fixture-staging", "account_id": "111122223333", "region": "eu-west-1", "profile": "fixture",
            "environment": "staging", "state_dir": str(self.state_dir), "secret_arn": "arn:aws:secretsmanager:eu-west-1:111122223333:secret:fixture-app-abcdef",
            "network": {"vpc_id": "vpc-11111111", "database_subnet_ids": ["subnet-11111111", "subnet-22222222"], "application_subnet_ids": ["subnet-33333333", "subnet-44444444"], "alb_subnet_ids": ["subnet-55555555", "subnet-66666666"], "application_security_group_id": "sg-11111111", "alb_security_group_id": "sg-22222222", "associate_public_ip": True, "trusted_proxy_cidrs": ["10.4.0.0/16"]},
            "roles": {"service_role": "fixture-service", "instance_profile": "fixture-profile", "instance_role": "fixture-role"},
            "database": {"name": "waithaka", "instance_class": "db.t4g.small", "engine_version": "8.4.5", "multi_az": False},
            "application": {"name": "waithaka-fixture", "environment_name": "waithaka-fixture-env", "platform_arn": "arn:aws:elasticbeanstalk:eu-west-1::platform/PHP 8.3 running on 64bit Amazon Linux 2023/4.7.0", "min_instances": 1, "max_instances": 2},
            "domains": {"canonical": "preview.waithakandegwa.com", "aliases": ["preview.waithakandegwa.com"], "origin": "origin-preview.waithakandegwa.com", "viewer_certificate_arn": "arn:aws:acm:us-east-1:111122223333:certificate/11111111-2222-3333-4444-555555555555", "regional_certificate_arn": "arn:aws:acm:eu-west-1:111122223333:certificate/11111111-2222-3333-4444-555555555555"},
            "artifacts": {"application_zip": str(bundle), "database_backup": str(backup), "original_key_file": str(key_file), **paths},
            "dns": {"provider": "external", "zones": {}}, "mail": {"transport": "disabled"},
        }
        self.data = {"DatabaseHost": "fixture.abcdefghijkl.eu-west-1.rds.amazonaws.com", "AdminSecretArn": "arn:aws:secretsmanager:eu-west-1:111122223333:secret:fixture-admin-abcdef", "PublicMedia": "fixture-public-bucket", "PrivateMedia": "fixture-private-bucket", "ReleaseArtifacts": "fixture-release-bucket"}
        self.evidence = m.artifact_evidence(self.config)
        self.state = {"schema_version": 1, "config_sha256": m.digest(self.config), "completed": {"foundation": self.data}}

    def tearDown(self):
        self.temp.cleanup()

    def accept(self, kind, config=None, state=None):
        config = config or self.config
        state = state or self.state
        value = m.receipt_template(config, kind, m.artifact_evidence(config), state)
        value["verified_by"] = "Local regression operator"
        value["verified_at"] = dt.datetime.now(dt.timezone.utc).isoformat()
        value["checks"] = {key: True for key in value["checks"]}
        if kind == "restore":
            value["source_counts"] = {"users": 1, "posts": 7, "contributions": 0, "media_releases": 0, "settings": 4}
            value["target_counts"] = dict(value["source_counts"])
        m.write_json(Path(config["state_dir"]) / (kind + "-receipt.json"), value, overwrite=True)
        return value

    def state_through_ready(self):
        state = copy.deepcopy(self.state)
        state["completed"].update({
            "media": {"media_sha256": self.evidence["media_sha256"]},
            "application": {"release_sha256": self.evidence["application_zip"]["sha256"], "environment_id": "e-fixture"},
            "edge": {"DistributionId": "EABC123", "DistributionArn": "arn:aws:cloudfront::111122223333:distribution/EABC123", "DistributionDomain": "dfixture.cloudfront.net"},
            "bind-media": {}, "ready": {"artifacts_sha256": m.digest(self.evidence)}})
        return state

    def production(self):
        config = copy.deepcopy(self.config)
        config["environment"] = "production"
        config["domains"]["canonical"] = "www.waithakandegwa.com"
        config["domains"]["aliases"] = ["www.waithakandegwa.com", "waithakandegwa.com", "www.waithakandegwa.co.ke", "waithakandegwa.co.ke"]
        return config

    def ses(self, config):
        config["mail"] = {"transport": "ses", "from_address": "hello@waithakandegwa.com", "from_name": "Waithaka", "postal_address": "Fixture postal address for offline test", "identity_arn": "arn:aws:ses:eu-west-1:111122223333:identity/waithakandegwa.com", "configuration_set": "fixture-feedback", "sns_topic_arn": "arn:aws:sns:eu-west-1:111122223333:fixture-feedback"}
        return config

    def test_complete_explicit_fixture_validates(self):
        self.assertEqual(m.validate_config(self.config), self.config)

    def test_placeholders_and_cross_account_inputs_rejected(self):
        for key, value in (("account_id", "123456789012"), ("region", "REPLACE_REGION"), ("profile", ""), ("secret_arn", "arn:aws:secretsmanager:eu-west-1:999988887777:secret:foreign-abcdef")):
            config = copy.deepcopy(self.config)
            config[key] = value
            with self.subTest(key=key), self.assertRaises(m.GuardError):
                m.validate_config(config)

    def test_missing_region_is_not_inferred(self):
        del self.config["region"]
        with patch.dict(os.environ, {"AWS_REGION": "eu-west-1"}), self.assertRaises(m.GuardError):
            m.validate_config(self.config)

    def test_trust_all_and_noncanonical_proxy_networks_rejected(self):
        for value in ("0.0.0.0/0", "::/0", "10.4.2.3/16", "10.4.0.0/no"):
            self.config["network"]["trusted_proxy_cidrs"] = [value]
            with self.subTest(value=value), self.assertRaises(m.GuardError):
                m.validate_config(self.config)

    def test_staging_cannot_take_production_alias(self):
        self.config["domains"]["aliases"].append("waithakandegwa.com")
        with self.assertRaises(m.GuardError):
            m.validate_config(self.config)

    def test_production_requires_all_four_domains(self):
        config = self.production()
        self.assertEqual(m.validate_config(config), config)
        config["domains"]["aliases"].pop()
        with self.assertRaises(m.GuardError):
            m.validate_config(config)

    def test_secret_values_cannot_be_put_in_plan_config(self):
        self.config["database"]["DB_PASSWORD"] = "secret"
        with self.assertRaises(m.GuardError):
            m.validate_config(self.config)

    def test_private_key_permissions_enforced(self):
        Path(self.config["artifacts"]["original_key_file"]).chmod(0o644)
        with self.assertRaises(m.GuardError):
            m.artifact_evidence(self.config)

    def test_inventory_preserves_all_three_prefixes_and_never_key_value(self):
        objects = self.evidence["media"]
        self.assertEqual({item["key"] for item in objects}, {"assets/uploads/2026/10/a.jpg", "release-proofs/2026/10/a.pdf", "media-library/2026/10/a.mp4"})
        self.assertEqual([item["visibility"] for item in objects].count("private"), 2)
        self.assertNotIn(self.key, json.dumps(self.evidence))
        self.assertNotIn("APP_KEY", json.dumps(self.evidence))

    def test_media_symlink_rejected(self):
        Path(self.config["artifacts"]["public_media_root"], "linked.jpg").symlink_to(self.config["artifacts"]["database_backup"])
        with self.assertRaises(m.GuardError):
            m.inventory(self.config)

    def test_unrecognized_media_rejected(self):
        Path(self.config["artifacts"]["private_library_root"], "payload.php").write_text("fixture")
        with self.assertRaises(m.GuardError):
            m.inventory(self.config)

    def test_archive_rejects_private_data_and_mismatched_migration(self):
        with zipfile.ZipFile(self.config["artifacts"]["application_zip"], "a") as archive:
            archive.writestr("storage/media-library/2026/10/private.mp4", "private")
        with self.assertRaises(m.GuardError):
            m.artifact_evidence(self.config)

    def test_offline_plan_does_not_call_aws(self):
        empty = {"schema_version": 1, "config_sha256": m.digest(self.config), "completed": {}}
        self.accept("infrastructure", state=empty)
        with patch.object(m.Aws, "call", side_effect=AssertionError("No AWS in offline plan")):
            plan = m.build_plan(self.config, "foundation", empty)
        self.assertEqual(plan["actions"][0]["parameters"]["MediaDistributionArn"], "")
        self.assertNotIn(self.key, json.dumps(plan))
        self.assertEqual(plan["sha256"], m.digest({k: v for k, v in plan.items() if k != "sha256"}))

    def test_default_receipt_is_never_accepted(self):
        receipt = m.receipt_template(self.config, "restore", self.evidence, self.state)
        m.write_json(self.state_dir / "restore-receipt.json", receipt)
        with self.assertRaises(m.GuardError):
            m.receipt(self.config, "restore", self.evidence, self.state)

    def test_restore_receipt_rejects_empty_writer_and_wrong_target(self):
        for change in ("empty_users", "wrong_host", "changed_count"):
            value = self.accept("restore")
            if change == "empty_users":
                value["source_counts"]["users"] = value["target_counts"]["users"] = 0
            elif change == "wrong_host":
                value["database_host"] = "other-host"
            else:
                value["target_counts"]["posts"] += 1
            m.write_json(self.state_dir / "restore-receipt.json", value, overwrite=True)
            with self.subTest(change=change), self.assertRaises(m.GuardError):
                m.receipt(self.config, "restore", self.evidence, self.state)

    def test_restore_receipt_is_bound_to_actual_backup(self):
        self.accept("restore")
        Path(self.config["artifacts"]["database_backup"]).write_text("-- changed backup\n")
        with self.assertRaises(m.GuardError):
            m.receipt(self.config, "restore", m.artifact_evidence(self.config), self.state)

    def test_receipt_age_and_timestamp_are_checked(self):
        value = self.accept("restore")
        value["verified_at"] = (dt.datetime.now(dt.timezone.utc) - dt.timedelta(days=8)).isoformat()
        m.write_json(self.state_dir / "restore-receipt.json", value, overwrite=True)
        with self.assertRaises(m.GuardError):
            m.receipt(self.config, "restore", self.evidence, self.state)

    def test_missing_prior_phase_prevents_concrete_plan(self):
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, "edge", self.state)

    def test_app_options_pause_work_and_keep_exact_hosts(self):
        options = m.app_options(self.config, self.data)
        env = {o["OptionName"]: o["Value"] for o in options if o["Namespace"] == "aws:elasticbeanstalk:application:environment"}
        self.assertEqual((env["APP_READY"], env["WORKER_ENABLED"], env["MAIL_TRANSPORT"]), ("0", "0", "disabled"))
        self.assertEqual(env["APP_ALLOWED_HOSTS"], "preview.waithakandegwa.com")
        self.assertEqual(env["MEDIA_PUBLIC_URL"], env["APP_URL"])
        self.assertNotIn("REPLACE", json.dumps(options))

    def test_runtime_policy_keeps_private_prefixes_and_version_reads(self):
        policy = m.runtime_policy(self.config, self.data)
        self.assertNotIn("ses:", json.dumps(policy))
        self.assertNotIn("sns:", json.dumps(policy))
        private = next(row for row in policy["Statement"] if row["Sid"] == "PrivateMediaOnly")
        self.assertIn("s3:GetObjectVersion", private["Action"])
        self.assertEqual(set(private["Resource"]), {"arn:aws:s3:::fixture-private-bucket/release-proofs/*", "arn:aws:s3:::fixture-private-bucket/media-library/*"})

    def deployed_configuration(self, options, status="deployed"):
        return {"ApplicationName": self.config["application"]["name"], "EnvironmentName": self.config["application"]["environment_name"], "DeploymentStatus": status, "OptionSettings": options}

    def test_adopted_environment_must_actually_be_paused(self):
        expected = m.app_options(self.config, self.data)
        drifted = copy.deepcopy(expected)
        for option in drifted:
            if option["OptionName"] == "WORKER_ENABLED":
                option["Value"] = "1"
        aws = m.Aws(self.config)
        with patch.object(aws, "call", return_value={"ConfigurationSettings": [self.deployed_configuration(drifted)]}):
            with self.assertRaises(m.GuardError):
                m.check_environment_options(self.config, aws, expected)

    def test_failed_update_draft_cannot_substitute_for_deployed_settings(self):
        old = m.app_options(self.config, self.data)
        requested = m.environment_expected_update(self.config, self.data, {"APP_READY": "1", "WORKER_ENABLED": "0", "MAIL_TRANSPORT": "disabled"})
        aws = m.Aws(self.config)
        response = {"ConfigurationSettings": [self.deployed_configuration(old), self.deployed_configuration(requested, "failed")]}
        with patch.object(aws, "call", return_value=response), self.assertRaises(m.GuardError):
            m.check_environment_options(self.config, aws, requested)

    def test_pending_configuration_is_not_a_completed_phase(self):
        expected = m.app_options(self.config, self.data)
        aws = m.Aws(self.config)
        response = {"ConfigurationSettings": [self.deployed_configuration(expected), self.deployed_configuration(expected, "pending")]}
        with patch.object(aws, "call", return_value=response), self.assertRaises(m.GuardError):
            m.check_environment_options(self.config, aws, expected)

    def test_environment_readback_covers_database_secret_and_canonical(self):
        expected = m.app_options(self.config, self.data)
        for name in ("DB_HOST", "APP_KEY", "APP_URL", "MEDIA_PRIVATE_BUCKET"):
            changed = copy.deepcopy(expected)
            for option in changed:
                if option["OptionName"] == name:
                    option["Value"] = "unreviewed-sensitive-setting"
            aws = m.Aws(self.config)
            with self.subTest(name=name), patch.object(aws, "call", return_value={"ConfigurationSettings": [self.deployed_configuration(changed)]}):
                with self.assertRaises(m.GuardError) as error:
                    m.check_environment_options(self.config, aws, expected)
                self.assertNotIn("unreviewed-sensitive-setting", str(error.exception))

    def test_deployed_configuration_matching_plan_is_accepted(self):
        expected = m.app_options(self.config, self.data)
        aws = m.Aws(self.config)
        with patch.object(aws, "call", return_value={"ConfigurationSettings": [self.deployed_configuration(expected)]}):
            m.check_environment_options(self.config, aws, expected)

    def test_cloudformation_removal_replacement_and_unrelated_change_blocked(self):
        for resource in ({"Action": "Remove"}, {"Action": "Modify", "Replacement": "Conditional"}, {"Action": "Modify", "Replacement": "True"}, {"Action": "Modify", "LogicalResourceId": "Database", "Replacement": "False"}):
            with self.subTest(resource=resource), self.assertRaises(m.GuardError):
                m.validate_change_set([{"ResourceChange": resource}], update=True)
        m.validate_change_set([{"ResourceChange": {"Action": "Modify", "Replacement": "False", "LogicalResourceId": "PublicMediaTlsPolicy"}}], update=True)

    def apply_binding_with_observations(self, observations):
        """Run the actual orchestration with delayed AWS reads, without network/sleep."""
        plan = {"sha256": "a" * 64, "phase": "bind-media"}
        name = self.config["deployment_id"] + "-data"
        prefix = "arn:aws:cloudformation:" + self.config["region"] + ":" + self.config["account_id"] + ":"
        stack_id = prefix + "stack/" + name + "/11111111-2222-3333-4444-555555555555"
        change_id = prefix + "changeSet/wk-" + "a" * 24 + "/11111111-2222-3333-4444-555555555555"
        parameters = m.data_parameters(self.config, "arn:aws:cloudfront::111122223333:distribution/EABC123")
        old_parameters = m.data_parameters(self.config)
        action = {"kind": "cloudformation-policy-only-update", "stack": name, "template": "data-stack.json", "parameters": parameters}
        cursor = {"executed": False, "observations": 0}
        changes = [{"ResourceChange": {"Action": "Modify", "LogicalResourceId": "PublicMediaTlsPolicy", "Replacement": "False"}}]

        def stack_row(status, fresh):
            return {"StackId": stack_id, "StackStatus": status, "Parameters": [{"ParameterKey": key, "ParameterValue": value} for key, value in (parameters if fresh else old_parameters).items()],
                    "Tags": [{"Key": "WaithakaDeployment", "Value": self.config["deployment_id"]}], "Outputs": [{"OutputKey": "PublicMedia", "OutputValue": "verified-bucket"}]}

        def response(service, operation, *arguments, **kwargs):
            self.assertEqual(service, "cloudformation")
            if operation == "create-change-set":
                return {"Id": change_id, "StackId": stack_id}
            if operation == "describe-change-set":
                self.assertEqual(arguments[arguments.index("--change-set-name") + 1], change_id)
                self.assertEqual(arguments[arguments.index("--stack-name") + 1], stack_id)
                execution = observations[cursor["observations"]][0] if cursor["executed"] else "AVAILABLE"
                return {"ChangeSetId": change_id, "StackId": stack_id, "Status": "CREATE_COMPLETE", "ExecutionStatus": execution, "Changes": changes}
            if operation == "execute-change-set":
                self.assertEqual(arguments[arguments.index("--change-set-name") + 1], change_id)
                cursor["executed"] = True
                return {}
            if operation == "describe-stacks":
                if not cursor["executed"]:
                    return {"Stacks": [stack_row("UPDATE_COMPLETE", False)]}
                _, status, fresh = observations[cursor["observations"]]
                cursor["observations"] += 1
                return {"Stacks": [stack_row(status, fresh)]}
            raise AssertionError("Unexpected AWS operation in offline fixture: " + operation)

        aws = m.Aws(self.config)
        with patch.object(aws, "call", side_effect=response), patch.object(m.time, "sleep") as sleep, contextlib.redirect_stdout(io.StringIO()):
            result = m.apply_stack(self.config, aws, action, plan)
        evidence = m.read_json(self.state_dir / ("bind-media-changeset-" + "a" * 12 + ".json"))
        return result, cursor["observations"], sleep.call_count, evidence

    def test_stack_waiter_requires_this_execution_and_final_parameters(self):
        result, reads, sleeps, evidence = self.apply_binding_with_observations([
            ("AVAILABLE", "UPDATE_COMPLETE", False),
            ("EXECUTE_IN_PROGRESS", "UPDATE_COMPLETE", False),
            ("EXECUTE_COMPLETE", "UPDATE_IN_PROGRESS", True),
            ("EXECUTE_COMPLETE", "UPDATE_COMPLETE", False),
            ("EXECUTE_COMPLETE", "UPDATE_COMPLETE", True),
        ])
        self.assertEqual(reads, 5)
        self.assertEqual(sleeps, 4)
        self.assertEqual(result, {"PublicMedia": "verified-bucket"})
        self.assertEqual(evidence["execution_status"], "EXECUTE_COMPLETE")
        self.assertTrue(evidence["parameters_verified"])

    def test_old_create_complete_cannot_satisfy_an_update(self):
        _, reads, sleeps, _ = self.apply_binding_with_observations([
            ("EXECUTE_IN_PROGRESS", "CREATE_COMPLETE", False),
            ("EXECUTE_COMPLETE", "CREATE_COMPLETE", True),
            ("EXECUTE_COMPLETE", "UPDATE_COMPLETE", True),
        ])
        self.assertEqual(reads, 3)
        self.assertEqual(sleeps, 2)

    def test_failed_or_obsolete_change_set_stops_without_success_evidence(self):
        for execution in ("EXECUTE_FAILED", "OBSOLETE"):
            with self.subTest(execution=execution), self.assertRaises(m.GuardError):
                self.apply_binding_with_observations([(execution, "UPDATE_COMPLETE", True)])
            evidence = m.read_json(self.state_dir / ("bind-media-changeset-" + "a" * 12 + ".json"))
            self.assertNotIn("parameters_verified", evidence)
            self.assertNotIn("execution_status", evidence)

    def test_rollback_cannot_satisfy_completed_change_set(self):
        with self.assertRaises(m.GuardError):
            self.apply_binding_with_observations([("EXECUTE_COMPLETE", "UPDATE_ROLLBACK_COMPLETE", True)])

    def test_staging_worker_activation_is_forbidden_even_with_receipts(self):
        state = self.state_through_ready()
        state["completed"]["cutover"] = {}
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, "worker", state)

    def test_cutover_requires_readiness_for_exact_final_data(self):
        state = self.state_through_ready()
        self.accept("acceptance", state=state)
        self.accept("traffic", state=state)
        Path(self.config["artifacts"]["database_backup"]).write_text("-- final delta changed\n")
        with self.assertRaises(m.GuardError):
            m.build_plan(self.config, "cutover", state)

    def test_mail_setup_stays_paused_and_only_exact_topic_is_allowed(self):
        config = self.ses(self.production())
        state = self.state_through_ready()
        plan = m.build_plan(config, "mail-setup", state)
        values = plan["actions"][-1]["values"]
        self.assertEqual((values["WORKER_ENABLED"], values["MAIL_TRANSPORT"]), ("0", "disabled"))
        self.assertIn("MAIL_FROM", values)
        self.assertIn("MAIL_POSTAL_ADDRESS", values)
        policy = plan["actions"][0]["policy"]
        topic = next(s for s in policy["Statement"] if s["Sid"] == "AcceptedFeedbackTopic")
        self.assertEqual(topic["Resource"], config["mail"]["sns_topic_arn"])
        self.assertEqual(topic["Action"], ["sns:ConfirmSubscription"])

    def test_mail_activation_needs_setup_and_every_evidence_gate(self):
        config = self.ses(self.production())
        state = self.state_through_ready()
        state["completed"]["worker"] = {}
        state["completed"]["cutover"] = {}
        for kind in ("traffic", "worker", "mail"):
            self.accept(kind, config, state)
        with self.assertRaises(m.GuardError):
            m.build_plan(config, "mail", state)
        state["completed"]["mail-setup"] = {}
        plan = m.build_plan(config, "mail", state)
        self.assertEqual(plan["actions"][-1]["values"]["MAIL_TRANSPORT"], "ses")

    def test_route53_plan_modifies_only_target_address_records(self):
        config = self.production()
        config["dns"] = {"provider": "route53", "zones": {h: "ZFIXTURE123" for h in config["domains"]["aliases"]}}
        state = self.state_through_ready()
        records = {h: [] for h in config["domains"]["aliases"]}
        records["www.waithakandegwa.com"] = [{"Name": "www.waithakandegwa.com.", "Type": "CNAME", "TTL": 300, "ResourceRecords": [{"Value": "old-host.waithakandegwa.com"}]}]
        batches = m.dns_changes(config, state, {"records": records})
        changes = batches[0]["change_batch"]["Changes"]
        self.assertEqual(changes[0]["Action"], "DELETE")
        self.assertEqual(changes[0]["ResourceRecordSet"]["Type"], "CNAME")
        self.assertEqual(len(changes), 9)
        self.assertTrue(all(c["ResourceRecordSet"]["Type"] in {"A", "AAAA", "CNAME"} for c in changes))

    def test_special_dns_routing_is_not_silently_replaced(self):
        config = copy.deepcopy(self.config)
        host = config["domains"]["canonical"]
        config["dns"] = {"provider": "route53", "zones": {host: "ZFIXTURE123"}}
        with self.assertRaises(m.GuardError):
            m.dns_changes(config, self.state_through_ready(), {"records": {host: [{"Name": host + ".", "Type": "A", "SetIdentifier": "weighted", "Weight": 10}]}})

    def test_aws_identity_mismatch_stops_before_other_checks(self):
        with patch.object(m.Aws, "call", return_value={"Account": "999988887777", "Arn": "arn:aws:iam::999988887777:root"}) as call:
            with self.assertRaises(m.GuardError):
                m.preflight(self.config, m.Aws(self.config))
        self.assertEqual(call.call_count, 1)

    def test_wrong_original_key_is_rejected_without_exposing_either_value(self):
        aws = m.Aws(self.config)
        secret = {"APP_KEY": "different-key-not-for-production-use", "DB_USERNAME": "fixture_runtime", "DB_PASSWORD": "private-fixture-password"}
        with patch.object(aws, "call", return_value={"SecretString": json.dumps(secret)}):
            with self.assertRaises(m.GuardError) as error:
                m.compare_key(self.config, aws)
        self.assertNotIn(self.key, str(error.exception))
        self.assertNotIn(secret["APP_KEY"], str(error.exception))

    def test_aws_cli_uses_explicit_boundary_and_suppresses_raw_errors(self):
        completed = subprocess.CompletedProcess([], 1, "", "An error occurred (AccessDeniedException) when calling: PRIVATE_SECRET_MUST_NOT_LEAK")
        with patch("shutil.which", return_value="/fixture/aws"), patch("subprocess.run", return_value=completed) as run, patch.dict(os.environ, {"AWS_ENDPOINT_URL": "https://untrusted.invalid", "AWS_ENDPOINT_URL_STS": "https://untrusted.invalid"}):
            with self.assertRaises(m.AwsError) as error:
                m.Aws(self.config).call("sts", "get-caller-identity")
        command = run.call_args.args[0]
        env = run.call_args.kwargs["env"]
        self.assertIn("--profile", command)
        self.assertIn("--region", command)
        self.assertNotIn("AWS_ENDPOINT_URL", env)
        self.assertNotIn("AWS_ENDPOINT_URL_STS", env)
        self.assertEqual(env["AWS_IGNORE_CONFIGURED_ENDPOINT_URLS"], "true")
        self.assertNotIn("PRIVATE_SECRET", str(error.exception))

    def test_mismatched_existing_media_is_never_overwritten(self):
        aws = m.Aws(self.config)
        with patch.object(aws, "call", return_value={"ContentLength": 10, "ChecksumSHA256": "bad", "VersionId": "v1"}) as call:
            with self.assertRaises(m.GuardError):
                m.put_verified(self.config, aws, "fixture-private-bucket", self.evidence["media"][0])
        self.assertEqual(call.call_count, 1)
        self.assertEqual(call.call_args.args[1], "head-object")

    def test_media_upload_is_conditional_and_verified(self):
        item = self.evidence["media"][0]
        checksum = m.base64.b64encode(bytes.fromhex(item["sha256"])).decode()
        aws = m.Aws(self.config)
        with patch.object(aws, "call", side_effect=[m.AwsError("s3api", "head-object", "404"), {}, {"ContentLength": item["bytes"], "ChecksumSHA256": checksum, "VersionId": "immutable-version"}]) as call:
            result = m.put_verified(self.config, aws, "fixture-public-bucket", item)
        upload = call.call_args_list[1].args
        self.assertIn("--if-none-match", upload)
        self.assertIn("--expected-bucket-owner", upload)
        self.assertIn("--checksum-sha256", upload)
        self.assertEqual(result["version_id"], "immutable-version")

    def test_modified_plan_is_rejected_before_any_aws_call(self):
        empty = {"schema_version": 1, "config_sha256": m.digest(self.config), "completed": {}}
        self.accept("infrastructure", state=empty)
        plan = m.build_plan(self.config, "foundation", empty)
        good_hash = plan["sha256"]
        plan["actions"][0]["parameters"]["DatabaseName"] = "different"
        path = self.directory / "tampered.json"
        m.write_json(path, plan)
        with patch.object(m.Aws, "call", side_effect=AssertionError("AWS must not be called")), self.assertRaises(m.GuardError):
            m.apply_plan(path, good_hash)

    def test_plan_output_is_private_and_not_overwritten(self):
        path = self.directory / "plan.json"
        m.write_json(path, {"safe": True})
        self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
        with self.assertRaises(m.GuardError):
            m.write_json(path, {"replacement": True})


if __name__ == "__main__":
    unittest.main(verbosity=2)
