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
                concurrent=False, mismatch=False):
        policy = copy.deepcopy(BASE if current is None else current)
        calls = []
        reads = 0
        def aws(*args):
            nonlocal policy, reads
            calls.append(args)
            if args[:2] == ('sts','get-caller-identity'):
                role = m.ROLE if deploy_role else 'AuthorizedAdminFixture'
                return {'Account':account,'Arn':f'arn:aws:sts::{account}:assumed-role/{role}/test'}
            if args[:2] == ('iam','get-role-policy'):
                reads += 1
                result = copy.deepcopy(policy)
                if concurrent and reads == 2: result['Statement'].append({'Sid':'ConcurrentFixture'})
                if mismatch and reads == 3: result = copy.deepcopy(BASE)
                return {'PolicyDocument':result}
            if args[:2] == ('iam','put-role-policy'):
                self.assertEqual(args[args.index('--role-name')+1], m.ROLE)
                self.assertEqual(args[args.index('--policy-name')+1], m.POLICY)
                file = Path(args[args.index('--policy-document')+1][7:])
                self.assertEqual(file.stat().st_mode & 0o777, 0o600)
                policy = json.loads(file.read_text())
                return {}
            self.fail('Unexpected AWS operation: '+repr(args))
        self.calls = calls
        with patch.object(m, 'aws', side_effect=aws), contextlib.redirect_stdout(io.StringIO()) as out:
            m.main(argv)
        return calls, out.getvalue()

    def test_exact_append_and_preservation(self):
        source = copy.deepcopy(BASE)
        result, changed = m.patch(source)
        self.assertTrue(changed)
        self.assertEqual(source, BASE)
        self.assertEqual(result['Statement'], BASE['Statement']+[TARGET])

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
        ]:
            with self.subTest(policy=policy), self.assertRaises(ValueError): m.patch(policy)

    def test_actual_bootstrap_contains_permission_and_preserves_launch_templates(self):
        policy = bootstrap_policy()
        statements = {x['Sid']:x for x in policy['Statement']}
        self.assertEqual(statements.get(m.SID), TARGET)
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
        self.assertEqual(len(calls), 2)
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

    def test_apply_reads_back_and_removes_temporary_file(self):
        calls, _ = self.run_cli(['--apply'])
        writes = [x for x in calls if x[:2] == ('iam','put-role-policy')]
        self.assertEqual(len(writes), 1)
        self.assertFalse(Path(writes[0][-1][7:]).exists())
        self.assertEqual(sum(x[:2] == ('iam','get-role-policy') for x in calls), 3)

    def test_concurrent_edit_is_not_overwritten(self):
        with self.assertRaisesRegex(RuntimeError, 'Policy changed'):
            self.run_cli(['--apply'], concurrent=True)
        self.assertFalse(any(x[:2] == ('iam','put-role-policy') for x in self.calls))

    def test_mismatched_readback_fails(self):
        with self.assertRaisesRegex(RuntimeError, 'read-back did not match'):
            self.run_cli(['--apply'], mismatch=True)

    def test_verify_only_fails_if_missing_without_mutation(self):
        with self.assertRaisesRegex(RuntimeError, 'permission is missing'):
            self.run_cli(['--verify-only'])
        self.assertEqual(len(self.calls), 2)

    def test_verify_and_reapply_existing_statement_do_not_write(self):
        configured, _ = m.patch(copy.deepcopy(BASE))
        for argv in [['--verify-only'], ['--apply']]:
            with self.subTest(argv=argv):
                calls, _ = self.run_cli(argv, current=configured)
                self.assertEqual(len(calls), 2)

    def test_standard_ci_runner_includes_this_suite(self):
        self.assertIn("'repair_rolling_role_test.py'=>", (ROOT/'tests/run.php').read_text())


if __name__ == '__main__':
    unittest.main()
