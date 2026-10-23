"""Exercise the actual migration cleanup function with a fake AWS command; no network."""
from pathlib import Path
import json, os, subprocess, tempfile, unittest
ROOT=Path(__file__).resolve().parents[1]
SCRIPT=(ROOT/'deploy/aws/fresh_application.sh').read_text()
FUNCTION=SCRIPT[SCRIPT.index('cleanup_google_migration_access(){'):SCRIPT.index('if [[ "$GOOGLE_MIGRATION_REQUIRED" == 1 ]]; then\n  trap')]
class MigrationCleanupTests(unittest.TestCase):
    def invoke(self, enabled, status=0):
        with tempfile.TemporaryDirectory() as d:
            output=Path(d)/'policy.json'
            program='''set -u
GOOGLE_MIGRATION_REQUIRED="$ENABLED"
RUNTIME_SECRET=fixture-runtime-secret
PUBLIC_BUCKET=fixture-public
PRIVATE_BUCKET=fixture-private
INSTANCE_ROLE=fixture-role
aws(){
  while [[ "$#" -gt 0 ]]; do
    if [[ "$1" == --policy-document ]]; then cp "${2#file://}" "$CAPTURE"; break; fi
    shift
  done
  return "$AWS_STATUS"
}
'''+FUNCTION+'''cleanup_google_migration_access
'''
            env=os.environ|{'ENABLED':str(enabled),'AWS_STATUS':str(status),'CAPTURE':str(output)}
            result=subprocess.run(['bash','-c',program],env=env,capture_output=True,text=True)
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
    def test_cleanup_failure_is_not_reported_as_success(self):
        status,_=self.invoke(1,13)
        self.assertEqual(status,13)
    def test_normal_release_does_not_reopen_access(self):
        status,policy=self.invoke(0)
        self.assertEqual(status,0)
        self.assertIsNone(policy)
if __name__=='__main__':unittest.main()
