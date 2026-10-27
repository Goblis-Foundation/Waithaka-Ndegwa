#!/usr/bin/env python3
"""Read-only public release acceptance. Never submits a real enquiry or payment."""
from html.parser import HTMLParser
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
from urllib.parse import urlparse
import xml.etree.ElementTree as ET
BASE='https://www.waithakandegwa.com'
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return None
opener=build_opener(NoRedirect())
def read(path, expected=200):
    try: response=opener.open(Request(BASE+path,headers={'User-Agent':'Waithaka-Experience-Acceptance/1.0','Cache-Control':'no-cache'}),timeout=25)
    except HTTPError as error: response=error
    with response:
        if response.code!=expected: raise RuntimeError('Unexpected response '+path+': '+str(response.code))
        return response.read(2*1024*1024).decode('utf-8'),response.headers
class Page(HTMLParser):
    def __init__(self):
        super().__init__();self.canonicals=[];self.h1=0;self.robots='';self.country=False;self.phone=False;self.fields=set();self.breadcrumb=False
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='h1': self.h1+=1
        if tag=='link' and a.get('rel')=='canonical':self.canonicals.append(a.get('href'))
        if tag=='meta' and a.get('name')=='robots':self.robots=a.get('content','')
        if tag=='option' and a.get('value')=='Kenya' and 'selected' in a:self.country=True
        if tag=='input' and a.get('name')=='phone_code' and a.get('value')=='+254':self.phone=True
        if tag in ['input','select','textarea']:self.fields.add(a.get('name',''))
        if tag=='nav' and a.get('aria-label')=='Breadcrumb':self.breadcrumb=True

def verify():
    for path,marker in [('/explore','What would you like to do?'),('/advertise','Suitability comes before payment.'),('/advertising-policy','Advertising with care.')]:
        html,_=read(path);p=Page();p.feed(html)
        if marker not in html or p.h1!=1 or p.canonicals!=[BASE+path] or 'noindex' in p.robots or not p.breadcrumb or 'BreadcrumbList' not in html:
            raise RuntimeError('Public page/SEO acceptance failed: '+path)
        if 'unified-experience.css' not in html or 'unified-experience.js' not in html:raise RuntimeError('Shared experience assets missing')
    html,headers=read('/promote');p=Page();p.feed(html)
    if not p.country or not p.phone or not {'county','sub_county','city','phone','advertising_category','suitability_ack'}<=p.fields or 'Town / Area' not in html:
        raise RuntimeError('Kenya-first intake or suitability controls missing')
    if 'no-store' not in headers.get('Cache-Control','') or 'noindex' not in p.robots:raise RuntimeError('Private intake boundary changed')
    home,_=read('/');about,_=read('/about')
    for marker in ['A meaningful life,','shared with others.']:
        if marker not in home or marker not in about:raise RuntimeError('Personal-purpose enrichment missing')
    for marker in ['My faith in God','People and nature in harmony','full high school education','The Riara University','Goal 2030','Why I write']:
        if marker not in about:raise RuntimeError('Required personal story/purpose marker missing')
    xml,_=read('/publishing-sitemap.xml');root=ET.fromstring(xml);urls=[n.text for n in root.findall('.//{http://www.sitemaps.org/schemas/sitemap/0.9}loc')]
    if not {BASE+'/explore',BASE+'/advertise',BASE+'/advertising-policy',BASE+'/monthly-roundup'}<=set(urls):raise RuntimeError('Public discovery routes absent from sitemap')
    if any(urlparse(u).path.startswith(('/promote','/client','/write')) or '?' in u for u in urls):raise RuntimeError('Private or parameterised URL in public sitemap')
    for path in ['/write/control-centre','/write/seo-checks']:
        _,h=read(path,302)
        if urlparse(h.get('Location','')).path!='/write/login' or 'no-store' not in h.get('Cache-Control',''):raise RuntimeError('Owner workspace boundary failed')
    print('PASS: Kenya-default contact controls, suitability policy, public journeys, consistent assets, personal purpose, canonical/breadcrumb SEO, public-only sitemap and protected owner routes. No mutation performed.')
if __name__=='__main__':verify()
