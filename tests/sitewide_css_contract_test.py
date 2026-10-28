#!/usr/bin/env python3
"""Offline contract for the exact CSS parsed by the read-only production verifier."""
from pathlib import Path
import re

css=Path(__file__).resolve().parent.parent.joinpath("assets/css/layout-refinement.css").read_text()
form_rule=re.search(r"\.page-search\s+\.search-page-form\s*\{([^}]*)\}",css,re.S)
assert form_rule is not None,"Current Search form stylesheet selector is absent"
assert re.search(r"max-width:\s*none\s*;",form_rule.group(1)) is not None, (
    "Public Search form must share the full container instead of a narrower width cap"
)
assert "search-intro-container" in css and "search-results-container" in css
print("PASS: Search page/form/results share uncapped responsive container CSS.")

# The deployment verifier must use the exact versioned asset the live HTML
# loads, not the unversioned edge-cached CSS URL.
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/"deploy"/"aws"))
from public_css_contract import versioned_search_css_path, full_width_search_form
html = '<link rel="stylesheet" href="https://www.waithakandegwa.com/assets/css/layout-refinement.css?v=1793200001">'
assert versioned_search_css_path(html)=="/assets/css/layout-refinement.css?v=1793200001"
assert full_width_search_form(css)
assert not full_width_search_form(".page-search .search-page-form{max-width:1100px;}")
for invalid in (
  '<link rel="stylesheet" href="https://other.example/assets/css/layout-refinement.css?v=1">',
  '<link rel="stylesheet" href="http://www.waithakandegwa.com/assets/css/layout-refinement.css?v=1">',
  '<link rel="stylesheet" href="https://www.waithakandegwa.com/assets/css/layout-refinement.css">',
  '<link rel="stylesheet" href="https://www.waithakandegwa.com/assets/css/layout-refinement.css?v=1&url=http://other.example">',
):
    try: versioned_search_css_path(invalid)
    except RuntimeError: pass
    else: raise AssertionError("Unsafe or unversioned CSS destination accepted")
print("PASS: Production verifier pins versioned first-party CSS and rejects stale or untrusted sources.")
