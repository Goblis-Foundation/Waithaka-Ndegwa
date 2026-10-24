from pathlib import Path
import datetime as dt
import importlib.util
import unittest
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
if __name__=='__main__':unittest.main()
