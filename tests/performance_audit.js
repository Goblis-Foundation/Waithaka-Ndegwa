'use strict';

// Anonymous public-page measurements. Fresh Chrome profiles, no login or form interaction.
const fs = require('node:fs');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const ORIGIN = 'https://www.waithakandegwa.com';
const TARGETS = Object.freeze(['/', '/start-here', '/my-thoughts', '/contact']);
const CATEGORIES = ['performance', 'accessibility', 'best-practices', 'seo'];
const finite = value => typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;
const readMethod = method => method === 'GET' || method === 'HEAD';

function summarize(lhr, route, device) {
  if (!TARGETS.includes(route) || !['mobile','desktop'].includes(device)) throw new Error('Unapproved audit target.');
  const expected = ORIGIN + route;
  if (!lhr || lhr.runtimeError || lhr.requestedUrl !== expected || lhr.finalDisplayedUrl !== expected || (lhr.finalUrl !== undefined && lhr.finalUrl !== expected)) throw new Error('Audit failed or navigated away from the exact public page.');
  if (lhr.audits?.['http-status-code']?.score !== 1) throw new Error('Public page did not pass the HTTP response check.');
  const scores = {};
  for (const name of CATEGORIES) {
    const score = lhr.categories?.[name]?.score;
    if (typeof score !== 'number' || !Number.isFinite(score) || score < 0 || score > 1) throw new Error('Audit category did not return a usable score: ' + name);
    scores[name] = Math.round(score * 100);
  }
  const metrics = {};
  for (const [name,id] of Object.entries({lcpMs:'largest-contentful-paint',fcpMs:'first-contentful-paint',tbtMs:'total-blocking-time',cls:'cumulative-layout-shift',speedIndexMs:'speed-index'})) metrics[name] = finite(lhr.audits?.[id]?.numericValue);
  if (Object.values(metrics).some(value => value === null)) throw new Error('Performance metrics were not measured completely.');
  const recommendations = Object.entries(lhr.audits || {})
    .filter(([,a]) => typeof a.score === 'number' && a.score < 1 && a.scoreDisplayMode !== 'notApplicable')
    .map(([id,a]) => ({id,title:String(a.title || id).slice(0,200),savingsMs:finite(a.metricSavings?.LCP ?? a.details?.overallSavingsMs),savingsBytes:finite(a.details?.overallSavingsBytes)}))
    .sort((a,b) => (b.savingsMs || 0) - (a.savingsMs || 0)).slice(0,12);
  const warnings = [];
  if (metrics.lcpMs > 2500) warnings.push('Synthetic LCP exceeds 2.5 seconds.');
  if (metrics.cls > 0.1) warnings.push('Synthetic CLS exceeds 0.1.');
  if (metrics.tbtMs > 200) warnings.push('Synthetic total blocking time exceeds the 200 ms review budget. TBT is not INP.');
  for (const name of CATEGORIES) if (scores[name] < 90) warnings.push(name + ' score is below the 90-point review target.');
  return {route,device,url:expected,measuredAt:lhr.fetchTime,lighthouseVersion:lhr.lighthouseVersion,metrics,scores,recommendations,warnings};
}

function markdown(report) {
  const rows = ['# Public performance audit', '', 'Anonymous, simulated browser measurements. These are not real-user Core Web Vitals, physical-device tests, or a deployment receipt.', '', '| Page | Device | Performance | Accessibility | Best practices | SEO | LCP ms | TBT ms | CLS |', '| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |'];
  for (const row of report.results) rows.push(`| ${row.route} | ${row.device} | ${row.scores.performance} | ${row.scores.accessibility} | ${row.scores['best-practices']} | ${row.scores.seo} | ${Math.round(row.metrics.lcpMs)} | ${Math.round(row.metrics.tbtMs)} | ${row.metrics.cls.toFixed(3)} |`);
  for (const row of report.results) if (row.warnings.length) rows.push('', `**${row.route} · ${row.device}**`, ...row.warnings.map(w => '- ' + w));
  if (report.errors.length) rows.push('', '## Incomplete measurements', ...report.errors.map(e => `- ${e.route} · ${e.device}: measurement failed; inspect the workflow log.`));
  rows.push('', 'Scores and budgets are review signals. Missing measurements fail the audit job; low scores are retained for investigation. No field INP or CrUX result is inferred. Full Lighthouse HTML and JSON reports are included in the artifact.', '');
  return rows.join('\n');
}

