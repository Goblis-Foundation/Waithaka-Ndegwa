"""Publish only the reviewed Google Workspace SPF/DKIM records; no mail activation."""
import argparse
import json
import subprocess
from pathlib import Path

ACCOUNT = '466401734428'
DOMAIN = 'waithakandegwa.com.'
DKIM_NAME = 'google._domainkey.' + DOMAIN
OLD_SPF = 'v=spf1 a mx include:websitewelcome.com ~all'
NEW_SPF = 'v=spf1 a mx include:websitewelcome.com include:_spf.google.com ~all'
DKIM = 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAveN7W2js9ixTWXDL0vTBonALl/NX1u2bbhpNkCF8FkVyPgTKK1mW1FPOGGGhEdtSyL8+6sOAV6RZCG87XskZWgi+miI9wCwqGpGgU6r4So74lk8bG3jYNHnkJgD5K5tuWYfIL5SspD2pbZKHhDNKOgAYzO+3kcmK17g5rY5Xhvcdcw2oY+YPr4l/6im1W9h7LbWRh8kh+bf7e/TPpVQfhfU5cO1g/8pQM8H+ZZkQ9+ZRKYBhj7Cr5ssqHHB9xMmK8ZKvoig4CIjCw4CJfEZ9R/p++B0aS7iJLwbVrD8n/cMb9WgSYY/zcP6cZ50uwdMYbvvRFudJUEqkq9cIaJpEvwIDAQAB'


def aws(*args):
    return json.loads(subprocess.check_output(['aws', *args, '--output', 'json', '--no-cli-pager'], text=True))


def decode_txt(value):
    # Route 53 represents a long TXT RR as adjacent quoted character strings.
    import shlex
    return ''.join(shlex.split(value))


def txt(value):
    return ' '.join(json.dumps(value[i:i+200]) for i in range(0, len(value), 200))


def changes_for(records):
    spf_records = [r for r in records if r['Name'] == DOMAIN and r['Type'] == 'TXT']
    if len(spf_records) != 1:
        raise RuntimeError('Expected exactly one apex TXT record set.')
    source = spf_records[0]
    if set(source) - {'Name', 'Type', 'TTL', 'ResourceRecords'}:
        raise RuntimeError('Refusing to change a routed TXT record set.')
    values = [decode_txt(r['Value']) for r in source['ResourceRecords']]
    spfs = [v for v in values if v.lower().startswith('v=spf1')]
    if spfs not in ([OLD_SPF], [NEW_SPF]):
        raise RuntimeError('SPF differs from the reviewed record; no changes made.')
    changes = []
    if spfs == [OLD_SPF]:
        updated = dict(source)
        updated['ResourceRecords'] = [({'Value': txt(NEW_SPF)} if decode_txt(r['Value']) == OLD_SPF else r) for r in source['ResourceRecords']]
        # DELETE + CREATE provides a compare-and-swap guard against concurrent edits.
        changes += [{'Action': 'DELETE', 'ResourceRecordSet': source}, {'Action': 'CREATE', 'ResourceRecordSet': updated}]
    existing = [r for r in records if r['Name'] == DKIM_NAME]
    if existing:
        if len(existing) != 1 or existing[0]['Type'] != 'TXT' or [decode_txt(r['Value']) for r in existing[0].get('ResourceRecords', [])] != [DKIM]:
            raise RuntimeError('Existing DKIM differs; refusing to replace it.')
    else:
        changes.append({'Action': 'CREATE', 'ResourceRecordSet': {'Name': DKIM_NAME, 'Type': 'TXT', 'TTL': 300, 'ResourceRecords': [{'Value': txt(DKIM)}]}})
    return changes


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if aws('sts', 'get-caller-identity')['Account'] != ACCOUNT:
        raise RuntimeError('Wrong AWS account.')
    zones = [z for z in aws('route53', 'list-hosted-zones')['HostedZones'] if z['Name'] == DOMAIN and not z['Config']['PrivateZone']]
    if len(zones) != 1:
        raise RuntimeError('Expected exactly one public hosted zone for the personal domain.')
    zone_id = zones[0]['Id']
    records = aws('route53', 'list-resource-record-sets', '--hosted-zone-id', zone_id)['ResourceRecordSets']
    changes = changes_for(records)
    Path('mail-dns-before.json').write_text(json.dumps({'zone_id': zone_id, 'records': [r for r in records if r['Name'] in (DOMAIN, DKIM_NAME)]}, indent=2))
    Path('mail-dns-plan.json').write_text(json.dumps({'Changes': changes}, indent=2))
    print(json.dumps({'account': ACCOUNT, 'zone': zone_id, 'domain': DOMAIN, 'changed_record_names': sorted({c['ResourceRecordSet']['Name'] for c in changes}), 'apply': args.apply}))
    if args.apply and changes:
        result = aws('route53', 'change-resource-record-sets', '--hosted-zone-id', zone_id, '--change-batch', 'file://mail-dns-plan.json')
        change_id = result['ChangeInfo']['Id']
        subprocess.run(['aws', 'route53', 'wait', 'resource-record-sets-changed', '--id', change_id], check=True)
        print('Route 53 change INSYNC: ' + change_id)
    if args.apply:
        after = aws('route53', 'list-resource-record-sets', '--hosted-zone-id', zone_id)['ResourceRecordSets']
        if changes_for(after):
            raise RuntimeError('Mail DNS read-back did not match.')
        print('SPF and DKIM records verified. Google DKIM activation and SMTP remain separate.')


if __name__ == '__main__':
    main()
