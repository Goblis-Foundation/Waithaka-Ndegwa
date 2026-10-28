#!/usr/bin/env python3
"""Pure, exact-domain contract for the currently deployed Search CSS asset."""
from __future__ import annotations
from html import unescape
from urllib.parse import urlsplit
import re

EXPECTED_HOST = "www.waithakandegwa.com"
EXPECTED_PATH = "/assets/css/layout-refinement.css"

def versioned_search_css_path(page: str) -> str:
    links = re.findall(r'<link\s+[^>]*\brel="stylesheet"[^>]*\bhref="([^"]+)"', page, re.I)
    eligible = []
    for raw in links:
        parsed = urlsplit(unescape(raw))
        if parsed.path != EXPECTED_PATH:
            continue
        if (parsed.scheme != "https" or parsed.hostname != EXPECTED_HOST
                or parsed.port is not None or parsed.username is not None
                or parsed.password is not None or parsed.fragment
                or not re.fullmatch(r"v=[0-9]{1,16}", parsed.query)):
            raise RuntimeError("An unexpected Search stylesheet URL was returned.")
        eligible.append(parsed.path + "?" + parsed.query)
    if len(eligible) != 1:
        raise RuntimeError("Exactly one versioned first-party Search stylesheet is required.")
    return eligible[0]

def full_width_search_form(css: str) -> bool:
    matched = re.search(r"\.page-search\s+\.search-page-form\s*\{([^}]*)\}", css, re.S)
    return bool(matched and re.search(r"\bmax-width:\s*none\s*;", matched.group(1))
        and "search-intro-container" in css and "search-results-container" in css)
