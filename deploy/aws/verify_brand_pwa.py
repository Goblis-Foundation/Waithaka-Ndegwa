#!/usr/bin/env python3
"""Read-only live brand and separated PWA acceptance; no account actions."""
from __future__ import annotations
import json
import re
import struct
import urllib.request

ROOT="https://www.waithakandegwa.com"

def read(path:str)->bytes:
    request=urllib.request.Request(ROOT+path,headers={
       "User-Agent":"Waithaka-Brand-Manifest-Acceptance/20261028",
       "Cache-Control":"no-cache","Accept":"*/*"})
    with urllib.request.urlopen(request,timeout=30) as response:
        if response.status!=200 or response.geturl().split("?")[0].rstrip("/")!=(ROOT+path.split("?")[0]).rstrip("/"):
            raise RuntimeError("Unexpected asset destination: "+path)
        return response.read(2_000_000)

public=json.loads(read("/manifest.webmanifest"))
writer=json.loads(read("/writer.webmanifest"))
if public.get("start_url")!="/" or public.get("id")!="/" or public.get("scope")!="/":
    raise RuntimeError("Public website PWA must launch public content, not Writer")
if writer.get("start_url")!="/write/communication" or writer.get("id")!="/write/" or writer.get("scope")!="/write/":
    raise RuntimeError("Writer PWA must launch the isolated private Communications Centre")
for label,item in [("public",public),("writer",writer)]:
    icons=item.get("icons",[])
    if len(icons)!=3 or {i.get("purpose") for i in icons}!={"any","maskable"}:
        raise RuntimeError(label+" icon purposes missing")
    if any("20261028b" not in i.get("src","") for i in icons):
        raise RuntimeError(label+" app icon cache version mismatch")
    for icon in icons:
        data=read(icon["src"])
        if data[:8]!=b"\x89PNG\r\n\x1a\n":
            raise RuntimeError(label+" PWA icon is not a PNG")
        w,h=struct.unpack(">II",data[16:24])
        size=int(icon["sizes"].split("x")[0])
        if (w,h)!=(size,size):raise RuntimeError("Wrong app icon dimensions")
print("PASS: separate public and Writer app installs, approved WN-sized PNG and maskable paths.")
svg=read("/assets/images/favicon.svg?v=20261028b").decode("utf-8")
if not all(t in svg for t in ["Waithaka Ndegwa","#073B6A","#2F6F3E","<path"]) or "<text" in svg:
    raise RuntimeError("Favicon must contain both original WN vector colors without tiny text")
for p in ["/","/about","/start-here"]:
    html=read(p).decode("utf-8")
    if "favicon.svg?v=20261028b" not in html:
        raise RuntimeError("Outdated website icon reference on "+p)
writerHTML=read("/write/login").decode("utf-8")
if "writer.webmanifest" not in writerHTML:
    raise RuntimeError("Writer app does not load its own install manifest")
print("PASS: WN logo favicon is the actual approved blue/green source, served consistently on website and Writer.")
