"""Exercise the actual migration cleanup function with a fake AWS command; no network."""
from pathlib import Path
import json, os, subprocess, tempfile, unittest
ROOT=Path(__file__).resolve().parents[1]
SCRIPT=(ROOT/'deploy/aws/fresh_application.sh').read_text()
FUNCTION=SCRIPT[SCRIPT.index('cleanup_google_migration_access(){'):SCRIPT.index('if [[ "$GOOGLE_MIGRATION_REQUIRED" == 1 ]]; then\n  trap')]
class MigrationCleanupTests(unittest.TestCase):
    def invoke(self, enabled, status=0, article=False):
        with tempfile.TemporaryDirectory() as d:
            output=Path(d)/'policy.json'
            retired=Path(d)/'retired.json'
            program='''set -u
GOOGLE_MIGRATION_REQUIRED="$ENABLED"
RUNTIME_SECRET=fixture-runtime-secret
PUBLIC_BUCKET=fixture-public
PRIVATE_BUCKET=fixture-private
INSTANCE_ROLE=fixture-role
ARTICLE_AUTHORITY_OPEN="$ARTICLE_MODE"
ARTICLE_MIGRATION_HASH=fixture-hash
ARTICLE_AUTHORITY_KEY=release-proofs/schema-migrations/fixture.json
REGION=af-south-1
aws(){
  while [[ "$#" -gt 0 ]]; do
    if [[ "$1" == --policy-document ]]; then cp "${2#file://}" "$CAPTURE"; break; fi
    if [[ "$1" == --body ]]; then cp "$2" "$RETIRED"; break; fi
    shift
  done
  return "$AWS_STATUS"
}
'''+FUNCTION+'''cleanup_google_migration_access
'''
            env=os.environ|{'ENABLED':str(enabled),'AWS_STATUS':str(status),'CAPTURE':str(output),'RETIRED':str(retired),'ARTICLE_MODE':'1' if article else '0'}
            result=subprocess.run(['bash','-c',program],env=env,capture_output=True,text=True)
            self.retired=json.loads(retired.read_text()) if retired.exists() else None
            return result.returncode,json.loads(output.read_text()) if output.exists() else None
    def test_cleanup_keeps_only_runtime_secret_and_owned_media(self):
        status,policy=self.invoke(1)
        self.assertEqual(status,0)
        self.assertEqual(policy['Statement'][0]['Resource'],'fixture-runtime-secret')
        encoded=json.dumps(policy)
        self.assertNotIn('admin',encoded.lower())
        self.assertNotIn('secretsmanager:*',encoded)
        self.assertEqual(len(policy['Statement']),3)
        self.assertIn('fixture-private/media-library/*',encoded)
    def test_article_authority_is_retired_without_leaving_an_admin_identifier(self):
        status,_=self.invoke(1,article=True)
        self.assertEqual(status,0)
        self.assertEqual(self.retired,{'schema_version':1,'migration':'210_article_groups.sql','sha256':'fixture-hash','state':'retired'})
    def test_cleanup_failure_is_not_reported_as_success(self):
        status,_=self.invoke(1,13)
        self.assertEqual(status,13)
    def test_normal_release_does_not_reopen_access(self):
        status,policy=self.invoke(0)
        self.assertEqual(status,0)
        self.assertIsNone(policy)
