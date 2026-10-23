#!/usr/bin/env python3
"""One-time account-pinned GitHub OIDC bootstrap. Never reads application secrets."""
import json
import subprocess
import tempfile
from pathlib import Path

ACCOUNT = '466401734428'
REGION = 'af-south-1'
ROLE = 'WaithakaGitHubDeployRole'
DATA = 'waithaka-ndegwa-production-466401734428-data'
PROVIDER = f'arn:aws:iam::{ACCOUNT}:oidc-provider/token.actions.githubusercontent.com'

def aws(*args):
    p = subprocess.run(['aws', *args, '--output', 'json', '--no-cli-pager'], capture_output=True, text=True)
    if p.returncode:
        raise RuntimeError(p.stderr.strip())
    return json.loads(p.stdout or '{}')

def statement(sid, actions, resources, condition=None):
    x = dict(Sid=sid, Effect='Allow', Action=actions, Resource=resources)
    if condition: x['Condition'] = condition
    return x

def bootstrap():
    if aws('sts', 'get-caller-identity')['Account'] != ACCOUNT:
        raise RuntimeError(f'Refusing changes outside AWS account {ACCOUNT}.')
    data = aws('cloudformation', 'describe-stacks', '--stack-name', DATA, '--region', REGION)['Stacks'][0]
    if data['StackStatus'] not in ('CREATE_COMPLETE', 'UPDATE_COMPLETE'):
        raise RuntimeError('Persistent data stack is not ready; no infrastructure was recreated.')
    outputs = {x['OutputKey']: x['OutputValue'] for x in data['Outputs']}
    buckets = [outputs[k] for k in ('PublicMedia', 'PrivateMedia', 'ReleaseArtifacts')]
    zones = aws('route53', 'list-hosted-zones')['HostedZones']
    zone_arns = []
    for domain in ('waithakandegwa.com.', 'waithakandegwa.co.ke.'):
        matches = [z for z in zones if z['Name'] == domain and not z['Config']['PrivateZone']]
        if len(matches) != 1:
            raise RuntimeError(f'Expected one existing public zone for {domain}; found {len(matches)}. No zones created.')
        zone_arns.append('arn:aws:route53:::hostedzone/' + matches[0]['Id'].split('/')[-1])
    providers = aws('iam', 'list-open-id-connect-providers')['OpenIDConnectProviderList']
    if PROVIDER not in [x['Arn'] for x in providers]:
        aws('iam', 'create-open-id-connect-provider', '--url', 'https://token.actions.githubusercontent.com', '--client-id-list', 'sts.amazonaws.com', '--tags', 'Key=Project,Value=Waithaka-Ndegwa')
    else:
        provider = aws('iam', 'get-open-id-connect-provider', '--open-id-connect-provider-arn', PROVIDER)
        if 'sts.amazonaws.com' not in provider['ClientIDList']:
            aws('iam', 'add-client-id-to-open-id-connect-provider', '--open-id-connect-provider-arn', PROVIDER, '--client-id', 'sts.amazonaws.com')
    trust = {'Version':'2012-10-17','Statement':[{'Effect':'Allow','Principal':{'Federated':PROVIDER},'Action':'sts:AssumeRoleWithWebIdentity','Condition':{'StringEquals':{
        'token.actions.githubusercontent.com:aud':'sts.amazonaws.com',
        'token.actions.githubusercontent.com:repository_id':'746133481',
        'token.actions.githubusercontent.com:repository_owner_id':'331913387',
        'token.actions.githubusercontent.com:ref':'refs/heads/main',
        'token.actions.githubusercontent.com:sub':'repo:Goblis-Foundation@331913387/Waithaka-Ndegwa@746133481:ref:refs/heads/main'
    }}}]}
    regional = {'StringEquals':{'aws:RequestedRegion':REGION}}
    policy = {'Version':'2012-10-17','Statement':[
        statement('ReadDeploymentMetadata', ['autoscaling:Describe*','ec2:Describe*','elasticloadbalancing:Describe*','elasticbeanstalk:Describe*','elasticbeanstalk:List*','cloudformation:Describe*','cloudformation:List*','cloudformation:GetTemplate','cloudformation:GetTemplateSummary','acm:ListCertificates','acm:DescribeCertificate','route53:ListHostedZones','route53:GetHostedZone','route53:ListResourceRecordSets','route53:ListReusableDelegationSets','route53:GetReusableDelegationSet','route53:GetChange'], '*'),
        statement('ExistingBeanstalkApplication', ['elasticbeanstalk:UpdateEnvironment','elasticbeanstalk:CreateApplicationVersion','elasticbeanstalk:RequestEnvironmentInfo','elasticbeanstalk:RetrieveEnvironmentInfo','elasticbeanstalk:RestartAppServer','elasticbeanstalk:AbortEnvironmentUpdate'], [f'arn:aws:elasticbeanstalk:{REGION}:{ACCOUNT}:application/waithaka-ndegwa',f'arn:aws:elasticbeanstalk:{REGION}:{ACCOUNT}:applicationversion/waithaka-ndegwa/*',f'arn:aws:elasticbeanstalk:{REGION}:{ACCOUNT}:environment/waithaka-ndegwa/waithaka-ndegwa-production'], regional),
        statement('OwnedStackUpdates', ['cloudformation:CreateStack','cloudformation:UpdateStack','cloudformation:CreateChangeSet','cloudformation:ExecuteChangeSet','cloudformation:DeleteChangeSet'], [f'arn:aws:cloudformation:{REGION}:{ACCOUNT}:stack/waithaka-edge/*', data['StackId'], f'arn:aws:cloudformation:*:{ACCOUNT}:changeSet/awscli-cloudformation-package-deploy-*/*']),
        statement('ReleaseAndBeanstalkBundles', ['s3:GetObject','s3:GetObjectVersion','s3:GetObjectAcl','s3:PutObject','s3:PutObjectAcl'], [f'arn:aws:s3:::{outputs["ReleaseArtifacts"]}/*',f'arn:aws:s3:::elasticbeanstalk-{REGION}-{ACCOUNT}/*']),
        statement('BeanstalkSystemBucket', ['s3:CreateBucket','s3:ListBucket','s3:GetBucketLocation','s3:GetBucketPolicy','s3:GetBucketAcl','s3:PutBucketPolicy','s3:PutBucketAcl'], f'arn:aws:s3:::elasticbeanstalk-{REGION}-{ACCOUNT}'),
        statement('ExistingInstanceProfile', ['iam:GetInstanceProfile'], f'arn:aws:iam::{ACCOUNT}:instance-profile/waithaka-eb-instance-profile'),
        statement('OwnedBucketMetadata', ['s3:ListBucket','s3:GetBucketLocation','s3:GetBucketPolicy','s3:GetBucketVersioning'], ['arn:aws:s3:::'+b for b in buckets]),
        statement('PublicMediaDistributionPolicy', ['s3:PutBucketPolicy'], 'arn:aws:s3:::'+outputs['PublicMedia']),
        statement('RuntimeSecretCreation', ['secretsmanager:CreateSecret','secretsmanager:DescribeSecret','secretsmanager:TagResource'], f'arn:aws:secretsmanager:{REGION}:{ACCOUNT}:secret:waithaka-ndegwa-production-runtime-*', regional),
        statement('PassExistingBeanstalkRoles', ['iam:GetRole','iam:PassRole'], [f'arn:aws:iam::{ACCOUNT}:role/waithaka-eb-service-role',f'arn:aws:iam::{ACCOUNT}:role/waithaka-eb-instance-role']),
        statement('RuntimeBootstrapPolicy', ['iam:GetRolePolicy','iam:PutRolePolicy'], f'arn:aws:iam::{ACCOUNT}:role/waithaka-eb-instance-role'),
        statement('RuntimeSsmOnly', ['iam:AttachRolePolicy'], f'arn:aws:iam::{ACCOUNT}:role/waithaka-eb-instance-role', {'ArnEquals':{'iam:PolicyARN':'arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore'}}),
        statement('ExistingZoneRecords', ['route53:ChangeResourceRecordSets'], zone_arns),
        statement('EdgeRead', ['cloudfront:ListDistributions','cloudfront:GetDistribution','cloudfront:GetDistributionConfig','cloudfront:GetFunction','cloudfront:DescribeFunction','cloudfront:GetCachePolicy','cloudfront:GetCachePolicyConfig','cloudfront:GetOriginAccessControl','cloudfront:GetOriginAccessControlConfig','cloudfront:ListTagsForResource'], '*'),
        statement('EdgeCreate', ['cloudfront:CreateDistribution','cloudfront:CreateDistributionWithTags','cloudfront:CreateFunction','cloudfront:CreateCachePolicy','cloudfront:CreateOriginAccessControl'], '*'),
        statement('OwnedAccountEdgeUpdates', ['cloudfront:UpdateDistribution','cloudfront:UpdateFunction','cloudfront:PublishFunction','cloudfront:UpdateCachePolicy','cloudfront:UpdateOriginAccessControl','cloudfront:TagResource','cloudfront:UntagResource','cloudfront:CreateInvalidation'], f'arn:aws:cloudfront::{ACCOUNT}:*/*')
    ]}
    with tempfile.TemporaryDirectory(prefix='waithaka-oidc-') as folder:
        t = Path(folder)/'trust.json'; t.write_text(json.dumps(trust))
        p = Path(folder)/'permissions.json'; p.write_text(json.dumps(policy))
        roles = aws('iam','list-roles')['Roles']
        if ROLE not in [r['RoleName'] for r in roles]:
            aws('iam','create-role','--role-name',ROLE,'--assume-role-policy-document','file://'+str(t),'--max-session-duration','3600','--tags','Key=Project,Value=Waithaka-Ndegwa')
        else:
            aws('iam','update-assume-role-policy','--role-name',ROLE,'--policy-document','file://'+str(t))
        aws('iam','put-role-policy','--role-name',ROLE,'--policy-name','WaithakaScopedAutomation','--policy-document','file://'+str(p))
    print('SUCCESS: GitHub OIDC and scoped repository automation are ready in account '+ACCOUNT+'.')
    print('No AWS access keys were created. Existing infrastructure and registrar delegation were retained.')

if __name__ == '__main__':
    bootstrap()
