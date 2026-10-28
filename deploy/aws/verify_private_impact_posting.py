#!/usr/bin/env python3
"""Read-only production acceptance: neither protected configuration page is public."""
import urllib.request
import urllib.error
from urllib.parse import urlparse

BASE="https://www.waithakandegwa.com"
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):
        return None
opener=urllib.request.build_opener(NoRedirect)

for path in ("/write/impact","/write/publishing/automation"):
    req=urllib.request.Request(BASE+path,headers={"Accept":"text/html","Cache-Control":"no-cache","User-Agent":"Waithaka-ReadOnly-Private-Check/1.0"})
    try:
        response=opener.open(req,timeout=20)
    except urllib.error.HTTPError as err:
        response=err
    with response:
        status=response.status
        location=response.headers.get("Location","")
        response_body=response.read(64000).decode("utf-8","replace")
    if status in (301,302,303,307,308):
        dest=urlparse(location)
        if dest.scheme!="https" or dest.netloc!="www.waithakandegwa.com" or not dest.path.startswith("/write/login"):
            raise RuntimeError(f"{path}: unexpected authentication redirect")
    elif status not in (401,403,404):
        raise RuntimeError(f"{path}: protected workspace unexpectedly accessible: HTTP {status}")
    if "Read-only bearer token" in response_body or "Posting automation · actual readiness" in response_body:
        raise RuntimeError(f"{path}: private workspace content leaked to anonymous request")
    print("PASS: anonymous access remains blocked on "+path)
print("PASS: no private data, payments, posts or provider actions.")
