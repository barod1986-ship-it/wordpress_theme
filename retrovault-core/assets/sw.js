/* RetroVault — عامل الخدمة (Service Worker).
 * الإعدادات في self.RV_SW (يضيفها الخادم قبل هذا الملف).
 * - ملفات الألعاب (المحاكي، الأنوية، ملفات اللعب): الشبكة أولاً وتُحفظ، ومن الذاكرة بدون إنترنت.
 * - الصفحات: الشبكة أولاً، ثم نسخة محفوظة، ثم صفحة «غير متصل».
 * - الأنماط والصور والخطوط: من الذاكرة فوراً مع تحديثها في الخلفية. */
(function () {
	'use strict';

	var C = self.RV_SW;
	var SHELL = 'rv-shell-' + C.version;
	var PAGES = 'rv-pages-' + C.version;
	var GAMES = 'rv-games';
	var KEEP = [SHELL, PAGES, GAMES, 'rv-offline'];
	var gameRequests = Object.create(null);
	var disabled = false;

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

	/* ملف اللعبة المحمي: /rom/{رمز}/{اسم} (أو ?rv_rom=رمز/اسم). الرمز يتغير كل بضع ساعات، فيُحفظ
	 * الملف بمفتاح بلا رمز ليبقى متاحاً بدون إنترنت مهما قدم رمز الصفحة المحفوظة. */
	var ROM_PATH = /\/rom\/[0-9a-f]{32}\//;
	var ROM_QUERY = /([?&]rv_rom=)[0-9a-f]{32}/;

	function isGameAsset(url) {
		if (url.href.indexOf(C.dataPath) === 0) {
			return true;
		}
		if (url.origin !== self.location.origin) {
			return false;
		}
		if (ROM_PATH.test(url.pathname) || ROM_QUERY.test(url.search)) {
			return true;
		}
		if (url.pathname.indexOf(C.uploads) === 0) {
			return C.romExt.indexOf(url.pathname.split('.').pop().toLowerCase()) !== -1;
		}
		return false;
	}

	function gameKey(href) {
		return href.replace(ROM_PATH, '/rom/-/').replace(ROM_QUERY, '$1-');
	}

	function skipped(url) {
		for (var i = 0; i < C.skip.length; i++) {
			/* بلا روابط دائمة يكون مسار REST هو جذر الموقع نفسه؛ لو استُثني لتعطّل كل شيء. */
			var prefix = C.skip[i];
			if (prefix && prefix.length > 1 && prefix !== C.scope && url.pathname.indexOf(prefix) === 0) {
				return true;
			}
		}
		/* صفحة «حسابي» بلا روابط دائمة (?page_id=N): مسارها جذر الموقع فتُعرف بمعاملها. */
		var pairs = C.skipQuery || [];
		for (var j = 0; j < pairs.length; j++) {
			var eq = pairs[j].indexOf('=');
			if (eq > 0 && url.searchParams.get(pairs[j].slice(0, eq)) === decodeURIComponent(pairs[j].slice(eq + 1))) {
				return true;
			}
		}
		return /(^|&)(rv_random|rv_unsub|rv_sw|rv_manifest|rv_download|preview|rest_route)=/.test(url.search.slice(1)) || /\/download\/?$/.test(url.pathname);
	}

	/* بلا اتصال ولا نسخة محفوظة بالرابط نفسه: صفحة بالمسار نفسه بمعاملات أخرى (المكتبة بفلتر آخر) تكفي،
	 * إلا في جذر الموقع: بلا روابط دائمة تتشارك كل الصفحات مسار الجذر وتختلف بمعاملاتها (?p=7)، فلا تُعرض
	 * صفحة بدل أخرى. معاملات التتبع وحدها (start_url فيه ?source=pwa) تعني الرئيسية نفسها. */
	var TRACKING = /^(source|utm_[a-z]+|fbclid|gclid|ref)$/;
	function fallback(cache, req) {
		var url = new URL(req.url);
		if (url.pathname !== C.scope) {
			return cache.match(req, { ignoreSearch: true });
		}
		var trackingOnly = true;
		url.searchParams.forEach(function (value, key) { if (!TRACKING.test(key)) { trackingOnly = false; } });
		return trackingOnly && url.search ? cache.match(url.origin + url.pathname) : Promise.resolve(undefined);
	}

	/* الخادم يقول إن الرد لا يُحفظ (صفحات الأعضاء وكل ما فيه بيانات شخصية). */
	function storable(res) {
		return !/\bno-store\b/i.test(res.headers.get('Cache-Control') || '');
	}

	function cacheable(res) {
		return res && (res.ok || res.type === 'opaque');
	}

	/* وسوم <script> و<link> إلى CDN المحاكي طلبات no-cors: ردّها «معتم» يخفي رمز الحالة، فيُحفظ خطأ 404
	 * كأنه الملف ويُعدّ اللعبة جاهزة بدون إنترنت. طلب cors للرابط نفسه يكشف الحالة (الـ CDN يسمح به)،
	 * وإن رفضه خادم آخر عدنا إلى الطلب الأصلي. */
	function fetchGame(req) {
		if (req.mode !== 'no-cors' || new URL(req.url).origin === self.location.origin) {
			return fetch(req);
		}
		return fetch(req.url, { mode: 'cors', credentials: 'omit' }).catch(function () { return fetch(req); });
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
		var key = gameKey(req.url);
		var url = new URL(req.url);
		var protectedRom = url.origin === self.location.origin && (ROM_PATH.test(url.pathname) || ROM_QUERY.test(url.search));
		if (protectedRom && (['cors', 'same-origin'].indexOf(req.mode) === -1 || req.destination)) {
			return Promise.resolve(new Response('Forbidden', { status: 403, headers: { 'Content-Type': 'text/plain', 'Cache-Control': 'no-store' } }));
		}
		if (event.clientId) {
			var seen = gameRequests[event.clientId] || (gameRequests[event.clientId] = []);
			if (seen.indexOf(key) === -1) { seen.push(key); }
		}
		/* Network failures can use the offline copy; an explicit server denial must win. */
		function denied(cache, response) {
			if ([401, 403, 404, 410].indexOf(response.status) !== -1) {
				return cache.delete(key).catch(function () {}).then(function () { return response; });
			}
			return response;
		}
		function saved(cache, fallback, headOnly) {
			return cache.match(key).then(function (hit) {
				if (!hit) {
					return fallback || Response.error();
				}
				return headOnly ? new Response(null, { status: 200, headers: hit.headers }) : hit;
			});
		}
		return caches.open(GAMES).then(function (cache) {
			if (req.method === 'HEAD') {
				return fetch(req).then(function (res) {
					if (!res.ok) {
						return denied(cache, res);
					}
					/* EmulatorJS يتحقق بطلب HEAD ثم يستخدم نسخته المخزّنة دون تنزيل؛
					 * نجلب الملف في الخلفية مرة واحدة ليبقى متاحاً بدون إنترنت. */
					event.waitUntil(cache.match(key).then(function (hit) {
						if (hit) {
							return null;
						}
						return fetch(req.url).then(function (full) {
							return cacheable(full) ? cache.put(key, full) : null;
						}).catch(function () {});
					}));
					return res;
				}).catch(function () {
					return saved(cache, null, true);
				});
			}
			return fetchGame(req).then(function (res) {
				if (!cacheable(res)) {
					return denied(cache, res);
				}
				return cache.put(key, res.clone()).catch(function () {}).then(function () { return res; });
			}).catch(function () {
				return saved(cache, null, false);
			});
		});
	}

	/* الصفحات */
	function page(event) {
		var req = event.request;
		return fetch(req).then(function (res) {
			if (res.ok && res.type === 'basic' && storable(res)) {
				var copy = res.clone();
				event.waitUntil(caches.open(PAGES).then(function (cache) {
					return cache.put(req, copy);
				}).then(function () { return trim(PAGES, C.maxPages); }));
			}
			return res;
		}).catch(function () {
			return caches.open(PAGES).then(function (cache) {
				return cache.match(req).then(function (hit) {
					return hit || fallback(cache, req);
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
						return cache.put(req, res.clone()).catch(function () {}).then(function () { return res; });
					}
					return res;
				});
				event.waitUntil(fresh.then(function () { return trim(SHELL, C.maxShell); }).catch(function () {}));
				return hit || fresh;
			});
		});
	}

	/* A marker is a receipt for actual cached responses, never just a game-start event. */
	function offlineReady(record, prepare, clientId) {
		if (!record || record.protocol !== 2 || !record.data || !record.rom || !record.player || !Array.isArray(record.resources)) {
			return Promise.resolve(false);
		}
		var rom = new URL(record.rom, self.location.origin);
		var pages = [record.data.url, record.player];
		if (!isGameAsset(rom) || pages.some(function (href) {
			var url = new URL(href, self.location.origin);
			return url.origin !== self.location.origin || skipped(url);
		})) { return Promise.resolve(false); }
		var resources = record.resources.concat(prepare ? (gameRequests[clientId] || []) : []);
		resources.push(C.dataPath + 'loader.js', gameKey(rom.href));
		resources = resources.filter(function (url, i, all) { return all.indexOf(url) === i; });
		return Promise.all([caches.open(PAGES), caches.open(GAMES), caches.open(SHELL)]).then(function (stores) {
			return Promise.all(pages.map(function (href) {
				return stores[0].match(href).then(function (hit) {
					if (hit) { return true; }
					if (!prepare) { return false; }
					return fetch(href, { credentials: 'same-origin' }).then(function (res) {
						if (!res.ok || res.type !== 'basic' || !(res.headers.get('Content-Type') || '').includes('text/html')) { return false; }
						return stores[0].put(href, res).then(function () { return true; });
					}).catch(function () { return false; });
				});
			}).concat(resources.map(function (href) {
				return Promise.all([stores[1].match(gameKey(href)), stores[2].match(href)]).then(function (hits) {
					return hits.some(function (hit) { return !!hit; });
				});
			}))).then(function (ok) {
				if (ok.some(function (value) { return !value; })) { return false; }
				record.resources = resources;
				return true;
			});
		}).catch(function () { return false; });
	}

	self.addEventListener('message', function (event) {
		var message = event.data || {};
		if (message.type === 'rv:disable') {
			disabled = true;
			event.waitUntil(self.registration.unregister());
			return;
		}
		if (message.type !== 'rv:offline-check' || !event.ports[0]) { return; }
		var record = message.record;
		event.waitUntil(offlineReady(record, !!message.prepare, event.source ? event.source.id : '').then(function (ready) {
			event.ports[0].postMessage({ ready: ready, record: ready ? record : null });
		}).catch(function () { event.ports[0].postMessage({ ready: false }); }));
	});

	self.addEventListener('fetch', function (event) {
		if (disabled) { return; }
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
