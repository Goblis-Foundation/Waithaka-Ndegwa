'use strict';

/**
 * Read-only synthetic acceptance for the public Waithaka Ndegwa site.
 * No credentials, login attempts, content submission, payment or posting.
 * Lab timings and axe findings are signals, not physical-device or field-CWV proof.
 */
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const axeSource = require('axe-core').source;

const ORIGIN = 'https://www.waithakandegwa.com';
const output = process.env.SENTINEL_EVIDENCE_DIR || path.join(process.cwd(), 'live-sentinel-evidence');
const targets = [
  { slug: 'home', path: '/', public: true },
  { slug: 'start-here', path: '/start-here', public: true },
  { slug: 'explore', path: '/explore', public: true },
  { slug: 'search', path: '/search', public: true },
  { slug: 'contact', path: '/contact', public: true },
  { slug: 'book', path: '/book', public: true },
  { slug: 'contribute', path: '/contribute', public: true },
  { slug: 'newsletter', path: '/newsletter', public: true },
  { slug: 'founder-submit', path: '/founders/submit', public: true },
  { slug: 'opportunity-submit', path: '/opportunities/submit', public: true },
  { slug: 'opportunities', path: '/opportunities', public: true },
  { slug: 'login', path: '/write/login', public: false }
];
const screens = [
  { name: 'small-mobile', width: 320, height: 700 },
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1366, height: 768 }
];

function limited(message) {
  return String(message || '').replace(/[\r\n]+/g, ' ').slice(0, 220);
}

