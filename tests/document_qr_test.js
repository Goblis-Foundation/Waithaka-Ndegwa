'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const api=require('../assets/js/document-qr.js');
const qrcode=require('../assets/js/vendor/qrcode.js');
const origin='https://www.waithakandegwa.com';
for(const value of ['https://evil.example/doc','//evil.example/doc','javascript:alert(1)','https://user:pass@www.waithakandegwa.com/doc','/consent/secret','/founders/story-review','/founders/story-review?token=secret','/write/releases/1?token=secret','/about#secret']){
 assert.equal(api.safeDocumentUrl(value,origin),null,value);
}
for(const value of ['/professional-information','/write/releases/12','/write/journeys/founders/34']){
 const url=api.safeDocumentUrl(value,origin);
 assert.equal(url,origin+value);
 const svg=api.svgFor(url,qrcode);
 assert.match(svg,/shape-rendering="crispEdges"/);
 assert.match(svg,/fill="#fff"/);
 assert.match(svg,/fill="#000"/);
 const n=Number(svg.match(/viewBox="0 0 (\d+) /)[1]);
 const coordinates=Array.from(svg.matchAll(/M(\d+),(\d+)h1v1h-1z/g),m=>[+m[1],+m[2]]);
 assert.ok(coordinates.length>100);
 for(const [x,y] of coordinates){assert.ok(x>=4&&y>=4&&x<n-4&&y<n-4,'Four-module quiet zone');}
}
const css=fs.readFileSync(path.join(__dirname,'../assets/css/document.css'),'utf8');
assert.match(css,/@media print/);assert.match(css,/36mm/);assert.match(css,/break-inside:avoid/);
const template=fs.readFileSync(path.join(__dirname,'../views/document_record.php'),'utf8');
assert.ok(!template.includes('REQUEST_URI'));
assert.ok(!template.includes('public_token'));
assert.ok(!template.includes('fetch('));
console.log('Document QR URL privacy, actual encoding, quiet zone and print boundaries passed.');
