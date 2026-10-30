#!/usr/bin/env python3
"""Admin-run managed-policy repair; preserve all inline policies, dry-run by default."""
import argparse, json, os, re, subprocess, sys, tempfile
ROLE='WaithakaGitHubDeployRole'
POLICY='WaithakaScopedAutomation'
ACCOUNT='466401734428'
REGION='af-south-1'
ASG=f'arn:aws:autoscaling:{REGION}:{ACCOUNT}:autoScalingGroup:*:autoScalingGroupName/awseb-e-8sjvpauytq-stack-*'
SID='RetireHealthyEnvironmentBatch'
READ_SID='ReadOwnDeploymentPolicy'
READ_TARGET={'Sid':READ_SID,'Effect':'Allow','Action':['iam:GetRolePolicy'],'Resource':f'arn:aws:iam::{ACCOUNT}:role/{ROLE}'}
ROLE_ARN=f'arn:aws:iam::{ACCOUNT}:role/{ROLE}'
MANAGED_NAME='WaithakaRollingDeploymentRepair'
MANAGED_ARN=f'arn:aws:iam::{ACCOUNT}:policy/{MANAGED_NAME}'

class AwsFailure(RuntimeError):
    def __init__(self, code, message):
        self.code=code
        super().__init__(message)

def aws(*args):
    p=subprocess.run(['aws',*args,'--output','json','--no-cli-pager'],capture_output=True,text=True)
    if p.returncode:
        code=re.search(r'An error occurred \(([^)]+)\)',p.stderr)
        raise AwsFailure(code.group(1) if code else 'Unknown','AWS request failed: '+p.stderr[:500])
    return json.loads(p.stdout or '{}')

def patch(policy):
    """Validate the legacy inline form. The returned document is never written."""
    if policy.get('Version')!='2012-10-17' or not isinstance(policy.get('Statement'),list):
        raise ValueError('Unexpected IAM policy structure; review manually.')
    if any(not isinstance(x,dict) for x in policy['Statement']):
        raise ValueError('Unexpected IAM statement; review manually.')
    target={'Sid':SID,'Effect':'Allow','Action':['autoscaling:TerminateInstanceInAutoScalingGroup'],'Resource':ASG,'Condition':{'StringEquals':{'aws:RequestedRegion':REGION}}}
    out=json.loads(json.dumps(policy))
    changed=False
    for required in [target,READ_TARGET]:
        existing=[x for x in policy['Statement'] if x.get('Sid')==required['Sid']]
        if existing:
            if len(existing)!=1 or existing[0]!=required: raise ValueError('Conflicting existing permission; refusing overwrite.')
        else:
            out['Statement'].append(required)
            changed=True
    return out,changed

def managed_document():
    # Keep new grants outside the role's aggregate 10,240-character inline quota.
    base,_=patch({'Version':'2012-10-17','Statement':[]})
    base['Statement'] += [
        {'Sid':'VerifyRepairAttachment','Effect':'Allow',
         'Action':['iam:ListAttachedRolePolicies'],'Resource':ROLE_ARN},
        {'Sid':'ReadRepairPolicy','Effect':'Allow',
         'Action':['iam:GetPolicy','iam:GetPolicyVersion'],'Resource':MANAGED_ARN},
    ]
    if len(json.dumps(base,separators=(',',':')))>6144:
        raise ValueError('Repair document exceeds the managed-policy limit; no change made.')
    return base

def managed_version():
    """Read and validate the active managed document; absence is the only tolerated error."""
    try:
        info=aws('iam','get-policy','--policy-arn',MANAGED_ARN)['Policy']
    except AwsFailure as exc:
        if exc.code=='NoSuchEntity': return None
        raise
    version=info.get('DefaultVersionId','')
    if info.get('Arn')!=MANAGED_ARN or not re.fullmatch(r'v[1-9][0-9]*',version):
        raise RuntimeError('Unexpected managed-policy identity or version; no overwrite allowed.')
    saved=aws('iam','get-policy-version','--policy-arn',MANAGED_ARN,'--version-id',version)['PolicyVersion']
    if saved.get('VersionId')!=version or saved.get('IsDefaultVersion') is not True:
        raise RuntimeError('Managed-policy default changed during verification; rerun the read.')
    if saved.get('Document')!=managed_document():
        raise RuntimeError('Conflicting managed policy exists; refusing to edit or attach it.')
    return version

