'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../retrovault-core/assets/cloud-saves.js'), 'utf8');
function hash(bytes) {
  let h = 0x811c9dc5;
  for (const b of bytes) { h ^= b; h = Math.imul(h, 0x01000193); }
  return (h >>> 0).toString(16) + '-' + bytes.length;
}
function session({ local = [1, 2], server = { bytes: [1, 2] }, storage = new Map(), beaconAccepted = true, rest = 'https://site.test/api/', resume = '' } = {}) {
  let bytes = Uint8Array.from(local);
  const events = {}, emulatorEvents = {}, calls = [], messages = [], states = new Map();
  const config = { id: 42, user: 7, rest, nonce: 'nonce', core: 'fceumm', max: 1e6, sram: true, resume, i18n: { savedLocal: 'local saved', saved: 'cloud saved', failed: 'failed', sramChanged: 'conflict', sramRestored: 'restored', unsupported: 'unsupported', loaded: 'loaded', none: 'none' } };
  const sandbox = {
    Blob, Response, FormData, Uint8Array, Promise, setTimeout, clearTimeout,
    RVCloud: config, navigator: { onLine: true, sendBeacon(url, body) { calls.push({ type: 'beacon', url, body }); return beaconAccepted; } },
    localStorage: { getItem: k => storage.get(k) || null, setItem: (k, v) => storage.set(k, v) },
    document: { addEventListener() {} }, location: { origin: 'https://site.test' }, parent: { postMessage() {} },
    addEventListener: (n, f) => { events[n] = f; }, confirm: () => true,
    EJS_emulator: { started: true, getCore: () => 'fceumm', getBaseFileName: () => 'game', displayMessage: m => messages.push(m), on: (n, f) => { emulatorEvents[n] = f; }, storage: { states: { put: async (k, v) => { states.set(k, v); }, get: async k => states.get(k) } }, gameManager: {
      getSaveFilePath: () => '/save.srm', FS: { analyzePath: () => ({ exists: !!bytes.length }), readFile: () => bytes, writeFile: (p, b) => { bytes = new Uint8Array(b); } }, loadSaveFiles() {}, restart() {}, loadState() {}
    } },
    fetch: async (url, opts = {}) => {
      const method = opts.method || 'GET';
      calls.push({ type: method, url, body: opts.body });
      if (method === 'POST') {
        if (server.hold) { await server.hold; }
        if (server.failWrite) { return Response.json({ message: 'write failed' }, { status: 500 }); }
        if (url.endsWith('/save')) { return Response.json({ slot: 'a' }); }
        const old = server.bytes.length ? hash(server.bytes) : '';
        const next = opts.body.get('hash');
        if (opts.body.get('base') !== old && next !== old) { return Response.json({}, { status: 409 }); }
        server.bytes = [...new Uint8Array(await opts.body.get('sram').arrayBuffer())];
        return Response.json({ hash: hash(server.bytes) });
      }
      if (server.failRead) { return new Response('', { status: 503 }); }
      if (url.endsWith('/sram/file')) { return new Response(Uint8Array.from(server.bytes), { headers: { 'X-RV-Hash': hash(server.bytes), 'X-RV-Encoding': server.gzip ? 'gzip' : 'raw' } }); }
      if (url.includes('/save/state')) { return new Response(Uint8Array.from([1]), { headers: { 'X-RV-Encoding': server.gzip ? 'gzip' : 'raw' } }); }
      return Response.json({ exists: !!server.bytes.length, hash: server.bytes.length ? hash(server.bytes) : '' });
    }
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox); vm.runInContext(source, sandbox);
  return { server, storage, calls, messages, states, sandbox, events,
    start: () => sandbox.RV_afterStart(),
    flush: () => emulatorEvents.saveSaveFiles(bytes),
    setLocal: value => { bytes = Uint8Array.from(value); },
    getLocal: () => [...bytes],
    base: () => storage.get('rv-sram-7-42')
  };
}

