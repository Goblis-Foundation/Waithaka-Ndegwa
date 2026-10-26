import importlib.util
from pathlib import Path
from copy import deepcopy

path = Path(__file__).resolve().parents[1] / 'deploy/aws/microsoft_domain_dns.py'
spec = importlib.util.spec_from_file_location('microsoft_domain_dns', path)
dns = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dns)

source = {'Name': dns.DOMAIN, 'Type': 'TXT', 'TTL': 3600, 'ResourceRecords': [{'Value': '"v=spf1 include:_spf.google.com ~all"'}, {'Value': '"google-site-verification=preserve"'}, {'Value': '"MS=old-value-preserve"'}]}
records = [deepcopy(source), {'Name': dns.DOMAIN, 'Type': 'MX', 'TTL': 300, 'ResourceRecords': [{'Value': '1 mail.example.org.'}]}]
before = deepcopy(records)
plan = dns.changes_for(records)
assert records == before
assert [p['Action'] for p in plan] == ['DELETE', 'CREATE']
assert plan[0]['ResourceRecordSet'] == source
new = plan[1]['ResourceRecordSet']
assert new['TTL'] == source['TTL']
assert new['ResourceRecords'][:-1] == source['ResourceRecords']
assert dns.decode_txt(new['ResourceRecords'][-1]['Value']) == dns.VERIFICATION
assert dns.changes_for([new]) == []
assert dns.changes_for([])[0]['Action'] == 'CREATE'
assert dns.decode_txt('"MS=" "ms86777381"') == dns.VERIFICATION
for bad in [[dict(source, SetIdentifier='routed')], [source, source]]:
    try:
        dns.changes_for(bad)
        raise AssertionError('Unsafe record set accepted')
    except RuntimeError:
        pass
print('PASS: Microsoft verification TXT is additive, idempotent and compare-and-swap guarded; existing mail and verification values are preserved.')