def managed_attached():
    # AWS CLI paginates this operation automatically; do not use --no-paginate/--max-items.
    response=aws('iam','list-attached-role-policies','--role-name',ROLE)
    policies=response.get('AttachedPolicies')
    if not isinstance(policies,list) or response.get('IsTruncated') or response.get('NextToken'):
        raise RuntimeError('Incomplete role attachment inventory; no change made.')
    if any(not isinstance(item,dict) or not isinstance(item.get('PolicyArn'),str) for item in policies):
        raise RuntimeError('Invalid role attachment inventory; no change made.')
    return any(item['PolicyArn']==MANAGED_ARN for item in policies)

def verify_managed():
    first=managed_version()
    if first is None or not managed_attached():
        raise RuntimeError('Required scoped permission is missing: repair policy is absent or unattached; no inline change made.')
    # Read the active version again after checking the attachment, not an inactive version.
    if managed_version()!=first:
        raise RuntimeError('Managed-policy version changed during verification; rerun the read.')
    return first

def create_managed():
    fd,name=tempfile.mkstemp(prefix='wn-managed-iam-',suffix='.json')
    try:
        with os.fdopen(fd,'w') as f: json.dump(managed_document(),f,separators=(',',':'))
        os.chmod(name,0o600)
        try:
            response=aws('iam','create-policy','--policy-name',MANAGED_NAME,
                         '--description','Scoped Waithaka rolling release and read-only permission verification',
                         '--policy-document','file://'+name)
            if response.get('Policy',{}).get('Arn')!=MANAGED_ARN:
                raise RuntimeError('Created policy identity did not match; nothing attached.')
        except AwsFailure as exc:
            # A concurrent creator may win. Never overwrite it; exact read-back is required.
            if exc.code!='EntityAlreadyExists': raise
    finally:
        os.unlink(name)

def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    mode=parser.add_mutually_exclusive_group()
    mode.add_argument('--apply',action='store_true',help='Apply only after reviewing the exact scoped permission')
    mode.add_argument('--verify-only',action='store_true',help='Verify legacy inline grants or the attached managed repair; never write')
    args=parser.parse_args(argv)
    identity=aws('sts','get-caller-identity')
    if identity.get('Account')!=ACCOUNT: raise RuntimeError('Wrong AWS account; no change made.')
    if args.apply and (':assumed-role/'+ROLE+'/' in identity.get('Arn','') or identity.get('Arn')==f'arn:aws:iam::{ACCOUNT}:role/{ROLE}'):
        raise RuntimeError('The deployment role cannot grant itself permissions; use the authorized IAM administrator session.')
    current=aws('iam','get-role-policy','--role-name',ROLE,'--policy-name',POLICY)['PolicyDocument']
    if not isinstance(current,dict): raise RuntimeError('Unexpected inline IAM policy response.')
    _,missing_inline=patch(current)
    if not missing_inline:
        print('PASS: exact legacy inline repair already exists; no policy changes made. Deployment health still requires verification.')
        return
    if args.verify_only:
        version=verify_managed()
        print(f'PASS: exact managed repair {MANAGED_NAME} {version} is attached to {ROLE}. Explicit denies and live deployment health still require verification.')
        return
    version=managed_version()
    attached=managed_attached()
    print(json.dumps({'account':ACCOUNT,'role':ROLE,'managed_policy':MANAGED_ARN,
                      'inline_policy_untouched':POLICY,'create_required':version is None,
                      'attach_required':not attached,'apply_requested':args.apply,
                      'document':managed_document()},indent=2))
    if not args.apply:
        print('DRY RUN ONLY: no IAM change has been made. Existing inline policies will not be rewritten.')
        return
    if version is None: create_managed()
    # Validate before attaching even when creation raced or a previous attempt stopped midway.
    if managed_version() is None: raise RuntimeError('Managed policy is not readable yet; rerun explicitly. Nothing attached.')
    if not managed_attached(): aws('iam','attach-role-policy','--role-name',ROLE,'--policy-arn',MANAGED_ARN)
    verify_managed()
    print('PASS: exact managed repair attached and verified. Existing inline policies were not modified; no IAM self-write permission was granted.')
if __name__=='__main__':
    try:main()
    except (RuntimeError,ValueError,KeyError,OSError) as exc:
        print('FAIL:',str(exc),file=sys.stderr);sys.exit(1)
