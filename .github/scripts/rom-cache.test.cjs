'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../retrovault-core/assets/sw.js'), 'utf8');
const origin = 'https://games.example';
const url = origin + '/games/demo/rom/' + 'a'.repeat(32) + '/demo.nes?v=1';
const key = url.replace(/\/rom\/[a-f0-9]{32}\//, '/rom/-/');

function worker(reply, config = {}) {
  const listeners = {}, stores = new Map();
  const address = request => typeof request === 'string' ? request : request.url;
  const caches = {
    async open(name) {
      if (!stores.has(name)) stores.set(name, new Map());
      const data = stores.get(name);
      return {
        async match(request, opts) {
          const key = address(request);
          if (data.has(key)) return data.get(key).clone();
          if (opts && opts.ignoreSearch) { for (const [k, v] of data) { if (k.split('?')[0] === key.split('?')[0]) return v.clone(); } }
          return undefined;
        },
        async put(request, response) { data.set(address(request), response.clone()); },
        async delete(request) { return data.delete(address(request)); },
        async keys() { return [...data.keys()].map(url => ({ url })); }
      };
    },
    async keys() { return [...stores.keys()]; },
    async delete(name) { return stores.delete(name); },
    async match(request) { for (const data of stores.values()) { const hit = data.get(address(request)); if (hit) return hit.clone(); } return undefined; }
  };
  const self = { location: { origin }, RV_SW: { version: 'test', scope: '/', dataPath: origin + '/data/', uploads: '/uploads/', romExt: ['nes'], skip: [], precache: [], offline: '/offline/', maxPages: 60, maxShell: 300, ...config }, addEventListener(type, fn) { listeners[type] = fn; } };
  vm.runInNewContext(source, { self, caches, URL, Response, fetch: reply });
  return {
    caches,
    async request(overrides = {}) {
      let response;
      const pending = [];
      listeners.fetch({ request: { url, method: 'GET', mode: 'cors', destination: '', clone() { return this; }, ...overrides }, clientId: 'player', respondWith(value) { response = Promise.resolve(value); }, waitUntil(value) { pending.push(value); } });
      const result = await response;
      await Promise.allSettled(pending);
      return result;
    }
  };
}

test('a cached ROM cannot be opened as a document, online or offline', async () => {
  for (const offline of [false, true]) {
    const w = worker(() => offline ? Promise.reject(new TypeError('offline')) : Promise.resolve(new Response('denied', { status: 403 })));
    await (await w.caches.open('rv-games')).put(key, new Response('private ROM'));
    const response = await w.request({ mode: 'navigate', destination: 'document' });
    assert.equal(response.status, 403);
    assert.notEqual(await response.text(), 'private ROM');
  }
});

test('server denials never fall back to cached ROM bytes and evict the cached copy', async () => {
  for (const status of [401, 403, 404, 410]) {
    for (const method of ['GET', 'HEAD']) {
      const w = worker(() => Promise.resolve(new Response(null, { status })));
      const cache = await w.caches.open('rv-games');
      await cache.put(key, new Response('private ROM'));
      const response = await w.request({ method });
      assert.equal(response.status, status, method + ' ' + status);
      assert.equal(await cache.match(key), undefined);
    }
  }
});

test('a network outage still permits cached player GET and HEAD requests', async () => {
  const w = worker(() => Promise.reject(new TypeError('offline')));
  await (await w.caches.open('rv-games')).put(key, new Response('private ROM'));
  const response = await w.request();
  assert.equal(response.status, 200);
  assert.equal(await response.text(), 'private ROM');
  assert.equal((await w.request({ method: 'HEAD' })).status, 200);
});

test('plain permalinks put the site root in the skip list without bypassing every page', async () => {
  // Without pretty permalinks rest_url() is "/?rest_route=", whose path is the site root (or the scope).
  const html = () => Promise.resolve(new Response('page', { status: 200, headers: { 'Content-Type': 'text/html' } }));
  const w = worker(html, { skip: ['/', '/wp-admin/'] });
  const page = await w.request({ url: origin + '/games/demo/', mode: 'navigate', destination: 'document' });
  assert.equal(page && await page.text(), 'page');
  assert.equal(await w.request({ url: origin + '/wp-admin/edit.php', mode: 'navigate', destination: 'document' }), undefined);
  assert.equal(await w.request({ url: origin + '/?rest_route=/retrovault/v1/games/1/save', mode: 'cors' }), undefined);
  const sub = worker(html, { scope: '/wp/', skip: ['/wp/', '/wp/wp-admin/'] });
  assert.equal(await (await sub.request({ url: origin + '/wp/games/demo/', mode: 'navigate', destination: 'document' })).text(), 'page');
});

test('an emulator file the CDN cannot serve is never cached as if it were the file', async () => {
  // <script> and <link> tags load the CDN with no-cors; the opaque answer hides a 404 unless the
  // worker asks with cors, which the CDN allows.
  const cdn = 'https://cdn.example/data/';
  const opaque = { type: 'opaque', ok: false, status: 0, clone() { return this; } };
  for (const status of [404, 500]) {
    const w = worker((request, init) => Promise.resolve(typeof request === 'string' && init && init.mode === 'cors' ? new Response('error', { status }) : opaque), { dataPath: cdn });
    const response = await w.request({ url: cdn + 'emulator.min.js', mode: 'no-cors', destination: 'script' });
    assert.equal(response.status, status);
    assert.equal(await (await w.caches.open('rv-games')).match(cdn + 'emulator.min.js'), undefined);
  }
  const fine = worker((request, init) => Promise.resolve(typeof request === 'string' && init && init.mode === 'cors' ? new Response('js', { status: 200 }) : opaque), { dataPath: cdn });
  assert.equal(await (await fine.request({ url: cdn + 'emulator.min.js', mode: 'no-cors', destination: 'script' })).text(), 'js');
  assert.equal(await (await (await fine.caches.open('rv-games')).match(cdn + 'emulator.min.js')).text(), 'js');
  // A host that refuses cors keeps the original request, as before.
  const strict = worker((request, init) => typeof request === 'string' && init && init.mode === 'cors' ? Promise.reject(new TypeError('cors')) : Promise.resolve(opaque), { dataPath: cdn });
  assert.equal((await strict.request({ url: cdn + 'emulator.min.js', mode: 'no-cors', destination: 'script' })).type, 'opaque');
});

// A page response as WordPress sends it: same-origin ("basic"), optionally with Cache-Control.
const html = (body, cacheControl) => ({ ok: true, type: 'basic', status: 200, headers: new Headers(cacheControl ? { 'Content-Type': 'text/html', 'Cache-Control': cacheControl } : { 'Content-Type': 'text/html' }), clone() { return this; }, async text() { return body; } });
const navigate = (url) => ({ url, mode: 'navigate', destination: 'document' });

test('the account page is skipped by its query under plain permalinks and never cached', async () => {
  const w = worker(() => Promise.resolve(html('account')), { skipQuery: ['page_id=42'] });
  assert.equal(await w.request(navigate(origin + '/?page_id=42')), undefined);
  assert.equal(await w.request(navigate(origin + '/?page_id=42&utm_source=x')), undefined);
  assert.equal(await (await w.request(navigate(origin + '/?page_id=7'))).text(), 'account');
  assert.equal(await (await w.caches.open('rv-pages-test')).match(origin + '/?page_id=42'), undefined);
});

test('responses marked no-store (every signed-in page) are never stored for offline use', async () => {
  const w = worker((req) => Promise.resolve(html('private', 'no-cache, must-revalidate, max-age=0, no-store, private')));
  assert.equal(await (await w.request(navigate(origin + '/games/'))).text(), 'private');
  assert.equal(await (await w.caches.open('rv-pages-test')).match(origin + '/games/'), undefined);
  const pub = worker(() => Promise.resolve(html('public')));
  await pub.request(navigate(origin + '/games/'));
  assert.equal(await (await (await pub.caches.open('rv-pages-test')).match(origin + '/games/')).text(), 'public');
});

test('offline, a cached root page is never served for a different ?p= page', async () => {
  const w = worker(() => Promise.reject(new TypeError('offline')));
  const pages = await w.caches.open('rv-pages-test');
  await pages.put(origin + '/?p=1', new Response('page one'));
  await pages.put(origin + '/', new Response('home'));
  await pages.put(origin + '/games/', new Response('library'));
  assert.equal(await (await w.request(navigate(origin + '/?p=1'))).text(), 'page one');
  assert.notEqual(await (await w.request(navigate(origin + '/?p=2'))).text(), 'page one');
  assert.equal(await (await w.request(navigate(origin + '/?source=pwa'))).text(), 'home', 'the PWA start URL still opens the cached home page');
  assert.equal(await (await w.request(navigate(origin + '/games/?sort=rating'))).text(), 'library', 'filtered views still fall back to the cached library');
});
