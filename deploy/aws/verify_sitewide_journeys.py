#!/usr/bin/env python3
"""Read-only production audit for the public website and entry-point journeys.

GET only: this test never submits forms, logs in, charges, emails, subscribes,
publishes, requests a meeting or invokes provider APIs. It cannot certify live
third-party delivery or genuine customer outcomes.
"""
from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor, as_completed
from html.parser import HTMLParser
from urllib.parse import urlsplit
from urllib.request import Request, urlopen
import sys

BASE="https://www.waithakandegwa.com"
PAGES={
    "/": ["/start-here","/my-thoughts","/about","/newsletter","/resources/problem-interview-worksheet"],
    "/start-here": ["/my-thoughts","/startup-journeys","/founders","/resources","/contact"],
    "/explore": ["/opportunities","/opportunities/jobs","/founders/submit","/promote","/contribute"],
    "/my-thoughts": [],
    "/startup-journeys": ["/founders","/newsletter"],
    "/founders": ["/founders/submit","/opportunities/submit"],
    "/founders/submit": [],
    "/resources": ["/start-here"],
    "/resources/problem-interview-worksheet": [],
    "/watch": ["/start-here"],
    "/enterprises": ["/about#goals-2030"],
    "/opportunities": ["/opportunities/jobs","/opportunities/submit","/partner-stories"],
    "/opportunities/jobs": ["/opportunities","/opportunities/submit"],
    "/opportunities/submit": [],
    "/partner-stories": ["/promote"],
    "/advertise": ["/promote","/advertising-policy"],
    "/advertising-policy": ["/contact","/promote"],
    "/promote": [],
    "/monthly-roundup": ["/newsletter"],
    "/about": ["/contact","/startup-journeys"],
    "/contact": ["/book","/founders"],
    "/book": ["/contact"],
    "/newsletter": ["/newsletter/preferences/request","/feed.xml","/privacy"],
    "/newsletter/preferences/request": [],
    "/contribute": [],
    "/professional-information": [],
    "/search": [],
    "/search?q=enterprise&type=post": [],
    "/privacy": [],
    "/cookies": [],
    "/legal": [],
    "/terms": [],
    "/contribution-terms": [],
    "/editorial-independence": [],
}
REQUIRED_FIELDS={
    "/contact": {"first_name","email","message","country","county","sub_county","ward"},
    "/newsletter": {"first_name","last_name","email","subscribe_ack"},
    "/founders/submit": {"submitter_name","submitter_email","venture_name","country","county","ward","privacy_ack"},
    "/opportunities/submit": {"organisation","title","contact_email","country","county","ward","privacy_ack","no_fee_ack"},
    "/promote": {"form_nonce","package","authority_ack","privacy_ack","country","county","ward"},
    "/contribute": {"contribution_acknowledgement"},
    "/search": {"q","type"},
    "/search?q=enterprise&type=post": {"q","type"},
}
PRIVATE_INTAKES={"/founders/submit","/opportunities/submit","/promote"}

class Page(HTMLParser):
    def __init__(self)->None:
        super().__init__(convert_charrefs=True)
        self.mains=0;self.headings=0;self.links=[];self.form_methods=[];self.fields=set()
        self.images_without_alt=[];self.canonical=[];self.robots=[]
        self.invalid_links=[];self.csrf=False
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=="main": self.mains+=1
        if tag=="h1": self.headings+=1
        if tag=="a":
            href=a.get("href","")
            if href.startswith(("javascript:","data:text/html")) or href.strip()=="":
                self.invalid_links.append(href)
            elif href: self.links.append(href)
        if tag=="form":
            self.form_methods.append(a.get("method","get").lower())
        if tag in {"input","select","textarea"}:
            key=a.get("name","")
            if key:self.fields.add(key)
            if "csrf" in key.lower():self.csrf=True
        if tag=="img" and "alt" not in a:
            self.images_without_alt.append(a.get("src","unlabeled"))
        if tag=="link" and a.get("rel")=="canonical":self.canonical.append(a.get("href"))
        if tag=="meta" and a.get("name")=="robots":self.robots.append(a.get("content",""))

