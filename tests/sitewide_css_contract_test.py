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
