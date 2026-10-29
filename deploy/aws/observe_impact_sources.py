#!/usr/bin/env python3
"""Read-only public Goal 2030 source status: no tokens, data import or publication.

The output deliberately includes no metrics, raw response, report identifiers,
approval evidence, credentials or provider error text. A 200 response is only
wire-format observation, never proof of source approval or consumer activation.
"""
from __future__ import annotations

import datetime as dt
import json
import os
from pathlib import Path
import re
import ssl
import sys
import urllib.error
import urllib.request

MAX_BYTES = 65536
SOURCES = {
    "goblis_group": "https://www.goblisgroup.com/api/public/impact/waithaka/",
    "ubunifu": "https://www.ubunifuinstitute.com/api/public/impact/waithaka/",
    "afritrace": "https://www.afritracelink.com/api/public/impact/waithaka/",
    "goblis_foundation": "https://www.goblisfoundation.org/api/public/impact/waithaka/",
}
DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


def classify(source: str, status: int | None, content_type: str = "",
             body: bytes = b"", *, oversized: bool = False) -> dict:
    """Allowlisted status only. Never return any source-controlled free text."""
    result = {"source": source, "http_status": status, "state": "unavailable"}
    if status is None:
        result["state"] = "unreachable"
    elif status in (401, 403):
        result["state"] = "authorization_required"
    elif status == 503:
        result["state"] = "publication_not_available"
    elif 300 <= status < 400:
        result["state"] = "redirect_not_followed"
    elif status != 200:
        result["state"] = "route_not_ready"
    elif oversized:
        result["state"] = "response_too_large"
    elif "application/json" not in content_type.lower():
        result["state"] = "unexpected_content_type"
    else:
        try:
            data = json.loads(body)
        except (ValueError, UnicodeDecodeError):
            result["state"] = "invalid_json"
            return result
        if not isinstance(data, dict):
            result["state"] = "invalid_contract"
            return result
        if data.get("source") != source or data.get("schema_version") != 2:
            result["state"] = "invalid_contract"
            return result
        approved = data.get("approved_for_publication")
        disposition = data.get("publication_status")
        if not isinstance(approved, bool) or disposition not in ("approved", "withdrawn"):
            result["state"] = "invalid_contract"
        elif disposition == "withdrawn" and not approved:
            result["state"] = "source_withheld"
        elif disposition == "approved" and approved:
            if not isinstance(data.get("metrics"), dict):
                result["state"] = "invalid_contract"
            else:
                result["state"] = "approved_wire_report_seen"
                period = data.get("measurement_period_end")
                if period is not None and (not isinstance(period, str) or
                                           not DATE.fullmatch(period)):
                    result["state"] = "invalid_contract"
                elif period is not None:
                    try:
                        dt.date.fromisoformat(period)
                    except ValueError:
                        result["state"] = "invalid_contract"
        else:
            result["state"] = "invalid_contract"
    return result


def observe(source: str, url: str, opener=None) -> dict:
    """One anonymous GET to the fixed source HTTPS URL, with redirects disabled."""
    opener = opener or urllib.request.build_opener(
        NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context())
    )
    request = urllib.request.Request(url, headers={
        "Accept": "application/json",
        "User-Agent": "Waithaka-Impact-ReadOnly-Status/1.0",
        "Cache-Control": "no-cache",
    }, method="GET")
    try:
        with opener.open(request, timeout=12) as response:
            body = response.read(MAX_BYTES + 1)
            return classify(source, response.status, response.headers.get("Content-Type", ""),
                            body[:MAX_BYTES], oversized=len(body) > MAX_BYTES)
    except urllib.error.HTTPError as error:
        # Do not read or log provider error bodies or tokens.
        return classify(source, error.code)
    except (urllib.error.URLError, TimeoutError, OSError, ssl.SSLError):
        return classify(source, None)


def main() -> int:
    now = dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds")
    report = {
        "checked_at_utc": now,
        "purpose": "Read-only public availability; not source approval or publication receipt",
        "checks": [observe(source, url) for source, url in SOURCES.items()],
        "notes": [
            "Missing / 503 data is unavailable, not zero.",
            "An approved-wire response is not a consumer validation, source signature or settlement.",
            "Source metrics, report IDs, raw bodies, tokens and contact records are never stored.",
            "Owner-authenticated private feeds require separate checks in Writer.",
        ],
    }
    path = Path(os.environ.get("IMPACT_SOURCE_PROBE_OUT", "impact-source-observation.json"))
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    for item in report["checks"]:
        print(f"{item['source']}: {item['state']} (HTTP {item['http_status']})")
    return 0  # Pending governed source publication is not a software failure.


if __name__ == "__main__":
    sys.exit(main())