def get_page(path:str)->tuple[str,Page,int]:
    url=BASE+path
    request=Request(url,headers={
        "User-Agent":"Waithaka-Public-Journey-Audit/2026-10-28",
        "Cache-Control":"no-cache","Accept":"text/html"
    },method="GET")
    with urlopen(request,timeout=35) as response:
        if response.status!=200:raise RuntimeError(f"HTTP {response.status} on {path}")
        destination=response.geturl()
        if urlsplit(destination).hostname!="www.waithakandegwa.com":
            raise RuntimeError(f"Unexpected destination for {path}")
        if "text/html" not in response.headers.get("Content-Type",""):
            raise RuntimeError(f"Not an HTML page: {path}")
        body=response.read(2_000_000)
        if len(body)>=2_000_000:raise RuntimeError(f"Page over bounded audit limit: {path}")
    html=body.decode("utf-8")
    parsed=Page()
    parsed.feed(html)
    return path,parsed,len(body)

def route_of(link:str)->str|None:
    if link.startswith("#"):
        return None
    if link.startswith("/"):
        return urlsplit(link).path+("#"+urlsplit(link).fragment if urlsplit(link).fragment else "")
    u=urlsplit(link)
    if u.scheme not in {"https","http"} or u.hostname!="www.waithakandegwa.com":
        return None
    return u.path+("#"+u.fragment if u.fragment else "")

def verify(path:str,page:Page,length:int)->list[str]:
    failures=[]
    if page.mains!=1:failures.append(f"exactly one main required (found {page.mains})")
    if page.headings!=1:failures.append(f"exactly one h1 required (found {page.headings})")
    if not page.canonical:failures.append("missing canonical URL")
    if page.invalid_links:failures.append("invalid empty or executable link")
    if page.images_without_alt:failures.append("image lacks alt: "+", ".join(page.images_without_alt[:3]))
    found={route_of(link) for link in page.links}
    for target in PAGES[path]:
        if target not in found:failures.append("missing next-step link to "+target)
    for field in REQUIRED_FIELDS.get(path,set()):
        if field not in page.fields:failures.append("missing form field "+field)
    if path in PRIVATE_INTAKES and not any("noindex" in r for r in page.robots):
        failures.append("private-intake page should not be indexed")
    if any(method=="post" for method in page.form_methods) and not page.csrf:
        failures.append("POST form has no CSRF field")
    print(f"{'PASS' if not failures else 'FAIL'}: {path}: {length} bytes; "
          f"{page.mains} main, {page.headings} h1, {len(page.links)} links, "
          f"{len(page.form_methods)} forms",flush=True)
    return [f"{path}: {item}" for item in failures]

def main()->None:
    # The pool makes this bounded audit practical without persistent crawling.
    # All targets are fixed and source-reviewable; no dynamic links are followed.
    failures=[]
    with ThreadPoolExecutor(max_workers=4) as pool:
        futures={pool.submit(get_page,path):path for path in PAGES}
        for future in as_completed(futures):
            path=futures[future]
            try:
                _,doc,length=future.result()
                failures.extend(verify(path,doc,length))
            except Exception as exc:
                failures.append(f"{path}: read-only audit could not complete: {type(exc).__name__}: {exc}")
    if failures:
        print("FAIL: live site inventory found "+str(len(failures))+" issues:",file=sys.stderr)
        for error in sorted(failures):print(" - "+error,file=sys.stderr)
        raise SystemExit(1)
    print("PASS: "+str(len(PAGES))+" live public pages and entry-point journeys, "
          "canonical/landmark/links/form contracts; GET only, no private user or provider action.")

if __name__=="__main__":
    main()
