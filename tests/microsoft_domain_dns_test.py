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

provider = [f'ns-{i}.awsdns-example.net' for i in range(1, 5)]
vanity = [f'ns{i}.waithakandegwa.com.' for i in range(1, 5)]
addresses = {p: [f'192.0.2.{i}'] for i, p in enumerate(provider, 1)}
addresses.update({v.rstrip('.'): [f'192.0.2.{i}'] for i, v in enumerate(vanity, 1)})
assert dns.authority_servers(provider, provider, lambda name: []) == sorted(provider)
assert dns.authority_servers(vanity, provider, addresses.__getitem__) == sorted(v.rstrip('.') for v in vanity)
for names, resolve in [(['foreign.example'], addresses.__getitem__), (vanity, lambda name: ['192.0.2.255'] if name.startswith('ns1.waithaka') else addresses[name])]:
    try:
        dns.authority_servers(names, provider, resolve)
        raise AssertionError('Foreign or mismatched authority accepted')
    except RuntimeError:
        pass
print('PASS: branded nameservers require exact provider IPv4 delegation mapping; foreign nameservers and mismatched addresses fail closed.')
