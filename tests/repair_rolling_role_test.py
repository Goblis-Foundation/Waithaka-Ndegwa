#!/usr/bin/env python3
"""Offline policy and CLI regressions; AWS is replaced by an in-memory fixture."""
import ast
import contextlib
import copy
import importlib.util
import io
import json
from pathlib import Path
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('repair', ROOT/'deploy/aws/repair_rolling_role.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
BASE = {'Version':'2012-10-17','Statement':[
    {'Sid':'Existing','Effect':'Allow','Action':['s3:GetObject'],'Resource':'arn:aws:s3:::fixture/*'}]}
TARGET = {'Sid':m.SID,'Effect':'Allow',
          'Action':['autoscaling:TerminateInstanceInAutoScalingGroup'],
          'Resource':m.ASG,'Condition':{'StringEquals':{'aws:RequestedRegion':'af-south-1'}}}
READ_TARGET = {'Sid':'ReadOwnDeploymentPolicy','Effect':'Allow','Action':['iam:GetRolePolicy'],
               'Resource':'arn:aws:iam::466401734428:role/WaithakaGitHubDeployRole'}


def bootstrap_policy():
    # Evaluate only the policy expression, never bootstrap() or an AWS operation.
    source = ast.parse((ROOT/'deploy/aws/bootstrap-automation.py').read_text())
    assignment = next(n for n in ast.walk(source) if isinstance(n, ast.Assign)
                      and any(isinstance(t, ast.Name) and t.id == 'policy' for t in n.targets))
    def statement(sid, actions, resources, condition=None):
        result = dict(Sid=sid, Effect='Allow', Action=actions, Resource=resources)
        if condition: result['Condition'] = condition
        return result
    env = dict(ACCOUNT=m.ACCOUNT, REGION=m.REGION, statement=statement,
               regional={'StringEquals':{'aws:RequestedRegion':m.REGION}},
               db_arn='arn:aws:rds:af-south-1:466401734428:db:fixture',
               data={'StackId':'fixture-data-stack'}, elb_resources=['fixture-lb'],
               outputs={'ReleaseArtifacts':'fixture-release','PublicMedia':'fixture-public'},
               buckets=['fixture-public','fixture-private','fixture-release'],
               zone_arns=['fixture-zone'])
    return eval(compile(ast.Expression(assignment.value), '<bootstrap-policy>', 'eval'),
                {'__builtins__':{}}, env)


class RepairTests(unittest.TestCase):
    def run_cli(self, argv, current=None, account=m.ACCOUNT, deploy_role=False,
                managed=None, attached=False, fail='', concurrent=False):
        policy = copy.deepcopy(BASE if current is None else current)
        document = copy.deepcopy(managed)
        calls = []
        reads = 0
        self.paths = []
        def aws(*args):
            nonlocal document, attached, reads
            calls.append(args)
            if args[:2] == ('sts','get-caller-identity'):
                role = m.ROLE if deploy_role else 'AuthorizedAdminFixture'
                return {'Account':account,'Arn':f'arn:aws:sts::{account}:assumed-role/{role}/test'}
            if args[:2] == ('iam','get-role-policy'):
                return {'PolicyDocument':copy.deepcopy(policy)}
            if args[:2] == ('iam','get-policy'):
                self.assertEqual(args[-1], m.MANAGED_ARN)
                if fail == 'get-denied': raise m.AwsFailure('AccessDenied', 'Denied')
                if document is None: raise m.AwsFailure('NoSuchEntity', 'Not found')
                return {'Policy':{'Arn':m.MANAGED_ARN,'DefaultVersionId':'v1'}}
            if args[:2] == ('iam','get-policy-version'):
                self.assertEqual(args[args.index('--policy-arn')+1], m.MANAGED_ARN)
                reads += 1
                doc = copy.deepcopy(document)
                if concurrent and reads >= 2: doc['Statement'][0]['Resource'] = '*'
                return {'PolicyVersion':{'VersionId':'v1','IsDefaultVersion':fail != 'inactive', 'Document':doc}}
            if args[:2] == ('iam','list-attached-role-policies'):
                self.assertEqual(args[-1], m.ROLE)
                return {'AttachedPolicies':[{'PolicyArn':m.MANAGED_ARN}] if attached else [], 'IsTruncated':fail == 'truncated'}
            if args[:2] == ('iam','create-policy'):
                self.assertEqual(args[args.index('--policy-name')+1], m.MANAGED_NAME)
                file = Path(args[args.index('--policy-document')+1][7:])
                self.paths.append(file)
                self.assertEqual(file.stat().st_mode & 0o777, 0o600)
                if fail == 'create-denied': raise m.AwsFailure('AccessDenied', 'Denied')
                document = json.loads(file.read_text())
                if fail in ['race-same','race-conflict']:
                    if fail == 'race-conflict': document['Statement'][0]['Resource'] = '*'
                    raise m.AwsFailure('EntityAlreadyExists', 'Concurrent creator')
                return {'Policy':{'Arn':m.MANAGED_ARN}}
            if args[:2] == ('iam','attach-role-policy'):
                self.assertEqual(args, ('iam','attach-role-policy','--role-name',m.ROLE,'--policy-arn',m.MANAGED_ARN))
                if fail == 'attach-denied': raise m.AwsFailure('AccessDenied', 'Denied')
                if fail != 'readback-unattached': attached = True
                return {}
            self.fail('Unexpected or forbidden AWS operation: '+repr(args))
        self.calls = calls
        self.original = copy.deepcopy(policy)
        with patch.object(m, 'aws', side_effect=aws), contextlib.redirect_stdout(io.StringIO()) as out:
            m.main(argv)
        self.assertEqual(policy, self.original, 'Existing inline grants preserved byte-for-byte')
        return calls, out.getvalue()

    def test_exact_append_and_preservation(self):
        source = copy.deepcopy(BASE)
        result, changed = m.patch(source)
        self.assertTrue(changed)
        self.assertEqual(source, BASE)
        self.assertEqual(result['Statement'], BASE['Statement']+[TARGET,READ_TARGET])

    def test_idempotence(self):
        result, _ = m.patch(copy.deepcopy(BASE))
        self.assertEqual(m.patch(result), (result, False))

    def test_reject_invalid_conflicting_and_duplicate_statements(self):
        for policy in [
            {'Version':'2008-10-17','Statement':[]},
            {'Version':'2012-10-17','Statement':[None]},
            {'Version':'2012-10-17','Statement':[dict(TARGET, Resource='*')]},
            {'Version':'2012-10-17','Statement':[dict(TARGET, Action=['autoscaling:*'])]},
            {'Version':'2012-10-17','Statement':[TARGET,TARGET]},
            {'Version':'2012-10-17','Statement':[dict(READ_TARGET,Resource='*')]},
            {'Version':'2012-10-17','Statement':[dict(READ_TARGET,Action=['iam:PutRolePolicy'])]},
        ]:
            with self.subTest(policy=policy), self.assertRaises(ValueError): m.patch(policy)

    def test_bootstrap_delegates_repair_and_preserves_launch_templates(self):
        policy = bootstrap_policy()
        statements = {x['Sid']:x for x in policy['Statement']}
        self.assertNotIn(m.SID, statements)
        self.assertNotIn(m.READ_SID, statements)
        self.assertIn("repair_rolling_role(['--apply'])", (ROOT/'deploy/aws/bootstrap-automation.py').read_text())
        self.assertEqual(statements['DeploymentLaunchTemplates'], {
            'Sid':'DeploymentLaunchTemplates','Effect':'Allow',
            'Action':['ec2:CreateLaunchTemplate','ec2:CreateLaunchTemplateVersion',
                      'ec2:ModifyLaunchTemplate','ec2:DeleteLaunchTemplateVersions','ec2:CreateTags'],
            'Resource':'arn:aws:ec2:af-south-1:466401734428:launch-template/*',
            'Condition':{'StringEquals':{'aws:RequestedRegion':'af-south-1'}}})
        self.assertEqual(len(statements), len(policy['Statement']))
        for statement in policy['Statement']:
            if any(a.startswith('iam:') and a not in ['iam:GetRole','iam:GetRolePolicy','iam:GetInstanceProfile','iam:PassRole'] for a in statement['Action']):
                self.assertEqual(statement['Resource'], 'arn:aws:iam::466401734428:role/waithaka-eb-instance-role')

    def test_dry_run_prints_exact_permission_and_never_writes(self):
        calls, output = self.run_cli([])
        self.assertEqual(len(calls), 4)
        self.assertIn('DRY RUN ONLY', output)
        self.assertIn('autoscaling:TerminateInstanceInAutoScalingGroup', output)

    def test_wrong_account_fails_before_policy_read(self):
        with self.assertRaisesRegex(RuntimeError, 'Wrong AWS account'):
            self.run_cli(['--apply'], account='000000000000')
        self.assertEqual(len(self.calls), 1)

    def test_deployment_role_cannot_self_grant(self):
        with self.assertRaisesRegex(RuntimeError, 'cannot grant itself'):
            self.run_cli(['--apply'], deploy_role=True)
        self.assertEqual(len(self.calls), 1)

    def test_apply_uses_managed_policy_and_removes_temporary_file(self):
        calls, output = self.run_cli(['--apply'])
        self.assertEqual(sum(x[:2] == ('iam','create-policy') for x in calls), 1)
        self.assertEqual(sum(x[:2] == ('iam','attach-role-policy') for x in calls), 1)
        self.assertFalse(any(x[:2] == ('iam','put-role-policy') for x in calls))
        self.assertTrue(all(not path.exists() for path in self.paths))
        self.assertIn('Existing inline policies were not modified', output)

    def test_concurrent_managed_edit_is_not_attached(self):
        with self.assertRaisesRegex(RuntimeError, 'Conflicting managed policy'):
            self.run_cli(['--apply'], managed=m.managed_document(), concurrent=True)
        self.assertFalse(any(x[:2] == ('iam','attach-role-policy') for x in self.calls))

    def test_mismatched_attachment_readback_fails(self):
        with self.assertRaisesRegex(RuntimeError, 'absent or unattached'):
            self.run_cli(['--apply'], fail='readback-unattached')

    def test_verify_only_fails_if_missing_without_mutation(self):
        with self.assertRaisesRegex(RuntimeError, 'permission is missing'):
            self.run_cli(['--verify-only'])
        self.assertEqual(len(self.calls), 3)

    def test_verify_and_reapply_existing_statement_do_not_write(self):
        configured, _ = m.patch(copy.deepcopy(BASE))
        for argv in [['--verify-only'], ['--apply']]:
            with self.subTest(argv=argv):
                calls, _ = self.run_cli(argv, current=configured)
                self.assertEqual(len(calls), 2)

    def test_managed_policy_has_only_exact_required_grants(self):
        doc = m.managed_document()
        self.assertEqual(doc['Statement'], [TARGET, READ_TARGET,
            {'Sid':'VerifyRepairAttachment','Effect':'Allow','Action':['iam:ListAttachedRolePolicies'],
             'Resource':'arn:aws:iam::466401734428:role/WaithakaGitHubDeployRole'},
            {'Sid':'ReadRepairPolicy','Effect':'Allow','Action':['iam:GetPolicy','iam:GetPolicyVersion'],
             'Resource':'arn:aws:iam::466401734428:policy/WaithakaRollingDeploymentRepair'}])
        self.assertLess(len(json.dumps(doc,separators=(',',':'))), 6144)
        actions = [a for s in doc['Statement'] for a in s['Action']]
        self.assertFalse(any(a in actions for a in ['iam:PutRolePolicy','iam:AttachRolePolicy','iam:CreatePolicy','iam:CreatePolicyVersion']))

    def test_dry_run_missing_policy_never_mutates(self):
        calls, _ = self.run_cli([])
        self.assertFalse(any(x[1] in ['create-policy','attach-role-policy','put-role-policy'] for x in calls))

    def test_near_limit_inline_policy_is_preserved_without_any_rewrite(self):
        large = copy.deepcopy(BASE)
        large['Statement'][0]['Sid'] = 'x' * 9750
        self.assertLessEqual(len(json.dumps(large,separators=(',',':'))), 10240)
        patched, _ = m.patch(large)
        self.assertGreater(len(json.dumps(patched,separators=(',',':'))), 10240)
        calls, _ = self.run_cli(['--apply'], current=large)
        self.assertFalse(any(x[1] == 'put-role-policy' for x in calls))

    def test_existing_matching_policy_is_attached_without_new_version(self):
        calls, _ = self.run_cli(['--apply'], managed=m.managed_document())
        self.assertFalse(any(x[1] in ['create-policy','create-policy-version','put-role-policy'] for x in calls))
        self.assertEqual(sum(x[1]=='attach-role-policy' for x in calls), 1)

    def test_attached_managed_repair_is_idempotent_and_deploy_role_can_verify(self):
        for argv in [[], ['--apply'], ['--verify-only']]:
            calls, _ = self.run_cli(argv, managed=m.managed_document(), attached=True,
                                    deploy_role=argv==['--verify-only'])
            self.assertFalse(any(x[1] in ['create-policy','attach-role-policy','put-role-policy'] for x in calls))

    def test_unattached_policy_cannot_certify_deployment(self):
        with self.assertRaisesRegex(RuntimeError, 'absent or unattached'):
            self.run_cli(['--verify-only'], managed=m.managed_document())

    def test_conflicting_managed_policy_is_never_overwritten_or_attached(self):
        document=m.managed_document()
        document['Statement'][0]['Resource']='*'
        for argv in [[], ['--apply'], ['--verify-only']]:
            with self.subTest(argv=argv), self.assertRaisesRegex(RuntimeError,'Conflicting managed policy'):
                self.run_cli(argv, managed=document, attached=True)
            self.assertFalse(any(x[1] in ['create-policy','attach-role-policy','create-policy-version'] for x in self.calls))

    def test_create_race_reuses_only_an_exact_document(self):
        self.run_cli(['--apply'], fail='race-same')
        with self.assertRaisesRegex(RuntimeError, 'Conflicting managed policy'):
            self.run_cli(['--apply'], fail='race-conflict')
        self.assertFalse(any(x[1]=='attach-role-policy' for x in self.calls))
        self.assertTrue(all(not path.exists() for path in self.paths))

    def test_access_denial_is_not_mistaken_for_absent_policy(self):
        with self.assertRaises(m.AwsFailure): self.run_cli(['--apply'], fail='get-denied')
        self.assertFalse(any(x[1] in ['create-policy','attach-role-policy'] for x in self.calls))

    def test_create_failure_cleans_file_and_never_attaches(self):
        with self.assertRaises(m.AwsFailure): self.run_cli(['--apply'], fail='create-denied')
        self.assertTrue(all(not path.exists() for path in self.paths))
        self.assertFalse(any(x[1]=='attach-role-policy' for x in self.calls))

    def test_attachment_failure_preserves_inline_policy_and_can_be_retried(self):
        with self.assertRaises(m.AwsFailure): self.run_cli(['--apply'], fail='attach-denied')
        self.assertFalse(any(x[1] in ['put-role-policy','delete-role-policy','delete-policy'] for x in self.calls))
        self.run_cli(['--apply'], managed=m.managed_document())

    def test_incomplete_attachment_inventory_and_inactive_version_are_rejected(self):
        for failure in ['truncated','inactive']:
            with self.subTest(failure=failure), self.assertRaises(RuntimeError):
                self.run_cli(['--apply'], managed=m.managed_document(), fail=failure)
            self.assertFalse(any(x[1]=='attach-role-policy' for x in self.calls))

    def test_standard_ci_runner_includes_this_suite(self):
        self.assertIn("'repair_rolling_role_test.py'=>", (ROOT/'tests/run.php').read_text())

    def test_deploy_role_can_verify_but_cannot_change_iam(self):
        configured, _ = m.patch(copy.deepcopy(BASE))
        calls, _ = self.run_cli(['--verify-only'], current=configured, deploy_role=True)
        self.assertEqual([x[:2] for x in calls], [('sts','get-caller-identity'),('iam','get-role-policy')])

    def test_partial_existing_fix_adds_only_read_permission(self):
        partial = copy.deepcopy(BASE)
        partial['Statement'].append(TARGET)
        configured, changed = m.patch(partial)
        self.assertTrue(changed)
        self.assertEqual(configured['Statement'], partial['Statement']+[READ_TARGET])

    def test_production_gate_precedes_infrastructure_changes(self):
        workflow = (ROOT/'.github/workflows/deploy-production.yml').read_text()
        gate = workflow.index('repair_rolling_role.py --verify-only')
        for operation in ['fresh_dns.py recover','fresh_hosting.sh --after-registrar','google_workspace.sh --enable']:
            self.assertLess(gate, workflow.index(operation))
        self.assertNotIn('repair_rolling_role.py --apply', workflow)


if __name__ == '__main__':
    unittest.main()
