from pathlib import Path
import datetime as dt
import importlib.util
import unittest
import ast
import re
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('migration_backup',ROOT/'deploy/aws/validate_migration_backup.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class BackupGuardTests(unittest.TestCase):
 def setUp(self):
  self.now=dt.datetime(2026,10,24,11,30,tzinfo=dt.timezone.utc)
  self.row={'arn':'arn:aws:rds:af-south-1:466401734428:db:fixture','host':'fixture.invalid','state':'available','encrypted':True,'retention':7,'latest':(self.now-dt.timedelta(minutes=5)).isoformat()}
 def test_recent_owned_encrypted_recovery_point(self):
  proof=module.validate([self.row],'fixture.invalid',self.now)
  self.assertEqual(proof['backup_retention_days'],7)
  self.assertEqual(proof['latest_restorable_time'],self.row['latest'])
 def test_wrong_target_or_missing_recent_backup_rejected(self):
  for change in [{'arn':'arn:aws:rds:us-east-1:111111111111:db:wrong'},{'retention':0},{'encrypted':False},{'state':'deleting'},{'latest':None},{'latest':(self.now-dt.timedelta(days=2)).isoformat()},{'latest':'2026-10-24T11:25:00'}]:
   with self.subTest(change=change),self.assertRaises(ValueError):module.validate([self.row|change],'fixture.invalid',self.now)
  with self.assertRaises(ValueError):module.validate([self.row],'another.invalid',self.now)
  with self.assertRaises(ValueError):module.validate([self.row,self.row],'fixture.invalid',self.now)
class HookPackagingTests(unittest.TestCase):
 def test_hook_commands_are_packaged_and_guarded(self):
  builder_path=ROOT/'bin/build-release.py'
  spec=importlib.util.spec_from_file_location('release_builder',builder_path)
  builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
  tree=ast.parse(builder_path.read_text())
  required=next(ast.literal_eval(node.value) for node in ast.walk(tree) if isinstance(node,ast.Assign) and any(isinstance(t,ast.Name) and t.id=='required' for t in node.targets))
  found=[]
  for hook in (ROOT/'.platform/hooks/postdeploy').glob('*.sh'):
   for command in re.findall(r'php\s+(bin/[A-Za-z0-9_-]+\.php)',hook.read_text()):
    found.append(command)
    self.assertTrue((ROOT/command).is_file(),command)
    self.assertTrue(builder.runtime_file(command),command)
    self.assertIn(command,required,command)
  self.assertIn('bin/founder-story-migrate.php',found)
  for private in ['.env','storage/private.json','bin/adhoc-admin.php']:
   self.assertFalse(builder.runtime_file(private),private)
class DiagnosticPrivacyTests(unittest.TestCase):
 def test_diagnostic_output_excludes_raw_private_logs(self):
  spec=importlib.util.spec_from_file_location('migration_diagnostics',ROOT/'deploy/aws/migration_log_diagnostics.py')
  diagnostic=importlib.util.module_from_spec(spec);spec.loader.exec_module(diagnostic)
  sample='password=private-fixture\\nAuthorization: private-fixture\\nFounder migration AWS read failed: AccessDeniedException; HTTP 400.\\nraw SQL and contact private-fixture'
  self.assertEqual(diagnostic.safe_lines(sample),['Founder migration AWS read failed: AccessDeniedException; HTTP 400.'])
if __name__=='__main__':unittest.main()
