"""Add only the owner-supplied Microsoft domain verification TXT; preserve all other DNS."""
import argparse
import json
import shlex
import subprocess
from pathlib import Path

ACCOUNT = '466401734428'
DOMAIN = 'waithakandegwa.com.'
VERIFICATION = 'MS=ms86777381'


def aws(*args):
    return json.loads(subprocess.check_output(['aws', *args, '--output', 'json', '--no-cli-pager'], text=True))


def decode_txt(value):
    return ''.join(shlex.split(value))


def changes_for(records):
    apex = [r for r in records if r['Name'] == DOMAIN and r['Type'] == 'TXT']
    if len(apex) > 1:
        raise RuntimeError('Expected at most one simple apex TXT set.')
    if not apex:
        return [{'Action': 'CREATE', 'ResourceRecordSet': {'Name': DOMAIN, 'Type': 'TXT', 'TTL': 300, 'ResourceRecords': [{'Value': json.dumps(VERIFICATION)}]}}]
    original = apex[0]
    if set(original) - {'Name', 'Type', 'TTL', 'ResourceRecords'}:
        raise RuntimeError('Refusing to change a routed TXT set.')
    if any(decode_txt(v['Value']) == VERIFICATION for v in original['ResourceRecords']):
        return []
    updated = dict(original)
    updated['ResourceRecords'] = [*original['ResourceRecords'], {'Value': json.dumps(VERIFICATION)}]
    # Compare-and-swap: an intervening TXT edit makes DELETE fail, rather than losing data.
    return [{'Action': 'DELETE', 'ResourceRecordSet': original}, {'Action': 'CREATE', 'ResourceRecordSet': updated}]


def dig(*args):
    return subprocess.check_output(['dig', '+short', *args], text=True, timeout=20).strip().splitlines()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    if aws('sts', 'get-caller-identity')['Account'] != ACCOUNT:
        raise RuntimeError('Wrong AWS account.')
    zones = [z for z in aws('route53', 'list-hosted-zones')['HostedZones'] if z['Name'] == DOMAIN and not z['Config']['PrivateZone']]
    if len(zones) != 1:
        raise RuntimeError('Expected exactly one public personal-domain hosted zone.')
    zone = zones[0]['Id']
    servers = aws('route53', 'get-hosted-zone', '--id', zone)['DelegationSet']['NameServers']
    authoritative = {s.lower().rstrip('.') for s in dig('NS', DOMAIN)}
    if authoritative != {s.lower().rstrip('.') for s in servers}:
        raise RuntimeError('The hosted zone is not the publicly delegated authority. No changes made.')
    records = aws('route53', 'list-resource-record-sets', '--hosted-zone-id', zone)['ResourceRecordSets']
    changes = changes_for(records)
    Path('microsoft-dns-before.json').write_text(json.dumps({'zone': zone, 'records': [r for r in records if r['Name'] == DOMAIN and r['Type'] == 'TXT']}, indent=2))
    Path('microsoft-dns-plan.json').write_text(json.dumps({'Changes': changes}, indent=2))
    print(json.dumps({'account': ACCOUNT, 'domain': DOMAIN, 'verification': VERIFICATION, 'apply': args.apply, 'changes': len(changes)}))
    if args.apply and changes:
        result = aws('route53', 'change-resource-record-sets', '--hosted-zone-id', zone, '--change-batch', 'file://microsoft-dns-plan.json')
        subprocess.run(['aws', 'route53', 'wait', 'resource-record-sets-changed', '--id', result['ChangeInfo']['Id']], check=True)
        print('Microsoft verification TXT change INSYNC.')
    if args.apply:
        after = aws('route53', 'list-resource-record-sets', '--hosted-zone-id', zone)['ResourceRecordSets']
        if changes_for(after):
            raise RuntimeError('Route 53 verification read-back failed.')
        for server in servers:
            if VERIFICATION not in [decode_txt(v) for v in dig('TXT', DOMAIN, '@' + server)]:
                raise RuntimeError('An authoritative nameserver has not returned the verification TXT.')
        print('PASS: all authoritative nameservers publish MS=ms86777381. Other TXT, SPF, DKIM and MX records were preserved. Microsoft tenant verification remains a separate step.')


if __name__ == '__main__':
    main()
