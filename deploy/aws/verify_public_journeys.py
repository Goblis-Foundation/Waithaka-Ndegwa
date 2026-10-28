#!/usr/bin/env python3
"""Read-only production acceptance of public search and Kenya-first shared forms.
No submissions, private links, writes, payments, provider auth or analytics events.
"""
from __future__ import annotations
import urllib.request
import urllib.error
import re

BASE = "https://www.waithakandegwa.com"

def read(path: str) -> str:
    request = urllib.request.Request(
        BASE + path,
        headers={"User-Agent": "Waithaka-ReadOnly-Acceptance/1.0",
                 "Cache-Control": "no-cache",
                 "Accept": "text/html, text/css;q=0.9"},
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        if response.status != 200:
            raise RuntimeError(f"{path}: unexpected HTTP status {response.status}")
        if response.geturl().split("?")[0].rstrip("/") != (BASE + path.split("?",1)[0]).rstrip("/"):
            raise RuntimeError(f"{path}: unexpected final destination")
        return response.read(2_000_000).decode("utf-8")

search = read("/search")
for expected in ('class="search-page-form"', 'name="q"', 'name="type"',
                 'for="public-search"', 'for="search-type"', 'layout-refinement.css',
                 'class="container search-intro-container"'):
    if expected not in search:
        raise RuntimeError("Search page missing " + expected)
print("PASS: Public search page and current labelled responsive controls.")
css = read("/assets/css/layout-refinement.css")
if ".page-search .search-page-form" not in css or "grid-template-columns" not in css:
    raise RuntimeError("Current search layout CSS is unavailable.")
form_rule = re.search(r"\.page-search\s+\.search-page-form\s*\{([^}]*)\}", css, re.S)
if form_rule is None or re.search(r"max-width:\s*none\s*;", form_rule.group(1)) is None:
    raise RuntimeError("The current search form still has a narrower width cap.")
search_results = read("/search?q=enterprise&type=post")
for expected in ('class="container search-results-container"', 'class="search-page-form"',
                 'class="topic-pills"', 'name="q" value="enterprise"'):
    if expected not in search_results:
        raise RuntimeError("The search-results layout is missing " + expected)
print("PASS: Live search input, filters and results use the common uncapped page container.")
print("PASS: Deployed responsive search stylesheet is served.")

for path in ["/contact", "/founders/submit", "/opportunities/submit", "/promote"]:
    content = read(path)
    for key in ['name="country"', 'name="county"', 'name="sub_county"',
                'name="ward"', 'name="city"']:
        if key not in content:
            raise RuntimeError(path + " missing " + key)
    if 'value="Kenya" selected' not in content:
        raise RuntimeError(path + " does not select Kenya by default")
    if path != "/opportunities/submit" and 'name="phone_code"' not in content:
        raise RuntimeError(path + " does not render an independent phone code")
    print("PASS: Kenya-first public journey (read-only): " + path)
print("PASS: Read-only public journey acceptance; no form or provider action.")
