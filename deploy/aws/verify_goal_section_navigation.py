#!/usr/bin/env python3
"""Read-only live check: Goal 2030 is a section, not a primary menu item."""
from __future__ import annotations
import re
import urllib.request

BASE="https://www.waithakandegwa.com"

def read(path: str) -> str:
    request=urllib.request.Request(BASE+path,headers={
        "User-Agent":"Waithaka-ReadOnly-Goal2030-Verification/1.0",
        "Cache-Control":"no-cache",
        "Accept":"text/html"})
    with urllib.request.urlopen(request,timeout=30) as response:
        if response.status!=200:
            raise RuntimeError("Unexpected HTTP status at "+path)
        if response.geturl().split("?")[0].rstrip("/")!=(BASE+path).rstrip("/"):
            raise RuntimeError("Unexpected canonical destination for "+path)
        return response.read(2_000_000).decode("utf-8")

for path in ["/","/about"]:
    html=read(path)
    menu=re.search(r'<nav\s+id="site-main-nav"[^>]*>(.*?)</nav>',html,re.S)
    if menu is None:
        raise RuntimeError("Primary navigation unavailable at "+path)
    if 'goals-2030' in menu.group(1) or 'Goal 2030' in menu.group(1):
        raise RuntimeError("Goal 2030 was left as a main menu item at "+path)
    if not re.search(r'<section\b[^>]*\bid="goals-2030"',html):
        raise RuntimeError("Actual Goal 2030 content section missing at "+path)
    if 'href="'+BASE+'/about#goals-2030"' not in html:
        raise RuntimeError("Footer Goal 2030 section link missing at "+path)
    print("PASS: "+path+" has a real Goal 2030 section, but no standalone primary menu link.")
print("PASS: 2030 objectives, footer discovery and primary navigation preserved without mutations.")
