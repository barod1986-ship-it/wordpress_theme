/* RetroVault — تسجيل تطبيق الويب، زر التثبيت، وشارة «متاحة بدون إنترنت». */
(function () {
	'use strict';

	var C = window.RVPWA || {};
	if (!('serviceWorker' in navigator) || !C.sw) {
		return;
	}

	function clearPages() {
		return window.caches ? caches.keys().then(function (keys) {
			return Promise.all(keys.filter(function (k) { return k.indexOf('rv-pages') === 0; }).map(function (k) { return caches.delete(k); }));
		}) : Promise.resolve();
	}
	if (C.enabled === false) {
		if (navigator.serviceWorker.controller && new URL(navigator.serviceWorker.controller.scriptURL).searchParams.has('rv_sw')) {
			navigator.serviceWorker.controller.postMessage({ type: 'rv:disable' });
		}
		navigator.serviceWorker.getRegistrations().then(function (registrations) {
			return Promise.all(registrations.filter(function (r) {
				var worker = r.active || r.waiting || r.installing;
				return worker && r.scope === new URL(C.scope, location.origin).href && new URL(worker.scriptURL).searchParams.has('rv_sw');
			}).map(function (r) { return r.unregister(); }));
		}).then(function () {
			if (!window.caches) { return; }
			return caches.keys().then(function (keys) {
				return Promise.all(keys.filter(function (k) { return /^rv-(shell-|pages|games$|offline$)/.test(k); }).map(function (k) { return caches.delete(k); }));
			});
		}).catch(function () {});
		return;
	}
	var accountReady = Promise.resolve();
	try {
		var who = C.user || '';
		var last = window.localStorage.getItem('rv-pwa-user');
		if (last !== null && last !== who) { accountReady = clearPages(); }
		window.localStorage.setItem('rv-pwa-user', who);
	} catch (e) {}
	accountReady = accountReady.catch(function () {});
	navigator.serviceWorker.register(C.sw, { scope: C.scope }).catch(function () {});

	function verify(record, prepare) {
		return accountReady.then(function () {
			if (!navigator.serviceWorker.controller || !window.MessageChannel) { return { ready: false }; }
			return new Promise(function (resolve) {
				var channel = new MessageChannel();
				var timer = setTimeout(function () { channel.port1.close(); resolve({ ready: false }); }, 5000);
				channel.port1.onmessage = function (e) { clearTimeout(timer); channel.port1.close(); resolve(e.data || { ready: false }); };
				navigator.serviceWorker.controller.postMessage({ type: 'rv:offline-check', record: record, prepare: prepare }, [channel.port2]);
			});
		});
	}

	window.RV_markOffline = function () {
		var marker = window.RVOfflineGame;
		if (!marker || !window.caches) { return Promise.resolve(false); }
		var record = Object.assign({}, marker, { protocol: 2, player: location.href.split('#')[0], resources: [] });
		var attempt = function (tries) {
			record.resources = performance.getEntriesByType('resource').map(function (r) { return r.name; }).filter(function (url) {
				return url.indexOf(C.dataPath) === 0 || url.indexOf(C.assets) === 0;
			});
			return verify(record, true).then(function (result) {
				if (!result.ready) {
					if (tries > 0) { return new Promise(function (resolve) { setTimeout(function () { resolve(attempt(tries - 1)); }, 1000); }); }
					return false;
				}
				result.record.data.time = Date.now();
				return caches.open(C.cache).then(function (cache) {
					return cache.put(marker.key, new Response(JSON.stringify(result.record), { headers: { 'Content-Type': 'application/json' } }));
				}).then(function () { return true; });
			}).catch(function () { return false; });
		};
		return attempt(5);
	};

	/* زر «ثبّت الموقع كتطبيق» يظهر فقط عندما يسمح المتصفح بالتثبيت */
	var deferred = null;
	var buttons = function () { return Array.prototype.slice.call(document.querySelectorAll('[data-rv-install]')); };
	window.addEventListener('beforeinstallprompt', function (e) {
		e.preventDefault();
		deferred = e;
		buttons().forEach(function (b) { b.hidden = false; });
	});
	window.addEventListener('appinstalled', function () {
		deferred = null;
		buttons().forEach(function (b) { b.hidden = true; });
	});
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-rv-install]');
		if (!btn || !deferred) {
			return;
		}
		deferred.prompt();
		deferred.userChoice.then(function () {
			deferred = null;
			btn.hidden = true;
		});
	});

	/* Revalidate the receipt and game version; stale markers never enable the badge. */
	var badge = document.querySelector('[data-rv-offline-badge]');
	if (badge && window.caches) {
		caches.open(C.cache).then(function (cache) {
			var key = badge.getAttribute('data-key');
			return cache.match(key).then(function (hit) { return hit ? hit.json() : null; }).then(function (record) {
				if (!record || record.version !== badge.getAttribute('data-version')) { return { ready: false }; }
				return navigator.serviceWorker.ready.then(function () { return verify(record, false); });
			}).then(function (result) {
				badge.hidden = !result.ready;
				if (!result.ready) { return cache.delete(key); }
			});
		}).catch(function () {});
	}
})();
