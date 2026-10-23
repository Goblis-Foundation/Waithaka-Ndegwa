#!/usr/bin/env python3
from pathlib import Path
import json,re
root=Path(__file__).resolve().parents[1]
script=(root/'deploy/aws/prepare-data-and-certificates.sh').read_text()
data=json.loads((root/'deploy/aws/data-stack.json').read_text())
assert '466401734428' in script
assert 'waithaka-network' in script
assert 'waithaka-ndegwa-production-466401734428-data' in script
assert 'db.t4g.small' in script
assert 'us-east-1' in script and 'af-south-1' in script
assert 'www.waithakandegwa.com' in script and 'origin.waithakandegwa.com' in script
assert 'request-certificate' in script and 'validation-method DNS' in script
assert '"Type":"PlatformBranchName"' in script, 'selector must use the actual PHP branch metadata'
assert '"Operator":"begins_with"' in script and '"Values":["PHP 8.4"]' in script
assert '"Type":"PlatformLifecycleState"' in script and '"Values":["recommended"]' in script
assert '"Attribute":"PlatformName"' not in script and '"Type":"PlatformName"' not in script, 'do not filter PHP 8.4 by the generic PlatformName field' 
assert 'cloudformation deploy' in script
assert 'MediaDistributionArn=' in script
assert 'DeletionProtection' in json.dumps(data) and data['Resources']['Database']['Properties']['DeletionProtection'] is True
assert data['Resources']['Database']['Properties']['ManageMasterUserPassword'] is True
assert data['Resources']['PublicMedia']['DeletionPolicy']=='Retain'
assert data['Resources']['PrivateMedia']['DeletionPolicy']=='Retain'
assert 'route53' not in re.sub(r'com\.amazonaws\.global\.cloudfront\.origin-facing','',script).lower(), 'post-foundation stage must not change DNS'
print('post-foundation stage static checks passed')
