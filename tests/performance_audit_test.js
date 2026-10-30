'use strict';
const assert = require('node:assert/strict');
const {ORIGIN,TARGETS,summarize,markdown,readMethod} = require('./performance_audit');
const fixture = () => ({requestedUrl:ORIGIN+'/',finalUrl:ORIGIN+'/',finalDisplayedUrl:ORIGIN+'/',fetchTime:'2026-10-30T18:00:00Z',lighthouseVersion:'13.6.0',categories:Object.fromEntries(['performance','accessibility','best-practices','seo'].map(k=>[k,{score:1}])),audits:{'http-status-code':{score:1},...Object.fromEntries(['largest-contentful-paint','first-contentful-paint','total-blocking-time','cumulative-layout-shift','speed-index'].map(k=>[k,{numericValue:0}]))}});
assert.deepEqual(TARGETS,['/','/start-here','/my-thoughts','/contact']);
for (const method of ['POST','PUT','DELETE','PATCH','OPTIONS','CONNECT']) assert.equal(readMethod(method),false);
for (const method of ['GET','HEAD']) assert.equal(readMethod(method),true);
const modern=fixture();delete modern.finalUrl;assert.equal(summarize(modern,'/','mobile').scores.performance,100);
const valid = summarize(fixture(),'/','mobile');assert.equal(valid.metrics.cls,0);assert.equal(valid.metrics.tbtMs,0);assert.equal(valid.scores.performance,100);
for (const url of [ORIGIN+'/write',ORIGIN+'/?token=private','https://example.invalid/']) {const f=fixture();f.finalDisplayedUrl=url;assert.throws(()=>summarize(f,'/','mobile'));}
assert.throws(()=>summarize(fixture(),'/write','mobile'));assert.throws(()=>summarize(fixture(),'/','phone'));
for (const edit of [f=>{f.runtimeError={code:'FAILED_DOCUMENT_REQUEST'};},f=>{f.categories.performance.score=null;},f=>{delete f.categories.seo;},f=>{f.audits['http-status-code'].score=0;},f=>{f.audits['largest-contentful-paint'].numericValue=null;},f=>{f.audits['total-blocking-time'].numericValue=NaN;},f=>{f.audits['speed-index'].numericValue=-1;}]) {const f=fixture();edit(f);assert.throws(()=>summarize(f,'/','desktop'));}
const slow=fixture();slow.audits['largest-contentful-paint'].numericValue=4000;slow.audits['total-blocking-time'].numericValue=350;slow.audits['cumulative-layout-shift'].numericValue=0.2;slow.categories.performance.score=0.6;slow.audits['render-blocking']={score:0,title:'Render blocking resources',metricSavings:{LCP:500}};
const measured=summarize(slow,'/','mobile');assert.equal(measured.warnings.length,4);assert.equal(measured.recommendations[0].id,'render-blocking');
const report=markdown({results:[valid,measured],errors:[{route:'/contact',device:'mobile',error:'private detail'}]});assert.match(report,/not real-user Core Web Vitals/);assert.match(report,/TBT is not INP/);assert.match(report,/Incomplete measurements/);assert.ok(!report.includes('private detail'));
console.log('PASS: performance measurement completeness, exact public URLs, no-write methods, zero/unknown metrics, advisory budgets and qualified lab reporting.');