async function closeAuditBrowser(guard,chrome) {
  try { if(guard) await guard.close(); } catch (_) { /* Still terminate our own Chrome process. */ }
  try { if(chrome) await chrome.kill(); } catch (_) { /* Runner process cleanup remains a final fallback. */ }
}

async function main() {
  const output = process.env.PERFORMANCE_EVIDENCE_DIR;
  if (!output || !path.isAbsolute(output)) throw new Error('Set an absolute, disposable performance evidence directory.');
  const {default:lighthouse} = await import(pathToFileURL(require.resolve('lighthouse')).href);
  const {default:desktopConfig} = await import(pathToFileURL(require.resolve('lighthouse/core/config/desktop-config.js')).href);
  const chromeLauncher = await import(pathToFileURL(require.resolve('chrome-launcher')).href);
  const {chromium} = require('playwright');
  fs.mkdirSync(output,{recursive:true});
  const report = {observedAt:new Date().toISOString(),auditCodeCommit:process.env.GITHUB_SHA || '',origin:ORIGIN,lighthouseVersion:'13.6.0',results:[],errors:[],note:'Audit source commit does not identify the deployed site version. Fixed public pages only; cold Chrome profiles; simulated mobile/desktop; all non-GET/HEAD browser requests blocked. No credentials, form submissions, publication or payments.'};
  try {
    for (const route of TARGETS) for (const device of ['mobile','desktop']) {
      const label = (route === '/' ? 'home' : route.slice(1)) + '-' + device;
      let chrome, guard;
      try {
        chrome = await chromeLauncher.launch({chromePath:chromium.executablePath(),connectionPollInterval:500,maxConnectionRetries:20,chromeFlags:['--headless','--no-sandbox','--disable-dev-shm-usage']});
        guard = await chromium.connectOverCDP('http://127.0.0.1:' + chrome.port);
        let blockedWrites = 0;
        await guard.contexts()[0].route('**/*',request => {
          if (readMethod(request.request().method())) return request.continue();
          blockedWrites++;
          return request.abort('blockedbyclient');
        });
        const result = await lighthouse(ORIGIN + route,{port:chrome.port,logLevel:'error',output:['html','json'],onlyCategories:CATEGORIES,maxWaitForLoad:45000},device === 'desktop' ? desktopConfig : undefined);
        if (result?.lhr?.lighthouseVersion !== '13.6.0') throw new Error('Unexpected Lighthouse version.');
        const row = summarize(result?.lhr,route,device);
        row.blockedWriteRequests = blockedWrites;
        report.results.push(row);
        fs.writeFileSync(path.join(output,label+'.html'),result.report[0]);
        fs.writeFileSync(path.join(output,label+'.json'),result.report[1]);
        console.log(`${label}: performance ${row.scores.performance}, LCP ${Math.round(row.metrics.lcpMs)} ms, TBT ${Math.round(row.metrics.tbtMs)} ms, CLS ${row.metrics.cls.toFixed(3)}`);
      } catch (error) {
        report.errors.push({route,device,error:String(error.message || error).replace(/[\r\n]+/g,' ').slice(0,250)});
        console.error('Incomplete measurement: ' + label + ': ' + report.errors.at(-1).error);
      } finally {
        await closeAuditBrowser(guard,chrome);
      }
      fs.writeFileSync(path.join(output,'summary.json'),JSON.stringify(report,null,2));
    }
  } finally {
    const summary = markdown(report);
    fs.writeFileSync(path.join(output,'summary.md'),summary);
    if (process.env.GITHUB_STEP_SUMMARY) fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY,summary);
  }
  if (report.errors.length || report.results.length !== 8) throw new Error('Public performance audit did not complete all eight measurements.');
}

module.exports = {ORIGIN,TARGETS,summarize,markdown,readMethod,closeAuditBrowser};
if (require.main === module) main().catch(error => {console.error(error.message);process.exitCode=1;});
