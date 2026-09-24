/* RetroVault — عامل الخدمة (Service Worker).
 * الإعدادات في self.RV_SW (يضيفها الخادم قبل هذا الملف).
 * - ملفات الألعاب (المحاكي، الأنوية، ملفات اللعب): الشبكة أولاً وتُحفظ، ومن الذاكرة بدون إنترنت.
 * - الصفحات: الشبكة أولاً، ثم نسخة محفوظة، ثم صفحة «غير متصل».
 * - الأنماط والصور والخطوط: من الذاكرة فوراً مع تحديثها في الخلفية. */
(function () {
	'use strict';

	var C = self.RV_SW;
	var SHELL = 'rv-shell-' + C.version;
	var PAGES = 'rv-pages';
	var GAMES = 'rv-games';
	var KEEP = [SHELL, PAGES, GAMES, 'rv-offline'];

	self.addEventListener('install', function (event) {
		event.waitUntil(
			caches.open(SHELL).then(function (cache) {
				return Promise.all(C.precache.map(function (url) { return cache.add(url).catch(function () {}); }));
			}).then(function () { return self.skipWaiting(); })
		);
	});

	self.addEventListener('activate', function (event) {
		event.waitUntil(
			caches.keys().then(function (keys) {
				return Promise.all(keys.filter(function (k) {
					return k.indexOf('rv-') === 0 && KEEP.indexOf(k) === -1;
				}).map(function (k) { return caches.delete(k); }));
			}).then(function () { return self.clients.claim(); })
		);
	});

	function isGameAsset(url) {
		if (url.href.indexOf(C.dataPath) === 0) {
			return true;
		}
		if (url.origin === self.location.origin && url.pathname.indexOf(C.uploads) === 0) {
			return C.romExt.indexOf(url.pathname.split('.').pop().toLowerCase()) !== -1;
		}
		return false;
	}

	function skipped(url) {
		for (var i = 0; i < C.skip.length; i++) {
			if (url.pathname.indexOf(C.skip[i]) === 0) {
				return true;
			}
		}
		return /(^|&)(rv_random|rv_unsub|rv_sw|rv_manifest|rv_download|preview)=/.test(url.search.slice(1)) || /\/download\/?$/.test(url.pathname);
	}

	function cacheable(res) {
		return res && (res.ok || res.type === 'opaque');
	}

	function trim(name, max) {
		return caches.open(name).then(function (cache) {
			return cache.keys().then(function (keys) {
				if (keys.length <= max) {
					return null;
				}
				return Promise.all(keys.slice(0, keys.length - max).map(function (k) { return cache.delete(k); }));
			});
		});
	}

	/* ملفات اللعبة */
	function gameAsset(event) {
		var req = event.request;
		return caches.open(GAMES).then(function (cache) {
			if (req.method === 'HEAD') {
				return fetch(req).then(function (res) {
					/* EmulatorJS يتحقق بطلب HEAD ثم يستخدم نسخته المخزّنة دون تنزيل؛
					 * نجلب الملف في الخلفية مرة واحدة ليبقى متاحاً بدون إنترنت. */
					event.waitUntil(cache.match(req.url).then(function (hit) {
						if (hit) {
							return null;
						}
						return fetch(req.url).then(function (full) {
							return cacheable(full) ? cache.put(req.url, full) : null;
						}).catch(function () {});
					}));
					return res;
				}).catch(function () {
					return cache.match(req.url).then(function (hit) {
						return hit ? new Response(null, { status: 200, headers: hit.headers }) : Response.error();
					});
				});
			}
			return fetch(req).then(function (res) {
				if (cacheable(res)) {
					cache.put(req.url, res.clone());
				}
				return res;
			}).catch(function () {
				return cache.match(req.url).then(function (hit) { return hit || Response.error(); });
			});
		});
	}

	/* الصفحات */
	function page(event) {
		var req = event.request;
		return fetch(req).then(function (res) {
			if (res.ok && res.type === 'basic') {
				var copy = res.clone();
				event.waitUntil(caches.open(PAGES).then(function (cache) {
					return cache.put(req, copy);
				}).then(function () { return trim(PAGES, C.maxPages); }));
			}
			return res;
		}).catch(function () {
			return caches.open(PAGES).then(function (cache) {
				return cache.match(req).then(function (hit) {
					return hit || cache.match(req, { ignoreSearch: true });
				});
			}).then(function (hit) {
				return hit || caches.match(C.offline).then(function (off) {
					return off || new Response('offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
				});
			});
		});
	}

	/* الأنماط والسكربتات والصور والخطوط */
	function asset(event) {
		var req = event.request;
		return caches.open(SHELL).then(function (cache) {
			return cache.match(req).then(function (hit) {
				var fresh = fetch(req).then(function (res) {
					if (cacheable(res)) {
						cache.put(req, res.clone());
					}
					return res;
				});
				event.waitUntil(fresh.then(function () { return trim(SHELL, C.maxShell); }).catch(function () {}));
				return hit || fresh;
			});
		});
	}

	self.addEventListener('fetch', function (event) {
		var req = event.request;
		if (req.method !== 'GET' && req.method !== 'HEAD') {
			return;
		}
		var url = new URL(req.url);
		if (isGameAsset(url)) {
			event.respondWith(gameAsset(event));
			return;
		}
		if (req.method !== 'GET') {
			return;
		}
		if (url.origin !== self.location.origin) {
			if (/(^|\.)fonts\.(googleapis|gstatic)\.com$/.test(url.hostname)) {
				event.respondWith(asset(event));
			}
			return;
		}
		if (skipped(url)) {
			return;
		}
		if (req.mode === 'navigate') {
			event.respondWith(page(event));
			return;
		}
		if (/\.(css|js|woff2?|ttf|png|jpe?g|gif|webp|svg|ico)$/i.test(url.pathname)) {
			event.respondWith(asset(event));
		}
	});
})();
