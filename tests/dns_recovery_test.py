import importlib.util
from pathlib import Path
from unittest import TestCase
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('recovery_dns', ROOT/'deploy/aws/fresh_dns.py')
dns = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dns)

class RunnerRecoveryTests(TestCase):
    def fixture(self, args, region=None):
        if args[:2] == ['sts','get-caller-identity']: return {'Account':dns.ACCOUNT}
        if args[:2] == ['route53','list-hosted-zones']:
            return {'HostedZones':[{'Name':domain+'.','Id':'/hostedzone/Z'+str(i),'Config':{'PrivateZone':False}} for i,domain in enumerate((dns.PRIMARY,dns.SECONDARY))]}
        if args[:2] == ['route53','get-hosted-zone']:
            return {'DelegationSet':{'Id':'/delegationset/EXISTING','NameServers':['ns-'+str(i)+'.awsdns.test' for i in range(4)]}}
        if args[:2] == ['route53','list-resource-record-sets']:
            domain = dns.PRIMARY if args[-1]=='Z0' else dns.SECONDARY
            return {'ResourceRecordSets':[{'Name':domain+'.','Type':'NS','ResourceRecords':[{'Value':v+'.'} for v in dns.VANITY]}]}
        raise AssertionError('Unexpected AWS operation: '+str(args))

    def test_fresh_runner_recovers_existing_ids_without_writes(self):
        with patch.object(dns,'aws_json',side_effect=self.fixture), patch.object(dns,'find_cert',return_value='arn:existing'), patch.object(dns,'save_state') as save:
            dns.recover()
            state=save.call_args.args[0]
            self.assertEqual(state['zones'],{dns.PRIMARY:'Z0',dns.SECONDARY:'Z1'})
            self.assertEqual(state['delegation_set_id'],'EXISTING')

    def test_ambiguous_existing_zone_stops_without_saving(self):
        def duplicate(args, region=None):
            result=self.fixture(args,region)
            if args[:2]==['route53','list-hosted-zones']: result['HostedZones'].append(result['HostedZones'][0])
            return result
        with patch.object(dns,'aws_json',side_effect=duplicate),patch.object(dns,'save_state') as save:
            with self.assertRaises(SystemExit): dns.recover()
            save.assert_not_called()
