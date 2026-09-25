'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assets = path.join(__dirname, '../../retrovault-core/assets');
let base, version = 'one', enabled = true, account = '', romStatus = 200;
const token = 'a'.repeat(32);
const game = '/games/demo/';
const player = game + 'play/';
const rom = () => base + game + 'rom/' + token + '/demo.nes?v=' + version;
const server = http.createServer((req, res) => {
  const url = new URL(req.url, base);
  if (url.searchParams.has('rv_sw')) {
    res.writeHead(200, { 'Content-Type': 'application/javascript', 'Service-Worker-Allowed': '/', 'Cache-Control': 'no-cache' });
    res.end('self.RV_SW = ' + JSON.stringify({ version: 'test-1.7.2', dataPath: base + '/data/', uploads: '/uploads/', romExt: ['nes'], skip: ['/wp-login.php', '/account/', '/wp-json/'], precache: [], offline: '/offline/', maxPages: 60, maxShell: 300 }) + ';\n' + fs.readFileSync(path.join(assets, 'sw.js'))); return;
  }
  if (url.pathname.startsWith('/assets/')) {
    const name = path.basename(url.pathname);
    if (!['pwa.js', 'cloud-saves.js'].includes(name)) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': 'application/javascript' }); res.end(fs.readFileSync(path.join(assets, name))); return;
  }
  if (url.pathname === '/data/loader.js') {
    res.writeHead(200, { 'Content-Type': 'application/javascript' });
    res.end("Promise.all([fetch(window.gameRom).then(r => {if(!r.ok)throw Error('rom');return r.arrayBuffer();}), fetch('/data/core.wasm').then(r => r.arrayBuffer())]).then(() => {window.started=true;window.offlineResult=window.RV_markOffline();}).catch(e => {window.loadError=e.message;});"); return;
  }
  if (url.pathname === '/data/core.wasm' || url.pathname.includes('/rom/')) {
    if (url.pathname.includes('/rom/') && romStatus !== 200) { res.writeHead(romStatus); res.end('denied'); return; }
    res.writeHead(200, { 'Content-Type': 'application/octet-stream' }); res.end(Buffer.from([1, 2, 3, 4])); return;
  }
  if (url.pathname === game || url.pathname === player) {
    const config = { enabled, sw: base + '/?rv_sw=1', scope: '/', cache: 'rv-offline', dataPath: base + '/data/', assets: base + '/assets/', user: account };
    const marker = { key: '/__rv-offline/42', data: { id: 42, title: 'Demo', url: base + game, short: 'NES', color: '#555' }, rom: rom(), version };
    res.writeHead(200, { 'Content-Type': 'text/html' });
    res.end('<!doctype html><html><head><meta charset="utf-8"></head><body><span data-rv-offline-badge data-key="/__rv-offline/42" data-version="' + version + '" hidden>Ready offline</span><script>window.RVPWA=' + JSON.stringify(config) + ';window.RVOfflineGame=' + JSON.stringify(marker) + ';window.gameRom=' + JSON.stringify(rom()) + ';</script><script src="/assets/pwa.js"></script>' + (url.pathname === player ? '<script src="/data/loader.js"></script>' : '') + '</body></html>'); return;
  }
  res.writeHead(404); res.end('missing');
});
(async () => {
  await new Promise(r => server.listen(0, '127.0.0.1', r));
  base = 'http://127.0.0.1:' + server.address().port;
  let browser;
  try {
    browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const context = await browser.newContext();
    const page = await context.newPage();
    page.on('pageerror', e => { throw e; });
    await page.goto(base + game);
    await page.waitForFunction(() => !!navigator.serviceWorker.controller);
    await page.goto(base + player);
    await page.waitForFunction(() => window.started === true);
    assert.equal(await page.evaluate(() => window.offlineResult), true);
    console.log('PASS: a game becomes ready only after cached assets and both pages exist.');
    assert.equal((await page.goto(rom())).status(), 403);
    console.log('PASS: opening the cached ROM as a document is denied online.');
    await context.setOffline(true);
    assert.equal((await page.goto(rom())).status(), 403);
    console.log('PASS: opening the cached ROM as a document is denied offline.');
    await page.goto(base + game);
    await page.waitForFunction(() => !document.querySelector('[data-rv-offline-badge]').hidden);
    await page.goto(base + player);
    await page.waitForFunction(() => window.started === true);
    assert.equal(await page.evaluate(() => window.offlineResult), true);
    console.log('PASS: cached player, ROM and emulator assets load with the network disabled.');
    await page.evaluate(async () => {
      const cache = await caches.open('rv-games');
      for (const request of await cache.keys()) { if (request.url.includes('/rom/')) await cache.delete(request); }
    });
    await page.goto(base + game);
    await page.waitForFunction(async () => !(await (await caches.open('rv-offline')).match('/__rv-offline/42')));
    assert.equal(await page.locator('[data-rv-offline-badge]').isHidden(), true);
    console.log('PASS: missing cached ROM invalidates the receipt and hides the badge.');
    await context.setOffline(false);
    await page.goto(base + player); await page.waitForFunction(() => window.started === true);
    assert.equal(await page.evaluate(() => window.offlineResult), true);
    romStatus = 403;
    assert.equal(await page.evaluate(async url => (await fetch(url)).status, rom()), 403);
    assert.equal(await page.evaluate(async url => !!(await (await caches.open('rv-games')).match(url.replace(/\/rom\/[a-f0-9]{32}\//, '/rom/-/'))), rom()), false);
    console.log('PASS: an explicit server denial is preserved and removes the cached ROM.');
    romStatus = 200;
    await page.goto(base + player); await page.waitForFunction(() => window.started === true);
    assert.equal(await page.evaluate(() => window.offlineResult), true);
    version = 'two'; await page.goto(base + game);
    await page.waitForFunction(async () => !(await (await caches.open('rv-offline')).match('/__rv-offline/42')));
    assert.equal(await page.locator('[data-rv-offline-badge]').isHidden(), true);
    console.log('PASS: a game update invalidates the old offline badge.');
    await page.goto(base + player); await page.waitForFunction(() => window.started === true);
    assert.equal(await page.evaluate(() => window.offlineResult), true);
    account = 'new-account'; await page.goto(base + game);
    await page.waitForFunction(async () => {
      for (const key of await caches.keys()) {
        if (!key.startsWith('rv-pages')) continue;
        if ((await (await caches.open(key)).keys()).some(r => r.url.includes('/play/'))) return false;
      }
      return true;
    });
    console.log('PASS: switching accounts removes cached player pages.');
    enabled = false; await page.goto(base + game);
    await page.waitForFunction(async () => (await navigator.serviceWorker.getRegistrations()).length === 0);
    await page.waitForFunction(async () => !(await caches.keys()).some(k => k.startsWith('rv-')));
    console.log('PASS: disabling PWA unregisters the worker and clears its caches.');
    await context.close();
  } finally {
    if (browser) await browser.close();
    await new Promise(r => server.close(r));
  }
})().catch(err => { console.error(err); process.exitCode = 1; });
