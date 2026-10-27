#!/usr/bin/env python3
"""Read-only production acceptance for publishing, approved branding and visitor snapshots."""
from __future__ import annotations
import hashlib
import json
import struct
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from zoneinfo import ZoneInfo
from verify_reconciliation_release import verify as verify_reconciliation

BASE = 'https://www.waithakandegwa.com'
LOGO_SHA256 = 'f674df8d4a9f30df3ce663b7f001af8fd1b18984d251ff5924c9ff01bac77c0b'

class SameOrigin(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if not newurl.startswith(BASE + '/'):
            raise RuntimeError('Unexpected redirect outside the canonical website')
        return super().redirect_request(req, fp, code, msg, headers, newurl)

OPENER = urllib.request.build_opener(SameOrigin())

def read(path: str, expected: int = 200) -> tuple[bytes, object]:
    request = urllib.request.Request(BASE + path, headers={'User-Agent':'Waithaka-Release-Acceptance/1.0','Cache-Control':'no-cache','Accept':'application/json,text/html,image/*,*/*'})
    try:
        response = OPENER.open(request, timeout=30)
    except urllib.error.HTTPError as error:
        if error.code != expected:
            raise RuntimeError('Unexpected HTTP status for ' + path) from None
        response = error
    with response:
        if response.code != expected:
            raise RuntimeError('Unexpected HTTP status for ' + path)
        return response.read(2 * 1024 * 1024), response.headers

def check_pages() -> None:
    for path, marker in [('/', 'data-visitor-statistics'),('/opportunities', 'Opportunities that move ideas forward.'),('/partner-stories','Meet the people and businesses behind the offer.'),('/promote','Send brief for review')]:
        data, _ = read(path)
        text = data.decode('utf-8')
        if marker not in text or 'brand-lockup.webp' not in text:
            raise RuntimeError('New route or approved logo is not visible: ' + path)
        if path == '/promote' and 'googletagmanager.com/gtag/js' in text:
            raise RuntimeError('Private commercial intake must not emit Analytics')
    logo, _ = read('/assets/images/brand-lockup.webp?v=20261027')
    if hashlib.sha256(logo).hexdigest() != LOGO_SHA256:
        raise RuntimeError('The deployed wordmark does not match the approved bytes')
    for name, width, height in [('icon-192.png',192,192),('icon-512.png',512,512),('apple-touch-icon.png',180,180),('brand-lockup.png',720,160)]:
        image, _ = read('/assets/images/' + name + '?v=20261027')
        if image[:8] != b'\x89PNG\r\n\x1a\n' or len(image) < 24 or struct.unpack('>II',image[16:24]) != (width,height):
            raise RuntimeError('Incorrect ready-to-use icon or fallback: ' + name)
    icon, _ = read('/assets/images/favicon.svg?v=20261027')
    if b'<path' not in icon or b'Waithaka Ndegwa' not in icon:
        raise RuntimeError('Approved vector favicon is missing')
    private, headers = read('/client/workspace/' + '0' * 32, 403)
    if 'no-store' not in headers.get('Cache-Control','').lower() or b'googletagmanager.com/gtag/js' in private:
        raise RuntimeError('Private client workspace privacy boundary failed')
    health, _ = read('/healthz')
    if json.loads(health).get('ok') is not True:
        raise RuntimeError('Database health is not ready')
    verify_reconciliation()
    print('PASS: public publishing routes, private intake/client isolation, approved logo hash, finished icon dimensions and database health.')

def snapshot() -> dict:
    data, headers = read('/api/visitor-statistics')
    if 'no-store' not in headers.get('Cache-Control','').lower():
        raise RuntimeError('Visitor snapshot is cacheable')
    result = json.loads(data)
    allowed = {'enabled','server_time','timezone','month_key','year_key','month_label','year_label','refresh_after','now','month','year','as_of'}
    if set(result) - allowed or not isinstance(result.get('server_time'),int) or abs(time.time()-result['server_time']) > 90:
        raise RuntimeError('Visitor endpoint schema or server freshness is invalid')
    local = datetime.fromtimestamp(result['server_time'],timezone.utc).astimezone(ZoneInfo(result['timezone']))
    if result['month_key'] != local.strftime('%Y-%m') or result['year_key'] != local.strftime('%Y') or result['month_label'] != local.strftime('%B') or result['year_label'] != local.strftime('%Y'):
        raise RuntimeError('Visitor labels do not match the current property-local calendar')
    for key in ['now','month','year']:
        value = result.get(key)
        if value is not None:
            at = result.get('as_of',{}).get(key)
            if type(value) is not int or value < 0 or type(at) is not int or not -5 <= result['server_time']-at <= (60 if key == 'now' else 900):
                raise RuntimeError('A visitor value is invalid or stale')
    return result

def main() -> None:
    for attempt in range(12):
        try:
            check_pages()
            break
        except Exception as error:
            print('Release visibility check',attempt+1,'not ready:',type(error).__name__)
            if attempt == 11:
                raise
            time.sleep(10)
    previous = None
    for attempt in range(16):
        current = snapshot()
        complete = current.get('enabled') is True and all(type(current.get(key)) is int for key in ['now','month','year'])
        if complete and previous is not None and current['as_of']['now'] > previous:
            print(json.dumps({'status':'verified','period':current['month_key'],'year':current['year_key'],'timezone':current['timezone'],'now':current['now'],'month':current['month'],'year_visitors':current['year'],'as_of':current['as_of'],'live_source_timestamp_advanced':True},sort_keys=True))
            return
        if complete and previous is None:
            previous = current['as_of']['now']
        print('Visitor source check',attempt+1,'waiting for fresh independent readings and a second live-source timestamp.')
        if attempt < 15:
            time.sleep(20)
    raise RuntimeError('Application pages passed, but automatic visitor-source refresh was not fully verified; inspect the protected visitor diagnostics.')

if __name__ == '__main__':
    main()
