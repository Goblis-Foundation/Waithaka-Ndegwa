#!/usr/bin/env python3
"""One-time, admin-run scoped deployment-role repair; dry-run without --apply."""
import argparse, json, subprocess, sys
ROLE='WaithakaGitHubDeployRole'
POLICY='WaithakaScopedAutomation'
ACCOUNT='466401734428'
REGION='af-south-1'
ASG=f'arn:aws:autoscaling:{REGION}:{ACCOUNT}:autoScalingGroup:*:autoScalingGroupName/awseb-e-8sjvpauytq-stack-*'
SID='RetireHealthyEnvironmentBatch'
def aws(*args):
    p=subprocess.run(['aws',*args,'--output','json','--no-cli-pager'],capture_output=True,text=True)
    if p.returncode: raise RuntimeError('AWS request failed: '+p.stderr[:500])
    return json.loads(p.stdout or '{}')
def patch(policy):
    if policy.get('Version')!='2012-10-17' or not isinstance(policy.get('Statement'),list):
        raise ValueError('Unexpected IAM policy structure; review manually.')
    existing=[x for x in policy['Statement'] if x.get('Sid')==SID]
    target={'Sid':SID,'Effect':'Allow','Action':['autoscaling:TerminateInstanceInAutoScalingGroup'],'Resource':ASG,'Condition':{'StringEquals':{'aws:RequestedRegion':REGION}}}
    if existing:
        if len(existing)!=1 or existing[0]!=target: raise ValueError('Conflicting existing permission; refusing overwrite.')
        return policy,False
    out=json.loads(json.dumps(policy))
    out['Statement'].append(target)
    return out,True
def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply',action='store_true',help='Apply only after reviewing the exact scoped permission')
    args=parser.parse_args()
    identity=aws('sts','get-caller-identity')
    if identity.get('Account')!=ACCOUNT: raise RuntimeError('Wrong AWS account; no change made.')
    current=aws('iam','get-role-policy','--role-name',ROLE,'--policy-name',POLICY)['PolicyDocument']
    if not isinstance(current,dict): raise RuntimeError('Unexpected inline IAM policy response.')
    revised,changed=patch(current)
    print(json.dumps({'account':ACCOUNT,'role':ROLE,'policy':POLICY,'new_statement':SID,'resource':ASG,'changed':changed,'apply_requested':args.apply},indent=2))
    if args.apply and changed:
        import tempfile,os
        fd,name=tempfile.mkstemp(prefix='wn-scoped-iam-',suffix='.json')
        try:
            with os.fdopen(fd,'w') as f:json.dump(revised,f)
            os.chmod(name,0o600)
            aws('iam','put-role-policy','--role-name',ROLE,'--policy-name',POLICY,'--policy-document','file://'+name)
            saved=aws('iam','get-role-policy','--role-name',ROLE,'--policy-name',POLICY)['PolicyDocument']
            if saved!=revised: raise RuntimeError('Policy read-back did not match; investigate before deployment.')
            print('PASS: exact existing policy retained and the single scoped action was read back.')
        finally:os.unlink(name)
    elif not args.apply:print('DRY RUN ONLY: no IAM change has been made.')
    else:print('PASS: exact scoped action already configured; no change needed.')
if __name__=='__main__':
    try:main()
    except (RuntimeError,ValueError,KeyError) as exc:
        print('FAIL:',str(exc),file=sys.stderr);sys.exit(1)
