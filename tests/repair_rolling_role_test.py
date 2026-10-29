#!/usr/bin/env python3
"""Offline safety regression: only the targeted single-ASG action may be appended."""
import ast, importlib.util
from pathlib import Path
p=Path(__file__).resolve().parents[1]/'deploy/aws/repair_rolling_role.py'
spec=importlib.util.spec_from_file_location('repair_rolling_role',p)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
original={'Version':'2012-10-17','Statement':[{'Sid':'Existing','Effect':'Allow','Action':['s3:GetObject'],'Resource':'arn:aws:s3:::existing/*'}]}
updated,changed=m.patch(original)
assert changed and len(updated['Statement'])==2 and len(original['Statement'])==1
assert updated['Statement'][0]==original['Statement'][0]
assert updated['Statement'][1]=={'Sid':m.SID,'Effect':'Allow','Action':['autoscaling:TerminateInstanceInAutoScalingGroup'],'Resource':m.ASG,'Condition':{'StringEquals':{'aws:RequestedRegion':'af-south-1'}}}
assert '466401734428' in m.ASG and 'awseb-e-8sjvpauytq-stack-*' in m.ASG
same,did_change=m.patch(updated)
assert not did_change and same==updated
try:
 m.patch({'Version':'2012-10-17','Statement':[{'Sid':m.SID,'Effect':'Allow','Action':'autoscaling:*','Resource':'*'}]})
 raise AssertionError('Conflicting broad grant was accepted')
except ValueError:pass
try:
 m.patch({'Version':'2008-10-17','Statement':[]})
 raise AssertionError('Unsupported IAM version accepted')
except ValueError:pass
print('PASS: scoped one-time ASG IAM patch, unchanged existing statements, idempotence and conflict refusal.')