test('queued beacon without a server acknowledgement preserves newer local progress on reopen', async () => {
  const a = session(); await a.start(); a.setLocal([9, 8]); a.events.pagehide(); a.flush();
  assert.equal(a.base(), hash([1, 2]));
  assert.equal(a.calls.at(-1).type, 'beacon');
  const b = session({ local: a.getLocal(), server: a.server, storage: a.storage }); await b.start();
  assert.deepEqual(b.server.bytes, [9, 8]); assert.deepEqual(b.getLocal(), [9, 8]);
});
test('rejected beacons also preserve the acknowledged base', async () => {
  const a = session({ beaconAccepted: false }); await a.start(); a.setLocal([3, 4]); a.events.pagehide(); a.flush();
  assert.equal(a.base(), hash([1, 2]));
});
test('failed metadata requests never mean the cloud save is absent', async () => {
  const server = { bytes: [2, 3], failRead: true };
  const a = session({ local: [8, 9], server }); await a.start();
  assert.equal(a.calls.filter(c => c.type === 'POST').length, 0); assert.deepEqual(a.getLocal(), [8, 9]);
  assert.equal(a.base(), undefined);
});
test('a stale device cannot overwrite another device and stops subsequent automatic writes', async () => {
  const a = session(); await a.start(); a.server.bytes = [5, 6]; a.setLocal([7, 8]); await a.flush();
  assert.deepEqual(a.server.bytes, [5, 6]); assert.equal(a.base(), hash([1, 2]));
  assert.ok(a.messages.includes('conflict')); const count = a.calls.length;
  a.setLocal([7, 9]); await a.flush(); assert.equal(a.calls.length, count);
});
test('overlapping flushes are serialized and the latest snapshot reaches the server', async () => {
  const a = session(); await a.start();
  let release; a.server.hold = new Promise(r => { release = r; });
  a.setLocal([3, 4]); const first = a.flush();
  await new Promise(r => setImmediate(r));
  a.setLocal([5, 6]); await a.flush();
  assert.equal(a.calls.filter(c => c.type === 'POST').length, 1);
  a.server.hold = null; release(); await first;
  assert.deepEqual(a.server.bytes, [5, 6]); assert.equal(a.base(), hash([5, 6]));
});
test('an HTTP failure leaves a manual state in local storage', async () => {
  const a = session(); a.server.failWrite = true;
  await a.sandbox.EJS_onSaveState({ state: Uint8Array.from([7, 8, 9]) });
  assert.deepEqual([...a.states.get('game.state')], [7, 8, 9]);
  assert.ok(!a.messages.includes('cloud saved'));
});
test('local storage rejection never reports a successful local save', async () => {
  const a = session(); a.sandbox.navigator.onLine = false;
  a.sandbox.EJS_emulator.storage.states.put = () => Promise.reject(new Error('quota'));
  await a.sandbox.EJS_onSaveState({ state: Uint8Array.from([1]) });
  assert.deepEqual(a.messages, ['failed']);
});
test('a real two-device conflict asks before replacing the local save', async () => {
  const storage = new Map([['rv-sram-7-42', hash([1, 2])]]);
  const a = session({ local: [7, 8], server: { bytes: [5, 6] }, storage });
  let prompted = false; a.sandbox.confirm = () => { prompted = true; return false; };
  await a.start(); assert.equal(prompted, true); assert.deepEqual(a.server.bytes, [7, 8]);
});
test('a device with no recorded base asks before the account save replaces its progress', async () => {
  // A guest played here before signing in, or another account uses this browser: neither side is known to be newer.
  const keep = session({ local: [7, 8], server: { bytes: [5, 6] } });
  let prompted = false; keep.sandbox.confirm = () => { prompted = true; return false; };
  await keep.start();
  assert.equal(prompted, true); assert.deepEqual(keep.server.bytes, [7, 8]); assert.deepEqual(keep.getLocal(), [7, 8]);
  const restore = session({ local: [7, 8], server: { bytes: [5, 6] } });
  restore.sandbox.confirm = () => true;
  await restore.start();
  assert.deepEqual(restore.getLocal(), [5, 6]); assert.equal(restore.base(), hash([5, 6]));
  const fresh = session({ local: [], server: { bytes: [5, 6] } });
  fresh.sandbox.confirm = () => { throw new Error('a device without progress must not prompt'); };
  await fresh.start(); assert.deepEqual(fresh.getLocal(), [5, 6]);
});
test('resume slots and the unload beacon reach the server under plain permalinks', async () => {
  const rest = 'https://site.test/?rest_route=/retrovault/v1/';
  const a = session({ rest, resume: 'abcdefghij123456' }); await a.start();
  await new Promise(r => setTimeout(r, 400));
  const slot = a.calls.find(c => c.type === 'GET' && c.url.includes('slot='));
  assert.ok(slot, 'the resume slot is requested');
  assert.equal(slot.url, rest + 'games/42/save/state&slot=abcdefghij123456');
  a.setLocal([9, 8]); a.events.pagehide(); a.flush();
  assert.equal(a.calls.at(-1).url, rest + 'games/42/sram&_wpnonce=nonce');
  const pretty = session({ resume: 'abcdefghij123456' }); await pretty.start();
  await new Promise(r => setTimeout(r, 400));
  assert.ok(pretty.calls.some(c => c.url === 'https://site.test/api/games/42/save/state?slot=abcdefghij123456'));
});
test('a gzip save on a browser without DecompressionStream explains itself and stops retrying', async () => {
  const a = session({ local: [7, 8], server: { bytes: [5, 6], gzip: true } });
  await a.start();
  assert.ok(a.messages.includes('unsupported')); assert.deepEqual(a.getLocal(), [7, 8]);
  const count = a.calls.length; a.setLocal([7, 9]); await a.flush();
  assert.equal(a.calls.length, count, 'no further automatic writes');
  const b = session({ server: { bytes: [1, 2], gzip: true } }); await b.start();
  b.sandbox.EJS_onLoadState(); await new Promise(r => setTimeout(r, 50));
  assert.equal(b.messages.at(-1), 'unsupported');
});
