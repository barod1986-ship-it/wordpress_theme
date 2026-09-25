/* RetroVault — cloud saves. Loaded only for signed-in players. */
(function () {
	'use strict';
	var C = window.RVCloud;
	if (!C) { return; }
	var ejs = function () { return window.EJS_emulator; };
	var say = function (t) { try { ejs().displayMessage(t, 3500); } catch (e) {} };
	var wait = function (ms) { return new Promise(function (r) { setTimeout(function () { r(null); }, ms); }); };
	var api = function (path, opts) {
		opts = opts || {};
		opts.credentials = 'same-origin';
		opts.headers = { 'X-WP-Nonce': C.nonce };
		return fetch(C.rest + 'games/' + C.id + path, opts);
	};
	/* الضغط داخل المتصفح: حالات N64 مثلاً ~16MB أغلبها أصفار */
	var pack = function (bytes) {
		if (!window.CompressionStream) { return Promise.resolve({ data: new Blob([bytes]), enc: 'raw' }); }
		var stream = new Blob([bytes]).stream().pipeThrough(new CompressionStream('gzip'));
		return new Response(stream).blob().then(function (b) { return { data: b, enc: 'gzip' }; });
	};
	var unpack = function (buf, enc) {
		if (enc !== 'gzip') { return Promise.resolve(new Uint8Array(buf)); }
		var stream = new Blob([buf]).stream().pipeThrough(new DecompressionStream('gzip'));
		return new Response(stream).arrayBuffer().then(function (b) { return new Uint8Array(b); });
	};
	/* صورة مصغّرة (480px كحد أقصى) للّحظة المحفوظة */
	var thumb = function (blob) {
		if (!blob || !window.createImageBitmap) { return Promise.resolve(blob); }
		return createImageBitmap(blob).then(function (bmp) {
			var scale = Math.min(1, 480 / bmp.width);
			var c = document.createElement('canvas');
			c.width = Math.max(1, Math.round(bmp.width * scale));
			c.height = Math.max(1, Math.round(bmp.height * scale));
			c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
			return new Promise(function (res) { c.toBlob(function (b) { res(b || blob); }, 'image/webp', 0.85); });
		}).catch(function () { return blob; });
	};
	/* EmulatorJS 4.2 لا يمرّر اللقطة في الحدث (e.screenshot فارغ)، فنلتقطها من الشاشة */
	var shot = function (e) {
		if (e && e.screenshot instanceof Blob) { return thumb(e.screenshot); }
		try {
			return Promise.race([
				ejs().takeScreenshot('canvas', 'png', 1).then(function (r) { return r && r.blob ? thumb(r.blob) : null; }),
				wait(4000)
			]).catch(function () { return null; });
		} catch (err) { return Promise.resolve(null); }
	};
	var core = function () { try { return ejs().getCore() || C.core; } catch (e) { return C.core; } };

	/* ---------- بدون اتصال: الحفظ في متصفح هذا الجهاز (مخزن EmulatorJS نفسه) ---------- */
	var localName = function () { try { return ejs().getBaseFileName() + '.state'; } catch (e) { return 'game.state'; } };
	var saveLocal = function (state) {
		try { return Promise.resolve(ejs().storage.states.put(localName(), state)).then(function () { say(C.i18n.savedLocal); }).catch(function () { say(C.i18n.failed); }); } catch (e) { say(C.i18n.failed); return Promise.resolve(); }
	};
	var loadLocal = function () {
		try {
			return ejs().storage.states.get(localName()).then(function (state) {
				if (state) { ejs().gameManager.loadState(state); say(C.i18n.loadedLocal); } else { say(C.i18n.noneLocal); }
			});
		} catch (e) { say(C.i18n.failed); return Promise.resolve(); }
	};
	var offlineError = function (err) { return !navigator.onLine || (err && err.name === 'TypeError'); };

	/* ---------- حالات المحاكي (Save State): سجل من عدة خانات ---------- */
	window.EJS_onSaveState = function (e) {
		if (!e || !e.state) { say(C.i18n.failed); return; }
		if (!navigator.onLine) { return saveLocal(e.state); }
		say(C.i18n.saving);
		return Promise.all([pack(e.state), shot(e)]).then(function (res) {
			if (res[0].data.size > C.max) { say(C.i18n.tooBig); return null; }
			var form = new FormData();
			form.append('state', res[0].data, 'state.bin');
			form.append('encoding', res[0].enc);
			form.append('core', core());
			if (res[1]) { form.append('screenshot', res[1], 'shot.png'); }
			return api('/save', { method: 'POST', body: form })
				.then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw j; } return j; }); })
				.then(function (info) {
					say(C.i18n.saved);
					try { window.parent.postMessage({ type: 'rv:saved', id: C.id, info: info }, window.location.origin); } catch (x) {}
				});
		}).catch(function (err) {
			return saveLocal(e.state).then(function () {
				if (!offlineError(err)) { say((err && err.message) || C.i18n.failed); }
			});
		});
	};

	var load = function (quiet, slot) {
		if (!navigator.onLine) { return quiet ? Promise.resolve() : loadLocal(); }
		var q = (slot && slot !== '1') ? '?slot=' + encodeURIComponent(slot) : '';
		return api('/save/state' + q)
			.then(function (r) {
				if (r.status === 404) { if (!quiet) { say(C.i18n.none); } return null; }
				if (!r.ok) { throw new Error('http'); }
				var enc = r.headers.get('X-RV-Encoding') || 'raw';
				return r.arrayBuffer().then(function (b) { return unpack(b, enc); });
			})
			.then(function (bytes) {
				if (!bytes) { return; }
				ejs().gameManager.loadState(bytes);
				say(C.i18n.loaded);
			})
			.catch(function (err) {
				if (offlineError(err) && !quiet) { return loadLocal(); }
				if (!quiet) { say(C.i18n.failed); }
			});
	};
	window.EJS_onLoadState = function () { load(false, ''); };

	/* SRAM: only acknowledged writes advance the synchronization base. */
	var baseKey = 'rv-sram-' + C.user + '-' + C.id;
	var lastHash = null;
	var cloudBase = null;
	var uploading = false;
	var queued = null;
	var blocked = false;
	var leaving = false;
	var hash = function (b) {
		var h = 0x811c9dc5;
		for (var i = 0; i < b.length; i++) { h ^= b[i]; h = Math.imul(h, 0x01000193); }
		return (h >>> 0).toString(16) + '-' + b.length;
	};
	var getBase = function () { try { return localStorage.getItem(baseKey) || ''; } catch (e) { return ''; } };
	var setBase = function (h) {
		lastHash = h;
		cloudBase = h;
		try { localStorage.setItem(baseKey, h); } catch (e) {}
	};
	var uploadSram = function (bytes, h) {
		if (blocked || cloudBase === null) { return Promise.resolve(); }
		if (uploading) { queued = new Uint8Array(bytes); return Promise.resolve(); }
		uploading = true;
		lastHash = h;
		var base = cloudBase;
		return pack(bytes).then(function (p) {
			var form = new FormData();
			form.append('sram', p.data, 'game.srm');
			form.append('encoding', p.enc);
			form.append('hash', h);
			form.append('base', base);
			return api('/sram', { method: 'POST', body: form, keepalive: p.data.size < 60000 });
		}).then(function (r) {
			if (r.status === 409) { blocked = true; say(C.i18n.sramChanged); }
			if (!r.ok) { throw new Error('http'); }
			return r.json().then(function (info) {
				if (info.hash !== h) { throw new Error('hash'); }
				setBase(h);
			});
		}).catch(function () { lastHash = null; }).then(function () {
			uploading = false;
			var next = queued;
			queued = null;
			if (next && !blocked && hash(next) !== getBase()) { return uploadSram(next, hash(next)); }
		});
	};
	var beaconSram = function (bytes, h) {
		if (blocked || cloudBase === null || !navigator.sendBeacon || bytes.length > 60000) { return; }
		var form = new FormData();
		form.append('sram', new Blob([bytes]), 'game.srm');
		form.append('encoding', 'raw');
		form.append('hash', h);
		form.append('base', cloudBase);
		// A queued beacon is not a server acknowledgement. Keep the prior base for the next launch.
		try { navigator.sendBeacon(C.rest + 'games/' + C.id + '/sram?_wpnonce=' + encodeURIComponent(C.nonce), form); } catch (e) {}
	};
	var onFlush = function (bytes) {
		if (!bytes || !bytes.length || blocked) { return; }
		var copy = new Uint8Array(bytes);
		var h = hash(copy);
		if (cloudBase === null) {
			// After an offline launch, re-check the server before uploading on reconnection.
			if (navigator.onLine && !leaving) { return syncSram(false); }
			return;
		}
		if (h === (lastHash || getBase())) { return; }
		if (leaving) { beaconSram(copy, h); } else { return uploadSram(copy, h); }
	};
	var writeSram = function (bytes, restart) {
		var gm = ejs().gameManager;
		var path = gm.getSaveFilePath();
		gm.FS.writeFile(path, bytes);
		gm.loadSaveFiles();
		if (restart) { gm.restart(); }
	};
	var syncing = false;
	var syncSram = function (restart) {
		if (syncing || blocked) { return Promise.resolve(); }
		var gm, path, local = null;
		try { gm = ejs().gameManager; path = gm.getSaveFilePath(); } catch (e) { return Promise.resolve(); }
		if (!path) { return Promise.resolve(); }
		try { if (gm.FS.analyzePath(path).exists) { local = new Uint8Array(gm.FS.readFile(path)); } } catch (e) {}
		var localHash = (local && local.length) ? hash(local) : '';
		var base = getBase();
		syncing = true;
		return api('/sram').then(function (r) {
			if (!r.ok) { throw new Error('http'); }
			return r.json();
		}).then(function (info) {
			cloudBase = info.exists ? info.hash : '';
			if (!cloudBase && !localHash) { return; }
			if (cloudBase === localHash) { setBase(cloudBase); return; }
			if (!cloudBase || (localHash && localHash !== base && cloudBase === base)) {
				return uploadSram(local, localHash);
			}
			if (localHash && base && localHash !== base && cloudBase !== base) {
				if (!window.confirm(C.i18n.sramConflict)) { return uploadSram(local, localHash); }
			}
			return api('/sram/file').then(function (r) {
				if (!r.ok) { throw new Error('http'); }
				var enc = r.headers.get('X-RV-Encoding') || 'raw';
				var confirmed = r.headers.get('X-RV-Hash');
				return r.arrayBuffer().then(function (b) { return unpack(b, enc); }).then(function (bytes) {
					// Do not overwrite progress changed while the download was in flight.
					var now = gm.FS.analyzePath(path).exists ? gm.FS.readFile(path) : null;
					if (((now && now.length) ? hash(now) : '') !== localHash) {
						blocked = true; say(C.i18n.sramChanged); return;
					}
					writeSram(bytes, restart);
					setBase(confirmed || hash(bytes));
					say(C.i18n.sramRestored);
				});
			});
		}).catch(function () { cloudBase = null; }).then(function () { syncing = false; });
	};

	window.addEventListener('beforeunload', function () { leaving = true; });
	window.addEventListener('pagehide', function () { leaving = true; });
	window.addEventListener('pageshow', function () { leaving = false; });
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState !== 'hidden') { return; }
		try { if (ejs() && ejs().started) { ejs().gameManager.saveSaveFiles(); } } catch (e) {}
	});

	window.RV_afterStart = function () {
		/* المزامنة أولاً، ثم الاستكمال من حالة (الحالة تتضمن ذاكرة اللعبة فلا داعي لإعادة التشغيل) */
		var first = C.sram ? syncSram(!C.resume) : Promise.resolve();
		return first.then(function () {
			if (C.sram) { try { ejs().on('saveSaveFiles', onFlush); } catch (e) {} }
			if (C.resume) { setTimeout(function () { load(true, C.resume); }, 300); }
		});
	};
})();
