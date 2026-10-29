#!/usr/bin/env python3
"""Static least-privilege acceptance of the precise Beanstalk rolling cleanup grant.

No AWS access, network requests, IAM writes or real instances are involved.
"""
from __future__ import annotations
import json
from pathlib import Path
import re
import unittest

ROOT=Path(__file__).resolve().parents[1]
POLICY=ROOT/"deploy/aws/rolling-batch-cleanup-policy.json"
BOOTSTRAP=ROOT/"deploy/aws/bootstrap-automation.py"
ASG_ARN="arn:aws:autoscaling:af-south-1:466401734428:autoScalingGroup:f312fcc7-a933-4793-881a-d9324d65dde5:autoScalingGroupName/awseb-e-8sjvpauytq-stack-AWSEBAutoScalingGroup-axF8jqGaErqA"

class RollingIamPolicy(unittest.TestCase):
    def test_only_one_scoped_cleanup_action_and_no_self_admin(self):
        doc=json.loads(POLICY.read_text())
        self.assertEqual(doc["Version"],"2012-10-17")
        self.assertEqual(len(doc["Statement"]),1)
        s=doc["Statement"][0]
        self.assertEqual(s["Effect"],"Allow")
        self.assertEqual(s["Action"],["autoscaling:TerminateInstanceInAutoScalingGroup"])
        self.assertEqual(s["Condition"],{"StringEquals":{"aws:RequestedRegion":"af-south-1"}})
        self.assertEqual(s["Resource"],"arn:aws:autoscaling:af-south-1:466401734428:autoScalingGroup:*:autoScalingGroupName/awseb-e-8sjvpauytq-stack-AWSEBAutoScalingGroup-*")
        suffix=s["Resource"].replace("*","[A-Za-z0-9-]+")
        self.assertRegex(ASG_ARN,"^"+suffix+"$")
        self.assertNotIn("iam:",json.dumps(doc))
        self.assertNotIn("AdministratorAccess",json.dumps(doc))
        self.assertNotIn('"Resource": "*"',POLICY.read_text())

    def test_bootstrap_pins_to_one_repo_and_known_scaling_group(self):
        source=BOOTSTRAP.read_text()
        self.assertIn("ACCOUNT = '466401734428'",source)
        self.assertIn("REGION = 'af-south-1'",source)
        self.assertIn("ROLE = 'WaithakaGitHubDeployRole'",source)
        self.assertIn("token.actions.githubusercontent.com:repository_id':'746133481'",source)
        self.assertIn("'autoscaling:TerminateInstanceInAutoScalingGroup'",source)
        self.assertIn("('autoscaling:TerminateInstanceInAutoScalingGroup', f'arn:aws:autoscaling:{REGION}:{ACCOUNT}",source)
        self.assertIn("f312fcc7-a933-4793-881a-d9324d65dde5",source)
        self.assertIn("awseb-e-8sjvpauytq-stack-AWSEBAutoScalingGroup-axF8jqGaErqA",source)
        self.assertIn("simulate-principal-policy",source)
        self.assertNotIn("iam:PutRolePolicy',f'arn:aws:iam::{ACCOUNT}:role/{ROLE}'",source)

if __name__ == "__main__":
    unittest.main(verbosity=2)
