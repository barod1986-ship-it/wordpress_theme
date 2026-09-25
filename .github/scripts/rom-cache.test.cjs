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

function worker(reply) {
  const listeners = {}, stores = new Map();
  const address = request => typeof request === 'string' ? request : request.url;
  const caches = {
    async open(name) {
      if (!stores.has(name)) stores.set(name, new Map());
      const data = stores.get(name);
      return {
        async match(request) { const hit = data.get(address(request)); return hit && hit.clone(); },
        async put(request, response) { data.set(address(request), response.clone()); },
        async delete(request) { return data.delete(address(request)); },
        async keys() { return [...data.keys()].map(url => ({ url })); }
      };
    },
    async keys() { return [...stores.keys()]; },
    async delete(name) { return stores.delete(name); }
  };
  const self = { location: { origin }, RV_SW: { version: 'test', dataPath: origin + '/data/', uploads: '/uploads/', romExt: ['nes'], skip: [], precache: [], maxPages: 60, maxShell: 300 }, addEventListener(type, fn) { listeners[type] = fn; } };
  vm.runInNewContext(source, { self, caches, URL, Response, fetch: reply });
  return {
    caches,
    async request(overrides = {}) {
      let response;
      const pending = [];
      listeners.fetch({ request: { url, method: 'GET', mode: 'cors', destination: '', ...overrides }, clientId: 'player', respondWith(value) { response = Promise.resolve(value); }, waitUntil(value) { pending.push(value); } });
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
