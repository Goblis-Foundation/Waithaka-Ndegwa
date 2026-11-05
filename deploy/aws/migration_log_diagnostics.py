"""Read scoped deployment tails; output only explicitly permitted diagnostics."""
import datetime as dt
import io
import json
import re
import subprocess
import time
import urllib.parse
import urllib.request
import zipfile

SAFE=re.compile(r'(Workspace contact relay probe: (?:accepted|rejected)\.|Article grouping migration AWS read failed: [A-Za-z0-9_.-]{1,100}; HTTP [0-9]{1,3}\.|Article grouping (?:additive migration applied and verified|migration already verified with runtime read access)\.|Could not open input file: bin/article-groups-migrate\.php|Founder migration AWS read failed: [A-Za-z0-9_.-]{1,100}; HTTP [0-9]{1,3}\.|Founder story additive migration applied and verified\.|Founder story migration already verified with runtime read access\.|Could not open input file: bin/founder-story-migrate\.php|Application error [A-F0-9]{12} [A-Za-z0-9_\\]+ /[A-Za-z0-9_./-]+:[0-9]+)')
HEALTH_BOOL={'enabled','ai_enabled','ai_configured','worker_recent'}
HEALTH_INT={'published_count','source_checked_count','ai_checked_count','source_attention_count','ai_attention_count','pending_review_count','last_worker_at','latest_source_at','latest_ai_at','source_current_count','ai_current_count','recovery_checked_count'}
HEALTH_PREFIX='Opportunity review health: '

def safe_lines(text):
 out=[]
 for line in text.splitlines():
  match=SAFE.search(line)
  if match:out.append(match.group(0))
  if HEALTH_PREFIX not in line:continue
  raw=line.split(HEALTH_PREFIX,1)[1].strip()
  if len(raw)>2000:continue
  try:data=json.loads(raw)
  except (ValueError,TypeError):continue
  if not isinstance(data,dict) or set(data)!=(HEALTH_BOOL|HEALTH_INT):continue
  if not all(type(data[key]) is bool for key in HEALTH_BOOL):continue
  if not all(type(data[key]) is int and 0<=data[key]<=2**53 for key in HEALTH_INT):continue
  out.append(HEALTH_PREFIX+json.dumps(data,sort_keys=True,separators=(',',':')))
 return out

def aws(*args):
 p=subprocess.run(['aws',*args,'--region','af-south-1','--output','json'],capture_output=True,text=True)
 if p.returncode:raise RuntimeError('Scoped deployment-log metadata could not be read.')
 return json.loads(p.stdout or '{}')
def main():
 name='waithaka-ndegwa-production';requested=time.time()
 aws('elasticbeanstalk','request-environment-info','--environment-name',name,'--info-type','tail')
 entries=[]
 for _ in range(12):
  time.sleep(10)
  rows=aws('elasticbeanstalk','retrieve-environment-info','--environment-name',name,'--info-type','tail').get('EnvironmentInfo',[])
  entries=[r for r in rows if dt.datetime.fromisoformat(r.get('SampleTimestamp','1970-01-01T00:00:00+00:00').replace('Z','+00:00')).timestamp()>=requested-5]
  if entries:break
 if not entries:raise RuntimeError('Scoped deployment logs were not ready.')
 for row in entries:
  target=urllib.parse.urlparse(row['Message'])
  if target.scheme!='https' or not (target.hostname or '').endswith('.amazonaws.com'):raise RuntimeError('Unexpected deployment-log origin.')
  with urllib.request.urlopen(row['Message'],timeout=30) as response:data=response.read(8*1024*1024)
  if zipfile.is_zipfile(io.BytesIO(data)):
   with zipfile.ZipFile(io.BytesIO(data)) as archive:
    bodies=[archive.read(info) for info in archive.infolist() if info.file_size<=4*1024*1024 and any(k in info.filename for k in ['eb-engine.log','eb-hooks.log','error_log','error.log','php-fpm'])]
  else:bodies=[data]
  for body in bodies:
   for line in safe_lines(body.decode('utf-8','replace')):print(line)
if __name__=='__main__':main()
