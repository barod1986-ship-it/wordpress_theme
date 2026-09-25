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
function session({ local = [1, 2], server = { bytes: [1, 2] }, storage = new Map(), beaconAccepted = true } = {}) {
  let bytes = Uint8Array.from(local);
  const events = {}, emulatorEvents = {}, calls = [], messages = [], states = new Map();
  const config = { id: 42, user: 7, rest: 'https://site.test/api/', nonce: 'nonce', core: 'fceumm', max: 1e6, sram: true, resume: '', i18n: { savedLocal: 'local saved', saved: 'cloud saved', failed: 'failed', sramChanged: 'conflict', sramRestored: 'restored' } };
  const sandbox = {
    Blob, Response, FormData, Uint8Array, Promise, setTimeout, clearTimeout,
    RVCloud: config, navigator: { onLine: true, sendBeacon(url, body) { calls.push({ type: 'beacon', body }); return beaconAccepted; } },
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
      if (url.endsWith('/sram/file')) { return new Response(Uint8Array.from(server.bytes), { headers: { 'X-RV-Hash': hash(server.bytes) } }); }
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
