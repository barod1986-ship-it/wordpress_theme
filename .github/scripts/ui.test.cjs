'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const base = process.env.RV_UI_BASE || 'http://localhost:8089';
const out = path.resolve(process.env.RV_UI_OUTPUT || 'ui-review');
fs.mkdirSync(out, { recursive: true });
const report = { pages: [], checks: [], errors: [] };
const check = async (name, fn) => {
  try { await fn(); report.checks.push({ name, pass: true }); }
  catch (error) { report.checks.push({ name, pass: false, error: error.message }); }
};
(async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  try {
    for (const [device, width, height] of [['desktop', 1440, 1000], ['mobile', 390, 844], ['narrow', 320, 800], ['tablet', 768, 1024]]) {
      const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 1, colorScheme: 'light', serviceWorkers: 'block' });
      const page = await context.newPage();
      page.on('pageerror', error => report.errors.push({ device, error: error.message }));
      await page.addInitScript(() => {
        window.rvVitals = { cls: 0, lcp: 0 };
        try { new PerformanceObserver(list => { for (const e of list.getEntries()) if (!e.hadRecentInput) window.rvVitals.cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); } catch {}
        try { new PerformanceObserver(list => { for (const e of list.getEntries()) window.rvVitals.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true }); } catch {}
      });
      for (const [name, route] of [['home', '/'], ['library', '/games/'], ['game', '/games/pixel-quest/']]) {
        await page.goto(base + route, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        const metrics = await page.evaluate(() => ({
          server: JSON.parse(document.getElementById('rv-ui-metrics').textContent),
          vitals: window.rvVitals,
          width: innerWidth,
          documentWidth: document.documentElement.scrollWidth,
          overflows: [...document.querySelectorAll('body *')].filter(e => { const r = e.getBoundingClientRect(); return r.width && (r.right > innerWidth + 1 || r.left < -1) && !e.closest('.shelf__track,.slots,.screen-reader-text,.mc__preview'); }).slice(0, 12).map(e => e.className),
          fontPreloads: document.querySelectorAll('link[rel="preload"][as="font"]').length,
          eagerImages: [...document.images].filter(i => i.loading !== 'lazy').length,
          resources: performance.getEntriesByType('resource').map(r => ({ url: r.name, bytes: r.transferSize, type: r.initiatorType })),
          playerFrames: document.querySelectorAll('.rv-player__frame').length
        }));
        report.pages.push({ device, name, ...metrics });
        await check(device + ' ' + name + ' has no horizontal page overflow', () => assert.ok(metrics.documentWidth <= width + 1, JSON.stringify(metrics.overflows)));
        await check(device + ' ' + name + ' defers emulator loading', () => { assert.equal(metrics.playerFrames, 0); assert.ok(!metrics.resources.some(r => /emulatorjs.*\/data\//.test(r.url))); });
        if (device === 'desktop' || device === 'mobile') await page.screenshot({ path: path.join(out, name + '-' + device + '.png'), fullPage: true });
      }
      if (device === 'desktop') {
        await page.goto(base + '/games/');
        await page.locator('#f-system').selectOption('nes');
        await page.waitForURL('**/games/?system=nes');
        await page.waitForFunction(() => !document.querySelector('#rv-results').hasAttribute('aria-busy'));
        await check('AJAX filter creates a visible reset link', async () => assert.equal(await page.locator('.filters__reset').count(), 1));
        await check('AJAX filter updates the selected system shortcut', async () => assert.equal(await page.locator('.slot[aria-current="page"] .slot__short').textContent(), 'NES'));
        await page.goto(base + '/system/nes/');
        await page.locator('#f-sort').selectOption('rating');
        await page.waitForURL(/sort=rating/);
        await page.goBack();
        await page.waitForFunction(() => !document.querySelector('#rv-results').hasAttribute('aria-busy'));
        await check('browser back restores the taxonomy filter', async () => assert.equal(await page.locator('#f-system').inputValue(), 'nes'));
        await page.screenshot({ path: path.join(out, 'library-filter-back.png'), fullPage: true });
      }
      await context.close();
    }
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } });
    const page = await context.newPage(); await page.goto(base + '/');
    await check('all home menu links remain keyboard reachable without JavaScript', async () => assert.equal(await page.locator('[data-mc-item][tabindex="-1"]').count(), 0));
    await context.close();
    await check('pages have no JavaScript errors', () => assert.deepEqual(report.errors, []));
  } finally {
    fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify({ pages: report.pages.map(({ resources, ...p }) => ({ ...p, requests: resources.length, bytes: resources.reduce((sum, r) => sum + r.bytes, 0) })), checks: report.checks, errors: report.errors }, null, 2));
    await browser.close();
  }
  if (report.checks.some(c => !c.pass)) process.exitCode = 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
