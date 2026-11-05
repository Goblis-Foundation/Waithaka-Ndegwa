"""Offline tests: import helpers without invoking AWS or reading production logs."""
import json
import runpy
from pathlib import Path

module=runpy.run_path(str(Path(__file__).resolve().parents[1]/'deploy/aws/migration_log_diagnostics.py'))
filter_lines=module['safe_lines'];prefix=module['HEALTH_PREFIX']
valid={key:False for key in module['HEALTH_BOOL']}
valid.update({key:0 for key in module['HEALTH_INT']})
valid['ai_checked_count']=4
out=filter_lines('prefix '+prefix+json.dumps(valid))
assert len(out)==1 and json.loads(out[0][len(prefix):])==valid
for candidate in [dict(valid,secret='PRIVATE_SECRET'),dict(valid,ai_checked_count='PRIVATE_SOURCE'),dict(valid,last_worker_at=-1),dict(valid,worker_recent=1),{'enabled':True}]:
 assert filter_lines(prefix+json.dumps(candidate))==[]
assert filter_lines(prefix+'invalid JSON PRIVATE_SECRET')==[]
assert filter_lines('PRIVATE_SECRET')==[]
assert filter_lines('Workspace contact relay probe: accepted.')==['Workspace contact relay probe: accepted.']
print('PASS: diagnostics emit only the exact aggregate schema and existing permitted messages.')
