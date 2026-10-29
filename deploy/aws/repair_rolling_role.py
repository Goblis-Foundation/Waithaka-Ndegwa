#!/usr/bin/env python3
"""One-time, admin-run scoped deployment-role repair; dry-run without --apply."""
import argparse, json, subprocess, sys
ROLE='WaithakaGitHubDeployRole'
POLICY='WaithakaScopedAutomation'
ACCOUNT='466401734428'
REGION='af-south-1'
ASG=f'arn:aws:autoscaling:{REGION}:{ACCOUNT}:autoScalingGroup:*:autoScalingGroupName/awseb-e-8sjvpauytq-stack-*'
SID='RetireHealthyEnvironmentBatch'
READ_SID='ReadOwnDeploymentPolicy'
READ_TARGET={'Sid':READ_SID,'Effect':'Allow','Action':['iam:GetRolePolicy'],'Resource':f'arn:aws:iam::{ACCOUNT}:role/{ROLE}'}
def aws(*args):
    p=subprocess.run(['aws',*args,'--output','json','--no-cli-pager'],capture_output=True,text=True)
    if p.returncode: raise RuntimeError('AWS request failed: '+p.stderr[:500])
    return json.loads(p.stdout or '{}')
def patch(policy):
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
def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    mode=parser.add_mutually_exclusive_group()
    mode.add_argument('--apply',action='store_true',help='Apply only after reviewing the exact scoped permission')
    mode.add_argument('--verify-only',action='store_true',help='Read back the exact statement; fail if missing, without writing')
    args=parser.parse_args(argv)
    identity=aws('sts','get-caller-identity')
    if identity.get('Account')!=ACCOUNT: raise RuntimeError('Wrong AWS account; no change made.')
    if args.apply and (':assumed-role/'+ROLE+'/' in identity.get('Arn','') or identity.get('Arn')==f'arn:aws:iam::{ACCOUNT}:role/{ROLE}'):
        raise RuntimeError('The deployment role cannot grant itself permissions; use the authorized IAM administrator session.')
    current=aws('iam','get-role-policy','--role-name',ROLE,'--policy-name',POLICY)['PolicyDocument']
    if not isinstance(current,dict): raise RuntimeError('Unexpected inline IAM policy response.')
    revised,changed=patch(current)
    target=next(x for x in revised['Statement'] if x.get('Sid')==SID)
    print(json.dumps({'account':ACCOUNT,'role':ROLE,'policy':POLICY,'statement':target,'read_only_preflight':READ_TARGET,'changed':changed,'apply_requested':args.apply},indent=2))
    if args.verify_only:
        if changed: raise RuntimeError('Required scoped permission is missing; no change made.')
        print('PASS: exact scoped termination and read-only preflight statements exist. Explicit denies can still override them; deployment health still requires independent verification.')
        return
    if args.apply and changed:
        import tempfile,os
        fd,name=tempfile.mkstemp(prefix='wn-scoped-iam-',suffix='.json')
        try:
            with os.fdopen(fd,'w') as f:json.dump(revised,f)
            os.chmod(name,0o600)
            fresh=aws('iam','get-role-policy','--role-name',ROLE,'--policy-name',POLICY)['PolicyDocument']
            if fresh!=current: raise RuntimeError('Policy changed since review; refusing to replace concurrent edits. Rerun the dry run.')
            aws('iam','put-role-policy','--role-name',ROLE,'--policy-name',POLICY,'--policy-document','file://'+name)
            saved=aws('iam','get-role-policy','--role-name',ROLE,'--policy-name',POLICY)['PolicyDocument']
            if saved!=revised: raise RuntimeError('Policy read-back did not match; investigate before deployment.')
            print('PASS: existing policy retained; exact termination and read-only preflight statements read back.')
        finally:os.unlink(name)
    elif not args.apply:print('DRY RUN ONLY: no IAM change has been made.')
    else:print('PASS: exact scoped action already configured; no change needed.')
if __name__=='__main__':
    try:main()
    except (RuntimeError,ValueError,KeyError,OSError) as exc:
        print('FAIL:',str(exc),file=sys.stderr);sys.exit(1)
