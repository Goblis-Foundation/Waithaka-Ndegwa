#!/usr/bin/env python3
"""Read-only acceptance. Never submits a live brief, payment or client approval."""
from html.parser import HTMLParser
from urllib.parse import urlparse
from urllib.request import Request, urlopen

BASE = 'https://www.waithakandegwa.com'

class FormParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.steps = set()
        self.fields = set()
        self.assets = []
        self.form = False
        self.packages = 0
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if 'data-promote-step' in a:
            self.steps.add(a['data-promote-step'])
        if tag == 'form' and 'data-promote-form' in a:
            self.form = a.get('method','').lower() == 'post' and a.get('enctype') == 'multipart/form-data' and urlparse(a.get('action','')).path == '/promote'
        if tag in ('input','textarea','select') and a.get('name'):
            self.fields.add(a['name'])
        if tag == 'input' and a.get('name') == 'package' and a.get('type') == 'radio':
            self.packages += 1
        if tag in ('link','script'):
            link = a.get('href') or a.get('src') or ''
            if 'promotion-experience.' in link:
                self.assets.append(link)

def get(url):
    request = Request(url,headers={'User-Agent':'Waithaka-Promotion-Acceptance/1.0','Cache-Control':'no-cache'})
    with urlopen(request,timeout=25) as response:
        if response.status != 200 or urlparse(response.url).netloc != urlparse(BASE).netloc:
            raise RuntimeError('Unexpected promotion acceptance response')
        return response.read(2*1024*1024).decode('utf-8'),response.headers

def verify():
    html,headers = get(BASE+'/promote')
    parsed = FormParser(); parsed.feed(html)
    required = {'csrf','form_nonce','package','organisation','title','summary','description','sectors','audience','offer','benefits','conditions','purchase_url','cta_label','offer_expires','timezone','source_url','claims_evidence','sponsor_name','client_name','client_email','evidence','evidence_ack','authority_ack','privacy_ack','website_repeat'}
    if not parsed.form or parsed.steps != {'0','1','2','3','4'} or parsed.packages != 3 or not required <= parsed.fields:
        raise RuntimeError('Interactive promotion form or protected fields are missing')
    if 'no-store' not in headers.get('Cache-Control','') or 'googletagmanager.com/gtag/js' in html:
        raise RuntimeError('Private promotion intake privacy boundary changed')
    if len(parsed.assets) != 2:
        raise RuntimeError('Interactive promotion assets are missing')
    for link in parsed.assets:
        parts = urlparse(link)
        if parts.netloc and parts.netloc != urlparse(BASE).netloc:
            raise RuntimeError('Unexpected promotion asset origin')
        content,_ = get(BASE+parts.path+('?' + parts.query if parts.query else ''))
        marker = 'validateThrough' if parts.path.endswith('.js') else '.promote-stepper'
        if marker not in content:
            raise RuntimeError('Interactive promotion asset is not deployed')
    print('PASS: five-step promotion form, selectable packages, review controls, private intake and deployed interaction assets. No brief, email, charge or approval was submitted.')

if __name__ == '__main__':
    verify()
