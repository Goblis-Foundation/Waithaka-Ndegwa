#!/usr/bin/env python3
"""Monitor public HTTPS while an approved AWS release command runs.

GET-only, credential-free and bounded: detects 502 or a blank Start Here page
*while* deployment is in progress. The application update finishes before the
monitor returns, even if it catches a temporary outage. No recovery writes.
"""
from __future__ import annotations
import argparse
import concurrent.futures
import datetime as dt
import json
import os
from pathlib import Path
import subprocess
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

ORIGIN = "https://www.waithakandegwa.com"
PATHS = ("/", "/start-here", "/healthz")
MAX_BODY = 200_000


def verdict(path: str, status: int, body: bytes) -> str | None:
    if status != 200:
        return "http_" + str(status)
    if len(body) > MAX_BODY:
        return "oversized_response"
    if path == "/healthz":
        try:
            return None if json.loads(body.decode("utf-8")).get("ok") is True else "health_not_ready"
        except (ValueError, UnicodeError, AttributeError):
            return "health_invalid_json"
    try:
        html = body.decode("utf-8")
    except UnicodeError:
        return "html_not_utf8"
    if len(html) < 1500 or "<main" not in html.lower() or "<h1" not in html.lower():
        return "page_empty_or_missing_landmarks"
    if path == "/start-here":
        required = ("Welcome to the journey.", "Explore My Thoughts",
                    "Explore Startup Journeys", "Choose a practical resource",
                    "Read Founder Stories")
        if any(marker not in html for marker in required):
            return "start_here_missing_required_content"
    elif "My Thoughts" not in html:
        return "home_missing_content"
    return None


def fetch(path: str) -> tuple[int, bytes]:
    request = Request(ORIGIN + path, method="GET",
        headers={"User-Agent": "Waithaka-Release-Continuity/2026-10-29",
                 "Cache-Control": "no-cache", "Pragma": "no-cache",
                 "Accept": "text/html, application/json"})
    try:
        with urlopen(request, timeout=12) as response:
            body = response.read(MAX_BODY + 1)
            return response.status, body
    except HTTPError as error:
        error.close()
        return int(error.code), b""


def sample(fetcher=fetch) -> dict:
    now = dt.datetime.now(dt.timezone.utc).isoformat()
    checks = {}
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
        futures = {path: pool.submit(fetcher, path) for path in PATHS}
        for path, future in futures.items():
            try:
                status, body = future.result()
                failure = verdict(path, status, body)
                checks[path] = {"status": status, "ok": failure is None}
                if failure is not None:
                    checks[path]["failure"] = failure
            except (URLError, OSError, TimeoutError, ValueError) as error:
                checks[path] = {"status": None, "ok": False,
                                "failure": "request_" + type(error).__name__}
            except Exception:
                checks[path] = {"status": None, "ok": False,
                                "failure": "request_unexpected"}
    return {"at": now, "checks": checks,
            "ok": all(value["ok"] for value in checks.values())}


def monitor(command: list[str] | None, interval: float, report_path: Path) -> int:
    report = {"scope": "canonical public GET only, no credentials or writes",
              "routes": list(PATHS), "samples": [], "release_returncode": None}
    try:
        first = sample()
        report["samples"].append(first)
        if not first["ok"]:
            print("FAIL: live site is not already healthy; refusing to begin a deployment.", flush=True)
            return 1
        if not command:
            print("PASS: current live homepage, full Start Here content and application health.", flush=True)
            return 0
        process = subprocess.Popen(command)
        print("PASS: pre-deployment public routes; watching during rollout.", flush=True)
        while process.poll() is None:
            time.sleep(interval)
            check = sample()
            report["samples"].append(check)
            if not check["ok"]:
                print("FAIL: an unavailable or empty public route was observed during deployment: " +
                      ",".join(path + "=" + str(value.get("failure")) for path, value
                               in check["checks"].items() if not value["ok"]), flush=True)
        report["release_returncode"] = process.wait()
        for n in range(3):
            last = sample()
            report["samples"].append(last)
            if n < 2:
                time.sleep(1)
        failures = sum(1 for x in report["samples"] if not x["ok"])
        report["failed_samples"] = failures
        if failures or report["release_returncode"] != 0:
            print("FAIL: release continuity observed " + str(failures) +
                  " faulty sample(s); command exit=" + str(report["release_returncode"]), flush=True)
            return 1
        print("PASS: " + str(len(report["samples"])) +
              " continuous deployment and post-deployment public checks; no empty Start Here or HTTP 502 observed.", flush=True)
        return 0
    finally:
        report_path.parent.mkdir(parents=True, exist_ok=True)
        report_path.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--interval", type=float, default=5.0)
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if not (2 <= args.interval <= 60):
        parser.error("Interval must be between 2 and 60 seconds.")
    command = args.command
    if command and command[0] == "--":
        command = command[1:]
    if command and not (len(command) >= 2 and command[0] == "bash" and
                        command[1] in ("deploy/aws/fresh_hosting.sh", "deploy/aws/google_workspace.sh")):
        parser.error("The deployment watcher may run only approved hosting or mail setup scripts.")
    return monitor(command or None, args.interval, args.report)


if __name__ == "__main__":
    sys.exit(main())
