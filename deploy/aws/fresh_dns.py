#!/usr/bin/env python3
from __future__ import annotations
import argparse, datetime as dt, json, os, pathlib, re, socket, subprocess, sys, urllib.parse, urllib.request, uuid

ACCOUNT='466401734428'
PRIMARY='waithakandegwa.com'
SECONDARY='waithakandegwa.co.ke'
CANONICAL='www.waithakandegwa.com'
VANITY=[f'ns{i}.waithakandegwa.com' for i in range(1,5)]
STATE=pathlib.Path.home()/'waithaka-aws-bootstrap'/'fresh-dns-state.json'
EVIDENCE=pathlib.Path.home()/'waithaka-aws-bootstrap'


def fail(msg:str, code:int=1):
    print(msg,file=sys.stderr); raise SystemExit(code)

def run(args:list[str], region:str|None=None)->str:
    cmd=['aws',*args,'--no-cli-pager']
    if region and '--region' not in cmd: cmd += ['--region',region]
    p=subprocess.run(cmd,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if p.returncode: fail(f"AWS command failed: {' '.join(cmd[:4])}\n{p.stderr.strip()}")
    return p.stdout

def aws_json(args:list[str], region:str|None=None):
    out=run([*args,'--output','json'],region)
    return json.loads(out or '{}')

def account_check():
    ident=aws_json(['sts','get-caller-identity'])
    if ident.get('Account')!=ACCOUNT: fail(f"Refusing DNS work outside account {ACCOUNT}; current account is {ident.get('Account')}")
    return ident

def save_state(st:dict):
    STATE.parent.mkdir(parents=True,exist_ok=True)
    tmp=STATE.with_suffix('.tmp'); tmp.write_text(json.dumps(st,indent=2,sort_keys=True)+'\n'); os.chmod(tmp,0o600); tmp.replace(STATE)

def load_state()->dict:
    return json.loads(STATE.read_text()) if STATE.exists() else {}

def doh_query(name:str, rtype:str, *, tolerate_dns_errors:bool=False)->dict:
    url='https://dns.google/resolve?'+urllib.parse.urlencode({'name':name,'type':rtype,'cd':'false','do':'false'})
    req=urllib.request.Request(url,headers={'accept':'application/dns-json','user-agent':'WaithakaDNSPreparation/1.1'})
    try:
        with urllib.request.urlopen(req,timeout=15) as r: data=json.load(r)
    except Exception as e:
        if tolerate_dns_errors:
            return {'status':-1,'answers':[],'error':str(e)}
        fail(f'Unable to snapshot current public DNS for {name} {rtype}: {e}')
    status=int(data.get('Status',-1))
    type_codes={'A':1,'NS':2,'CNAME':5,'MX':15,'TXT':16,'AAAA':28,'CAA':257}
    wanted=type_codes.get(rtype.upper())
    answers=[a for a in (data.get('Answer') or []) if wanted is None or int(a.get('type',-1))==wanted]
    if status not in (0,3) and not tolerate_dns_errors:
        fail(f'Public DNS query returned status {status} for {name} {rtype}.')
    return {'status':status,'answers':answers}

def doh(name:str, rtype:str)->list[dict]:
    return doh_query(name,rtype)['answers']

def fqdn(v:str)->str: return v if v.endswith('.') else v+'.'

def snapshot_public()->dict:
    queries=[
      (PRIMARY,'A'),(PRIMARY,'AAAA'),(PRIMARY,'MX'),(PRIMARY,'TXT'),(PRIMARY,'CAA'),
      (CANONICAL,'A'),(CANONICAL,'AAAA'),(CANONICAL,'CNAME'),
      ('_dmarc.'+PRIMARY,'TXT'),('google._domainkey.'+PRIMARY,'TXT'),
      (SECONDARY,'A'),(SECONDARY,'AAAA'),(SECONDARY,'MX'),(SECONDARY,'TXT'),
      ('www.'+SECONDARY,'A'),('www.'+SECONDARY,'AAAA'),('www.'+SECONDARY,'CNAME'),
      ('_dmarc.'+SECONDARY,'TXT'),('google._domainkey.'+SECONDARY,'TXT'),
    ]
    snap={}
    for name,typ in queries:
        ans=doh(name,typ)
        vals=[]; ttl=None
        for a in ans:
            # Google's type numbers: A=1, CNAME=5, MX=15, TXT=16, AAAA=28, CAA=257
            vals.append(str(a.get('data',''))); ttl=int(a.get('TTL',300)) if ttl is None else min(ttl,int(a.get('TTL',300)))
        snap[f'{name}|{typ}']={'name':name,'type':typ,'ttl':ttl or 300,'values':vals}
    return snap

def resolve_ns(ns:str)->dict:
    v4=set(); v6=set()
    for family,_,_,_,sockaddr in socket.getaddrinfo(ns.rstrip('.'),53,0,socket.SOCK_STREAM):
        if family==socket.AF_INET: v4.add(sockaddr[0])
        elif family==socket.AF_INET6: v6.add(sockaddr[0])
    if not v4: fail(f'No IPv4 address resolved for Route 53 server {ns}')
    if not v6: fail(f'No IPv6 address resolved for Route 53 server {ns}')
    return {'ipv4':sorted(v4),'ipv6':sorted(v6)}

def find_cert(region:str, primary_domain:str)->str|None:
    certs=aws_json(['acm','list-certificates','--certificate-statuses','PENDING_VALIDATION','ISSUED'],region)
    for c in certs.get('CertificateSummaryList',[]):
        if c.get('DomainName')==primary_domain: return c.get('CertificateArn')
    return None

def cert_records(arn:str,region:str)->list[dict]:
    cert=aws_json(['acm','describe-certificate','--certificate-arn',arn],region).get('Certificate',{})
    out=[]
    for d in cert.get('DomainValidationOptions',[]) or []:
        rr=d.get('ResourceRecord') or {}
        if rr.get('Name') and rr.get('Value'):
            out.append({'domain':d.get('DomainName'),'name':rr['Name'].rstrip('.'),'type':rr.get('Type','CNAME'),'value':fqdn(rr['Value'])})
    return out

def create_resources(st:dict)->dict:
    if not st.get('delegation_set_id'):
        ref='waithaka-'+dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-'+uuid.uuid4().hex[:8]
        ds=aws_json(['route53','create-reusable-delegation-set','--caller-reference',ref])
        st['delegation_set_id']=ds['DelegationSet']['Id'].split('/')[-1]
        st['aws_nameservers']=ds['DelegationSet']['NameServers']
        save_state(st)
    if not st.get('aws_nameservers'):
        ds=aws_json(['route53','get-reusable-delegation-set','--id',st['delegation_set_id']])
        st['aws_nameservers']=ds['DelegationSet']['NameServers']
    st.setdefault('zones',{})
    for domain in (PRIMARY,SECONDARY):
        if not st['zones'].get(domain):
            ref='waithaka-zone-'+domain.replace('.','-')+'-'+uuid.uuid4().hex[:10]
            z=aws_json(['route53','create-hosted-zone','--name',domain,'--caller-reference',ref,'--delegation-set-id',st['delegation_set_id'],'--hosted-zone-config',json.dumps({'Comment':'Waithaka Ndegwa authoritative DNS','PrivateZone':False})])
            st['zones'][domain]=z['HostedZone']['Id'].split('/')[-1]
            save_state(st)
    return st

def rrs(zone_id:str)->list[dict]: return aws_json(['route53','list-resource-record-sets','--hosted-zone-id',zone_id]).get('ResourceRecordSets',[])

def upsert(zone_id:str, records:list[dict], comment:str):
    if not records: return
    changes=[{'Action':'UPSERT','ResourceRecordSet':r} for r in records]
    # Route53 max 1000; ours tiny
    payload={'Comment':comment,'Changes':changes}
    p=EVIDENCE/f'route53-change-{zone_id}-{uuid.uuid4().hex[:8]}.json'; p.write_text(json.dumps(payload,indent=2)+'\n')
    res=aws_json(['route53','change-resource-record-sets','--hosted-zone-id',zone_id,'--change-batch',f'file://{p}'])
    change_id=res['ChangeInfo']['Id'].split('/')[-1]
    run(['route53','wait','resource-record-sets-changed','--id',change_id])

def txt_rdata(value:str)->str:
    """Return Route 53-safe TXT RDATA, preserving valid quoted chunks."""
    value=str(value).strip()
    if re.fullmatch(r'(?:"(?:[^"\\]|\\.)*"(?:\s+|$))+',value):
        return value
    # Route 53 TXT character-strings are limited to 255 characters. Keep
    # generated chunks comfortably below that limit and let JSON escaping
    # provide the required surrounding quotes.
    chunks=[value[i:i+240] for i in range(0,len(value),240)] or ['']
    return ' '.join(json.dumps(chunk,ensure_ascii=False) for chunk in chunks)

def rr(name:str,typ:str,values:list[str],ttl:int=300)->dict:
    if typ.upper()=='TXT':
        values=[txt_rdata(v) for v in values]
    return {'Name':fqdn(name),'Type':typ,'TTL':ttl,'ResourceRecords':[{'Value':v} for v in values]}

def source_records(snapshot:dict,domain:str)->list[dict]:
    records=[]
    names=[domain,'www.'+domain,'_dmarc.'+domain,'google._domainkey.'+domain]
    for name in names:
        cname=snapshot.get(f'{name}|CNAME',{}).get('values',[])
        for typ in ('A','AAAA','MX','TXT','CAA'):
            item=snapshot.get(f'{name}|{typ}')
            if not item or not item.get('values'): continue
            if name.startswith('www.') and cname and typ in ('A','AAAA'): continue
            vals=item['values']
            # Normalize domain-bearing record types.
            if typ=='MX':
                norm=[]
                for v in vals:
                    parts=v.split(maxsplit=1); norm.append(parts[0]+' '+fqdn(parts[1]) if len(parts)==2 else v)
                vals=norm
            records.append(rr(name,typ,vals,min(max(int(item.get('ttl',300)),60),3600)))
        if cname:
            records.append(rr(name,'CNAME',[fqdn(cname[0])],min(max(int(snapshot[f'{name}|CNAME'].get('ttl',300)),60),3600)))
    return records

def ensure_workspace(records:list[dict],domain:str)->tuple[list[dict],dict]:
    info={'mx_seeded':False,'spf_seeded':False,'dmarc_seeded':False,'dkim_present':False}
    apex=fqdn(domain); dmarc=fqdn('_dmarc.'+domain); dkim=fqdn('google._domainkey.'+domain)
    def has(name,typ,pred=lambda r:True): return any(r['Name']==name and r['Type']==typ and pred(r) for r in records)
    if not has(apex,'MX'):
        records.append(rr(domain,'MX',['1 smtp.google.com.'],300)); info['mx_seeded']=True
    if not has(apex,'TXT',lambda r:any('v=spf1' in x['Value'].lower() for x in r['ResourceRecords'])):
        records.append(rr(domain,'TXT',['"v=spf1 include:_spf.google.com ~all"'],300)); info['spf_seeded']=True
    if not has(dmarc,'TXT'):
        records.append(rr('_dmarc.'+domain,'TXT',['"v=DMARC1; p=none; pct=100; adkim=s; aspf=s"'],300)); info['dmarc_seeded']=True
    info['dkim_present']=has(dkim,'TXT')
    return records,info

def prepare():
    account_check(); st=load_state(); st.setdefault('schema_version',1); st.setdefault('account_id',ACCOUNT)
    if not st.get('source_snapshot'):
        print('Snapshotting currently published core DNS before delegation...')
        st['source_snapshot']=snapshot_public(); save_state(st)
    st=create_resources(st)
    aws_ns=[fqdn(x) for x in st['aws_nameservers']]
    if len(aws_ns)!=4: fail('Reusable delegation set did not return exactly four Route 53 nameservers.')
    st['vanity_map']={VANITY[i]:{'aws_nameserver':aws_ns[i],**resolve_ns(aws_ns[i])} for i in range(4)}; save_state(st)

    viewer=find_cert('us-east-1',CANONICAL); origin=find_cert('af-south-1','origin.'+PRIMARY)
    if not viewer or not origin: fail('Expected ACM viewer/origin certificates were not found. Run certificate preparation first.')
    st['certificates']={'viewer':viewer,'origin':origin}
    validation=cert_records(viewer,'us-east-1')+cert_records(origin,'af-south-1')
    st['certificate_validation_records']=validation

    workspace={}
    for domain in (PRIMARY,SECONDARY):
        zid=st['zones'][domain]
        existing=rrs(zid); soa=next(x for x in existing if x['Type']=='SOA')
        soa_parts=soa['ResourceRecords'][0]['Value'].split(); soa_parts[0]=fqdn(VANITY[0]); soa_parts[-1]='60'
        records=[rr(domain,'NS',[fqdn(x) for x in VANITY],60),rr(domain,'SOA',[' '.join(soa_parts)],60)]
        if domain==PRIMARY:
            for i,v in enumerate(VANITY):
                m=st['vanity_map'][v]; records.append(rr(v,'A',m['ipv4'],300)); records.append(rr(v,'AAAA',m['ipv6'],300))
        copied=source_records(st['source_snapshot'],domain)
        if domain==PRIMARY:
            copied,workspace[domain]=ensure_workspace(copied,domain)
        else:
            workspace[domain]={'mx_seeded':False,'spf_seeded':False,'dmarc_seeded':False,'dkim_present':any(r['Name']==fqdn('google._domainkey.'+domain) and r['Type']=='TXT' for r in copied)}
        records.extend(copied)
        for v in validation:
            if v['name']==domain or v['name'].endswith('.'+domain): records.append(rr(v['name'],v['type'],[v['value']],300))
        # Deduplicate by Name+Type: later generated records (cert validation) should win.
        dedup={};
        for rec in records: dedup[(rec['Name'],rec['Type'])]=rec
        upsert(zid,list(dedup.values()),'Prepare Waithaka fresh authoritative DNS, certificate validation, Workspace baseline and vanity nameservers')
    st['workspace_dns']=workspace; st['prepared_at']=dt.datetime.now(dt.timezone.utc).isoformat(); save_state(st)

    handoff={
      'account_id':ACCOUNT,'prepared_at':st['prepared_at'],'domains':[PRIMARY,SECONDARY],
      'nameservers':[{'hostname':v,'ipv4':st['vanity_map'][v]['ipv4'],'ipv6':st['vanity_map'][v]['ipv6'],'aws_nameserver':st['vanity_map'][v]['aws_nameserver']} for v in VANITY],
      'registrar_actions':[
        'Register child/glue nameservers ns1-ns4.waithakandegwa.com using the listed IPv4 and IPv6 addresses at the .com registrar.',
        'Change waithakandegwa.com authoritative nameservers to ns1-ns4.waithakandegwa.com.',
        'Change waithakandegwa.co.ke authoritative nameservers to the same ns1-ns4.waithakandegwa.com values.',
        'Do not change individual A/MX/TXT records at the registrar after delegation; Route 53 becomes authoritative.'
      ],
      'workspace':workspace,
      'dkim_action':'If google._domainkey is absent, generate a 2048-bit DKIM key in Google Admin after delegation and add the provided TXT value to Route 53.'
    }
    hp=EVIDENCE/'REGISTRAR-HANDOFF.json'; hp.write_text(json.dumps(handoff,indent=2)+'\n'); os.chmod(hp,0o600)
    print(json.dumps(handoff,indent=2))
    print(f'REGISTRAR_HANDOFF={hp}')
    print('DNS preparation complete. No registrar delegation was changed by this script.')

def recover():
    """Reconstruct runner state from existing infrastructure without DNS writes."""
    account_check()
    zones=aws_json(['route53','list-hosted-zones']).get('HostedZones',[])
    st={'account_id':ACCOUNT,'zones':{}}
    delegation_ids=set()
    for domain in (PRIMARY,SECONDARY):
        matches=[z for z in zones if z.get('Name')==fqdn(domain) and not z.get('Config',{}).get('PrivateZone')]
        if len(matches)!=1:
            fail(f'Expected exactly one existing public zone for {domain}; found {len(matches)}. Recovery creates no zones.')
        zid=matches[0]['Id'].split('/')[-1]
        zone=aws_json(['route53','get-hosted-zone','--id',zid])
        ds=zone.get('DelegationSet',{})
        if not ds.get('Id') or len(ds.get('NameServers',[]))!=4:
            fail(f'Existing zone {domain} does not expose its reusable four-server delegation set.')
        delegation_ids.add(ds['Id'].split('/')[-1])
        st['zones'][domain]=zid
        st['aws_nameservers']=ds['NameServers']
        records=rrs(zid)
        ns=next((x for x in records if x['Name']==fqdn(domain) and x['Type']=='NS'),{})
        if {fqdn(x['Value']).lower() for x in ns.get('ResourceRecords',[])}!={fqdn(v).lower() for v in VANITY}:
            fail(f'Existing branded NS records for {domain} differ from the accepted delegation. No records changed.')
    if len(delegation_ids)!=1:
        fail('The existing zones do not share a reusable delegation set. No delegation changed.')
    st['delegation_set_id']=delegation_ids.pop()
    viewer=find_cert('us-east-1',CANONICAL)
    origin=find_cert('af-south-1','origin.'+PRIMARY)
    if not viewer or not origin:
        fail('Existing viewer/origin certificates were not found. Recovery creates no certificates.')
    st['certificates']={'viewer':viewer,'origin':origin}
    st['recovered_at']=dt.datetime.now(dt.timezone.utc).isoformat()
    save_state(st)
    print(json.dumps({'recovered':True,'zones':st['zones'],'delegation_set_id':st['delegation_set_id']}))

def status():
    account_check(); st=load_state()
    if not st: fail('Fresh DNS has not been prepared.')
    out={'account_id':ACCOUNT,'delegation_set_id':st.get('delegation_set_id'),'zones':{},'certificates':{}}
    expected={fqdn(x).lower() for x in VANITY}
    for d,zid in st.get('zones',{}).items():
        sets=rrs(zid); ns=next((x for x in sets if x['Name']==fqdn(d) and x['Type']=='NS'),None)
        public=doh_query(d,'NS',tolerate_dns_errors=True)
        public_ns={fqdn(x.get('data','')).lower() for x in public['answers'] if x.get('data')}
        out['zones'][d]={
          'zone_id':zid,
          'vanity_ns':[x['Value'] for x in (ns or {}).get('ResourceRecords',[])],
          'public_dns_status':public['status'],
          'public_dns_error':public.get('error'),
          'public_ns':sorted(public_ns),
          'delegated_to_vanity':public['status']==0 and public_ns==expected
        }
    for key,arn in st.get('certificates',{}).items():
        reg='us-east-1' if key=='viewer' else 'af-south-1'; c=aws_json(['acm','describe-certificate','--certificate-arn',arn],reg).get('Certificate',{})
        out['certificates'][key]={'arn':arn,'status':c.get('Status')}
    print(json.dumps(out,indent=2))

def main():
    ap=argparse.ArgumentParser(description='Prepare fresh Route 53 DNS and white-label nameservers for the Waithaka Ndegwa website.')
    ap.add_argument('action',choices=['prepare','recover','status'])
    a=ap.parse_args(); {'prepare':prepare,'recover':recover,'status':status}[a.action]()
if __name__=='__main__': main()
