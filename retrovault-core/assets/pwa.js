/* RetroVault — تسجيل تطبيق الويب، زر التثبيت، وشارة «متاحة بدون إنترنت». */
(function () {
	'use strict';

	var C = window.RVPWA || {};
	if (!('serviceWorker' in navigator) || !C.sw) {
		return;
	}

	window.addEventListener('load', function () {
		navigator.serviceWorker.register(C.sw, { scope: C.scope }).catch(function () { /* غير مدعوم أو اتصال غير آمن */ });
	});

	/* تغيّر الحساب على هذا الجهاز (خروج، أو دخول بحساب آخر): تُحذف نسخ الصفحات المحفوظة
	 * (rv-pages في sw.js) حتى لا تُعرض صفحات الحساب السابق بدون إنترنت على جهاز مشترك. */
	try {
		var who = C.user || '';
		var last = window.localStorage.getItem('rv-pwa-user');
		if (last !== null && last !== who && window.caches) {
			caches.delete('rv-pages').catch(function () {});
		}
		window.localStorage.setItem('rv-pwa-user', who);
	} catch (e) { /* التخزين المحلي غير متاح */ }

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

	/* «متاحة بدون إنترنت على هذا الجهاز» في صفحة اللعبة */
	var badge = document.querySelector('[data-rv-offline-badge]');
	if (badge && window.caches) {
		caches.open(C.cache).then(function (cache) {
			return cache.match(badge.getAttribute('data-key'));
		}).then(function (hit) {
			if (hit) {
				badge.hidden = false;
			}
		}).catch(function () {});
	}
})();