async function inspect(browser, target, screen) {
  const label = target.slug + '-' + screen.name;
  const result = {
    path: target.path, viewport: screen.name, width: screen.width,
    status: null, finalPath: null, title: '', metrics: {},
    accessibility: { violations: [], incomplete: 0 },
    pageErrors: [], failures: [], warnings: []
  };
  const context = await browser.newContext({
    viewport: { width: screen.width, height: screen.height },
    deviceScaleFactor: 1, reducedMotion: 'reduce',
    serviceWorkers: 'block', locale: 'en-KE'
  });
  try {
    // This monitor only reads pages. Block unexpected write methods, including beacons.
    await context.route('**/*', route => {
      const method = route.request().method();
      if (method === 'GET' || method === 'HEAD') return route.continue();
      return route.abort('blockedbyclient');
    });
    await context.addInitScript(() => {
      window.__wnSynthetic = { lcp: null, cls: 0 };
      try {
        new PerformanceObserver(list => {
          for (const entry of list.getEntries()) window.__wnSynthetic.lcp = entry.startTime;
        }).observe({ type: 'largest-contentful-paint', buffered: true });
      } catch (_) { /* unsupported browser entry type */ }
      try {
        new PerformanceObserver(list => {
          for (const entry of list.getEntries()) {
            if (!entry.hadRecentInput) window.__wnSynthetic.cls += entry.value;
          }
        }).observe({ type: 'layout-shift', buffered: true });
      } catch (_) { /* unsupported browser entry type */ }
    });
    const page = await context.newPage();
    page.on('pageerror', error => {
      if (result.pageErrors.length < 5) result.pageErrors.push(limited(error.message));
    });
    const response = await page.goto(ORIGIN + target.path, {
      waitUntil: 'domcontentloaded', timeout: 30000
    });
    result.status = response ? response.status() : null;
    await page.waitForTimeout(800);
    const final = new URL(page.url());
    result.finalPath = final.pathname;
    if (final.origin !== ORIGIN) result.failures.push('Navigation left the canonical HTTPS origin.');
    if (result.status !== 200) result.failures.push('Expected HTTP 200; received ' + result.status + '.');

    const observed = await page.evaluate(() => {
      const nav = performance.getEntriesByType('navigation')[0];
      const fcp = performance.getEntriesByName('first-contentful-paint')[0];
      const main = document.querySelectorAll('main');
      const headings = document.querySelectorAll('h1');
      const canonical = document.querySelector('link[rel="canonical"]');
      const robots = document.querySelector('meta[name="robots"]');
      const width = Math.max(document.documentElement.scrollWidth, document.body ? document.body.scrollWidth : 0);
      return {
        title: document.title || '',
        mainCount: main.length,
        h1Count: headings.length,
        formCount: document.querySelectorAll('form').length,
        scrollWidth: width,
        canonical: canonical ? canonical.href : '',
        robots: robots ? robots.content : '',
        ttfbMs: nav ? Math.round(nav.responseStart) : null,
        domContentLoadedMs: nav ? Math.round(nav.domContentLoadedEventEnd) : null,
        fcpMs: fcp ? Math.round(fcp.startTime) : null,
        lcpMs: window.__wnSynthetic ? window.__wnSynthetic.lcp : null,
        cls: window.__wnSynthetic ? window.__wnSynthetic.cls : null
      };
    });
    result.title = observed.title;
    result.metrics = {
      ttfbMs: observed.ttfbMs,
      domContentLoadedMs: observed.domContentLoadedMs,
      fcpMs: observed.fcpMs,
      lcpMs: observed.lcpMs === null ? null : Math.round(observed.lcpMs),
      cls: observed.cls === null ? null : Math.round(observed.cls * 10000) / 10000
    };
    result.formCount = observed.formCount;
    if (observed.mainCount !== 1) result.failures.push('Expected exactly one main landmark.');
    if (observed.h1Count !== 1) result.failures.push('Expected exactly one H1.');
    if (observed.scrollWidth > screen.width + 2) {
      result.failures.push('Horizontal overflow: ' + observed.scrollWidth + 'px in ' + screen.width + 'px viewport.');
    }
    if (target.public && observed.canonical) {
      try {
        if (new URL(observed.canonical).origin !== ORIGIN) {
          result.failures.push('Canonical link points outside the approved origin.');
        }
      } catch (_) { result.failures.push('Canonical link is malformed.'); }
    }
    if (!target.public) {
      const headers = response ? response.headers() : {};
      const robots = (observed.robots + ',' + (headers['x-robots-tag'] || '')).toLowerCase();
      if (!robots.includes('noindex')) result.failures.push('Private login page must be noindex.');
      if (!/private|no-store/i.test(headers['cache-control'] || '')) {
        result.failures.push('Private login page must disallow shared caching.');
      }
    }

    try {
      await page.addScriptTag({ content: axeSource });
      const audit = await page.evaluate(async () => {
        return window.axe.run(document, {
          runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] }
        });
      });
      result.accessibility = {
        violations: audit.violations.map(v => ({ rule: v.id, impact: v.impact, elements: v.nodes.length })),
        incomplete: audit.incomplete.length
      };
      for (const violation of result.accessibility.violations) {
        result.failures.push('Automated WCAG A/AA rule violation: ' + violation.rule +
          ' (' + violation.elements + ' elements; ' + (violation.impact || 'impact not returned') + ').');
      }
    } catch (error) {
      result.failures.push('Accessibility scan did not complete: ' + limited(error.message));
    }
    try {
      await page.screenshot({ path: path.join(output, label + '.png'), animations: 'disabled' });
    } catch (error) {
      result.warnings.push('Screenshot unavailable: ' + limited(error.message));
    }
  } catch (error) {
    result.failures.push('Read-only probe failed: ' + limited(error.message));
  } finally {
    await context.close();
  }
  console.log((result.failures.length ? 'FAIL ' : 'PASS ') + label +
    ' · HTTP ' + result.status + ' · ' + result.failures.length + ' acceptance failures · ' +
    result.accessibility.violations.length + ' axe rules for review');
  return result;
}

async function main() {
  fs.mkdirSync(output, { recursive: true });
  const report = {
    purpose: 'Public read-only synthetic monitoring, not delivery/provider acceptance',
    timestampUtc: new Date().toISOString(),
    origin: ORIGIN,
    commit: process.env.GITHUB_SHA || '',
    note: 'Browser lab timings are not CrUX field Core Web Vitals. Form pages were rendered only; no form was submitted. Axe rules require manual review; a clean scan is not an accessibility certification.',
    checks: [], summary: {}
  };
  let browser;
  try {
    browser = await chromium.launch({ headless: true });
    for (const screen of screens) {
      for (const target of targets) {
        report.checks.push(await inspect(browser, target, screen));
      }
    }
  } catch (error) {
    report.fatal = limited(error.message);
  } finally {
    if (browser) await browser.close();
    report.summary = {
      checks: report.checks.length,
      failedChecks: report.checks.filter(x => x.failures.length > 0).length,
      axeRulesForReview: report.checks.reduce((sum, x) => sum + x.accessibility.violations.length, 0)
    };
    fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify(report, null, 2) + '\n');
  }
  if (report.fatal || report.summary.checks !== targets.length * screens.length ||
      report.summary.failedChecks > 0) {
    process.exitCode = 1;
  }
}

main().catch(error => {
  console.error('Sentinel could not complete: ' + limited(error.message));
  process.exitCode = 1;
});
