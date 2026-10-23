#!/usr/bin/env python3
import importlib.util, json, pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class AccountTargetTests(unittest.TestCase):
    def test_account_target_boundary(self):
        d=json.load(open(ROOT/'deploy/aws/account-target.json'))
        self.assertEqual(d['management_account_id'],'891923193638')
        self.assertEqual(d['workload_account_id'],'466401734428')
        self.assertEqual(d['primary_region'],'af-south-1')
        self.assertEqual(d['cloudfront_certificate_region'],'us-east-1')
        self.assertEqual(d['canonical_domain'],'www.waithakandegwa.com')
        self.assertEqual(len(d['branded_nameservers']),4)

    def test_target_install_config_is_pinned(self):
        d=json.load(open(ROOT/'deploy/aws/install-config.466401734428.example.json'))
        self.assertEqual(d['account_id'],'466401734428')
        self.assertEqual(d['region'],'af-south-1')
        self.assertFalse(d['network']['associate_public_ip'])
        self.assertEqual(d['roles']['service_role'],'waithaka-eb-service-role')
        self.assertEqual(d['application']['name'],'waithaka-ndegwa-web')

    def test_target_dns_config_is_pinned(self):
        d=json.load(open(ROOT/'deploy/aws/branded-dns-config.466401734428.example.json'))
        self.assertEqual(d['account_id'],'466401734428')
        self.assertEqual(d['region'],'af-south-1')
        self.assertEqual(set(d['zones']),{'waithakandegwa.com','waithakandegwa.co.ke'})

    def test_network_stack_is_private_app_two_az(self):
        d=json.load(open(ROOT/'deploy/aws/network-stack.json'))
        r=d['Resources']
        self.assertFalse(r['AppSubnetA']['Properties']['MapPublicIpOnLaunch'])
        self.assertFalse(r['AppSubnetB']['Properties']['MapPublicIpOnLaunch'])
        self.assertFalse(r['DbSubnetA']['Properties']['MapPublicIpOnLaunch'])
        self.assertIn('CloudFrontOriginPrefixListId',d['Parameters'])
        self.assertEqual(d['Parameters']['CloudFrontOriginPrefixListId']['Type'],'String')
        self.assertTrue(d['Parameters']['CloudFrontOriginPrefixListId']['AllowedPattern'].startswith('^pl-'))
        self.assertIn('NatGatewayB',r)
        self.assertEqual(r['NatGatewayB']['Condition'],'UseTwoNat')

    def test_bootstrap_cleans_failed_network_and_validates_prefix_list(self):
        text=(ROOT/'deploy/aws/bootstrap-foundation.sh').read_text()
        self.assertIn('network_stack_status', text)
        self.assertIn('Removing failed waithaka-network stack in ROLLBACK_COMPLETE before retry', text)
        self.assertIn('--prefix-list-ids "$prefix_list_id"', text)
        self.assertIn('com.amazonaws.global.cloudfront.origin-facing', text)

    def test_iam_stack_has_expected_roles(self):
        d=json.load(open(ROOT/'deploy/aws/iam-baseline-stack.json'))
        r=d['Resources']
        self.assertEqual(r['ElasticBeanstalkServiceRole']['Properties']['RoleName'],'waithaka-eb-service-role')
        self.assertIn('arn:aws:iam::aws:policy/service-role/AWSElasticBeanstalkEnhancedHealth',r['ElasticBeanstalkServiceRole']['Properties']['ManagedPolicyArns'])
        self.assertIn('arn:aws:iam::aws:policy/AWSElasticBeanstalkManagedUpdatesCustomerRolePolicy',r['ElasticBeanstalkServiceRole']['Properties']['ManagedPolicyArns'])
        self.assertEqual(r['ElasticBeanstalkServiceRole']['Properties']['AssumeRolePolicyDocument']['Statement'][0]['Condition']['StringEquals']['sts:ExternalId'],'elasticbeanstalk')
        self.assertIn('arn:aws:iam::aws:policy/AWSElasticBeanstalkWebTier',r['ElasticBeanstalkInstanceRole']['Properties']['ManagedPolicyArns'])
        self.assertEqual(r['ElasticBeanstalkInstanceRole']['Properties']['RoleName'],'waithaka-eb-instance-role')
        self.assertEqual(r['ElasticBeanstalkInstanceProfile']['Properties']['InstanceProfileName'],'waithaka-eb-instance-profile')

    def test_account_readiness_constants(self):
        spec=importlib.util.spec_from_file_location('ar',ROOT/'deploy/aws/account_readiness.py')
        m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
        self.assertEqual(m.MANAGEMENT_ACCOUNT,'891923193638')
        self.assertEqual(m.WORKLOAD_ACCOUNT,'466401734428')
        self.assertEqual(m.REGION,'af-south-1')

    def test_elastic_beanstalk_filter_uses_json_operator_equals(self):
        text=(ROOT/'deploy/aws/account_readiness.py').read_text()
        self.assertNotIn('Operator==', text)
        self.assertIn('\"Operator\":\"=\"', text)

if __name__=='__main__': unittest.main()