class ArticleMigrationPlanTests(unittest.TestCase):
    def plan(self, receipt=False, approved=True, bootstrap=0, legacy=0, present=True):
        import hashlib
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder);(root/'app/migrations').mkdir(parents=True);(root/'bin').mkdir()
            content=(ROOT/'app/migrations/210_article_groups.sql').read_bytes()
            (root/'app/migrations/210_article_groups.sql').write_bytes(content)
            if present:(root/'bin/article-groups-migrate.php').write_text('<?php')
            digest=hashlib.sha256(content).hexdigest()
            block=SCRIPT[SCRIPT.index('# Article groups use'):SCRIPT.index('if [[ "$FOUNDER_MIGRATION_REQUIRED" == 1 ||')]
            program='set -eu\naws(){ return "$RECEIPT_STATUS"; }\n'+block+"""printf '%s %s %s %s' "$ARTICLE_MIGRATION_REQUIRED" "$GOOGLE_MIGRATION_REQUIRED" "$ARTICLE_ONLY_RELEASE" "$ARTICLE_MIGRATION_RECEIPT"
"""
            env=os.environ|{'SOURCE_DIR':str(root),'RELEASE_BUCKET':'fixture-only','REGION':'af-south-1','GOOGLE_MIGRATION_REQUIRED':str(legacy),'BOOTSTRAP_REQUIRED':str(bootstrap),'APPROVED_ARTICLE_GROUP_MIGRATION_SHA256':digest if approved else 'unapproved','RECEIPT_STATUS':'0' if receipt else '1'}
            result=subprocess.run(['bash','-c',program],env=env,text=True,capture_output=True)
            self.assertEqual(result.returncode,0,result.stderr)
            return result.stdout.split(),digest
    def test_new_exact_approved_article_migration_opens_only_the_existing_temporary_path(self):
        fields,digest=self.plan()
        self.assertEqual(fields[:3],['1','1','1'])
        self.assertEqual(fields[3],'schema-migrations/210-'+digest+'.json')
        self.assertIn(digest,(ROOT/'.github/workflows/deploy-production.yml').read_text())
    def test_recorded_receipt_keeps_future_code_releases_routine(self):
        fields,_=self.plan(receipt=True)
        self.assertEqual(fields[:3],['0','0','0'])
    def test_other_ddl_bootstrap_or_unapproved_hash_cannot_use_article_only_authority(self):
        for changes in [{'approved':False},{'bootstrap':1},{'legacy':1}]:
            with self.subTest(changes=changes):
                fields,_=self.plan(**changes)
                self.assertEqual(fields[:3],['1','1','0'])
    def test_absent_article_command_cannot_request_migration_access(self):
        fields,_=self.plan(present=False)
        self.assertEqual(fields[:3],['0','0','0'])
    def test_approved_article_migration_uses_the_unchanged_code_only_release_path(self):
        block=SCRIPT[SCRIPT.index('ROUTINE_RELEASE=0\n'):SCRIPT.index('environment_snapshot(){')]
        for article,expected in [('1','1'),('0','0')]:
            result=subprocess.run(['bash','-eu','-c',block+'printf %s "$ROUTINE_RELEASE"'],env=os.environ|{'BOOTSTRAP_REQUIRED':'0','GOOGLE_MIGRATION_REQUIRED':'1','ARTICLE_ONLY_RELEASE':article},text=True,capture_output=True)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertEqual(result.stdout,expected)
    def test_private_authority_metadata_has_no_credentials_and_is_short_lived(self):
        import time
        code=SCRIPT.split("<<'PYAUTHORITY'\n",1)[1].split('\nPYAUTHORITY',1)[0]
        with tempfile.TemporaryDirectory() as folder:
            target=Path(folder)/'authority.json'
            result=subprocess.run(['python3','-c',code,str(target),'f'*64,'fixture-admin-identifier','fixture.invalid','fixture_database','r17-0123456789ab'],text=True,capture_output=True)
            self.assertEqual(result.returncode,0,result.stderr)
            record=json.loads(target.read_text())
            self.assertEqual(record['sha256'],'f'*64)
            self.assertEqual(record['db_host'],'fixture.invalid')
            self.assertEqual(record['db_name'],'fixture_database')
            self.assertEqual(record['state'],'open')
            self.assertLessEqual(abs(record['expires_at']-time.time()-3600),5)
            self.assertFalse({'password','username','token','SecretString'}&record.keys())
    def test_article_recovery_check_precedes_temporary_role_access(self):
        guard='if [[ "$FOUNDER_MIGRATION_REQUIRED" == 1 || "$TRASH_MIGRATION_REQUIRED" == 1 || "$AUDIT_MIGRATION_REQUIRED" == 1 || "$ARTICLE_MIGRATION_REQUIRED" == 1 ]]; then'
        self.assertLess(SCRIPT.index(guard),SCRIPT.index('cleanup_google_migration_access(){'))
        self.assertLess(SCRIPT.index('validate_migration_backup.py'),SCRIPT.index('POLICY_FILE="$(mktemp)"'))
        self.assertIn('if [[ "$ROUTINE_RELEASE" == 0 ]]; then',SCRIPT)

if __name__=='__main__':unittest.main()
