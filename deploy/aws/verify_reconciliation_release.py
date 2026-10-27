#!/usr/bin/env python3
"""Read-only acceptance of monthly archive, contact rules and uniform footer."""
from __future__ import annotations
import re
import urllib.error
import urllib.parse
import urllib.request
from verify_promotion_release import verify as verify_promotion
from verify_experience_release import verify as verify_experience

BASE = 'https://www.waithakandegwa.com'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None
OPENER = urllib.request.build_opener(NoRedirect())

def read(path: str, expected: int = 200):
    request = urllib.request.Request(BASE + path, headers={'User-Agent':'Waithaka-Reconciliation-Acceptance/1.0','Cache-Control':'no-cache'})
    try:
        response = OPENER.open(request, timeout=25)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        if response.code != expected:
            raise RuntimeError('Unexpected response for ' + path + ': ' + str(response.code))
        return response.read(2 * 1024 * 1024).decode('utf-8'), response.headers

def verify() -> None:
    home, _ = read('/')
    archive, _ = read('/monthly-roundup')
    if 'A new month.' not in archive or '1 November 2026' not in archive:
        raise RuntimeError('Monthly archive release is not visible')
    for path in ['/contact','/professional-information','/start-here']:
        text, _ = read(path)
        if re.search(r'Invite me to speak|Speaking / event invitation|speaking invitation|Speaking &amp; media background', text, re.I):
            raise RuntimeError('Retired invitation remains visible: ' + path)
        if 'Personal views.' not in text or 'brand-refresh.css' not in text:
            raise RuntimeError('Shared footer is not applied: ' + path)
    contact, _ = read('/contact?type=speaking')
    if 'value="speaking"' in contact or 'data-location-fields' not in contact or 'name="county"' not in contact or 'name="region"' not in contact:
        raise RuntimeError('Current contact purposes or shared location fields differ')
    if contact.count('<option value="Nyeri"') != 1 or 'name="phone_code"' not in contact:
        raise RuntimeError('County/phone controls are missing')
    match = re.search(r'href="([^"]*assets/css/brand-refresh\.css[^\"]*)"', home)
    if not match:
        raise RuntimeError('Current brand stylesheet is not referenced')
    url = urllib.parse.urlparse(match.group(1).replace('&amp;', '&'))
    if url.netloc and url.netloc != urllib.parse.urlparse(BASE).netloc:
        raise RuntimeError('Unexpected stylesheet origin')
    css, _ = read(url.path + ('?' + url.query if url.query else ''))
    if 'grid-template-columns:repeat(3,minmax(0,1fr))' not in css or 'body .site-footer .footer-note' not in css or 'font-variant-numeric:tabular-nums' not in css:
        raise RuntimeError('Uniform views/counter styles are not deployed')
    for path in ['/write/monthly-roundup','/write/correspondence','/write/reconciliation']:
        _, headers = read(path, 302)
        location = urllib.parse.urlparse(headers.get('Location','')).path
        if location != '/write/login' or 'no-store' not in headers.get('Cache-Control',''):
            raise RuntimeError('Protected workspace requires owner access: ' + path)
    _, headers = read('/client/client-contact/' + '0' * 32, 403)
    if 'no-store' not in headers.get('Cache-Control',''):
        raise RuntimeError('Client location route is not private')
    verify_promotion()
    verify_experience()
    print('PASS: monthly archive, retired speaking invitations, shared country/county controls, uniform views/counter footer and private workspace boundaries. No form, email or newsletter was submitted.')

if __name__ == '__main__':
    verify()
