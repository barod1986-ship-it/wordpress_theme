/* RetroVault — واجهة المحاكي داخل صفحة المشغّل (تُحمَّل قبل loader.js، والصفحة تستدعي RVEmulatorUI.attach من EJS_ready).
 * - EmulatorJS يعرض عناوين أقسام الإعدادات وبعض القيم والنصوص دون ترجمة؛ نترجمها من ملف لغته نفسه.
 * - عند فشل تنزيل ملف اللعبة يكتفي المحاكي بـ «تحقق من اتصالك»: نعرض السبب الفعلي وزر «أعد المحاولة»،
 *   ولمن يحرّر اللعبة تفاصيل تقنية تكفي لإصلاح الخلل. الإعدادات والنصوص في window.RVPlayerUI. */
(function () {
	'use strict';

	var C = window.RVPlayerUI || {};
	var T = C.i18n || {};
	var A = C.admin || null;
	var loads = [];

	/* EmulatorJS يطلب ملف اللعبة وملف BIOS بـ XMLHttpRequest ولا يحتفظ برد الخادم؛ نسجّل نتيجتهما فقط. */
	function watched(url) {
		return typeof url === 'string' && url !== '' && (url === window.EJS_gameUrl || url === window.EJS_biosUrl);
	}
	var xhrOpen = XMLHttpRequest.prototype.open;
	var xhrSend = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.open = function (method, url) {
		this.rvWatch = watched(url) ? { url: url, method: String(method).toUpperCase() } : null;
		return xhrOpen.apply(this, arguments);
	};
	XMLHttpRequest.prototype.send = function () {
		var xhr = this;
		var w = xhr.rvWatch;
		if (w) {
			// قبل onload/onerror التي يعالج فيها المحاكي الفشل (readystatechange يسبقهما).
			xhr.addEventListener('readystatechange', function () {
				if (xhr.readyState === 4) {
					loads.push({ url: w.url, method: w.method, status: xhr.status, reason: xhr.status ? (xhr.getResponseHeader('X-RetroVault-Rom') || '') : '' });
				}
			});
		}
		return xhrSend.apply(this, arguments);
	};

	/* نص بلا ترجمة في ملف اللغة، أو «قسم (يحتاج إعادة البدء)» الذي يركّبه المحاكي بعد ترجمة نصفه الثاني. */
	function translator(map) {
		var has = function (key) {
			return Object.prototype.hasOwnProperty.call(map, key) && typeof map[key] === 'string' && map[key] !== '';
		};
		return function (text) {
			var key = text.trim();
			if (key === '') {
				return null;
			}
			var out = null;
			if (has(key)) {
				out = map[key] === key ? null : map[key];
			} else {
				var m = /^(.+?) \((.+)\)$/.exec(key);
				if (m && has(m[1])) {
					out = map[m[1]] + ' (' + (has(m[2]) ? map[m[2]] : m[2]) + ')';
				}
			}
			// دالة لا نص: الترجمة قد تحوي $ فلا تُفسَّر كنمط استبدال.
			return out === null ? null : text.replace(key, function () { return out; });
		};
	}

	function walker(emu) {
		var map = emu.config && emu.config.langJson;
		if (!map || typeof map !== 'object') {
			return null;
		}
		var tr = translator(map);
		var walk = function (node) {
			if (node.nodeType === 3) {
				var text = tr(node.nodeValue);
				if (text !== null && text !== node.nodeValue) {
					node.nodeValue = text;
				}
				return;
			}
			for (var child = node.firstChild; child; child = child.nextSibling) {
				walk(child);
			}
		};
		return walk;
	}

	/* واجهة المحاكي كلها: القوائم والنوافذ تُبنى أو يُعاد كتابة نصوصها أثناء اللعب. */
	function translate(emu, root) {
		var walk = walker(emu);
		if (!walk || !root) {
			return;
		}
		walk(root);
		new MutationObserver(function (list) {
			list.forEach(function (m) {
				if (m.type === 'characterData') {
					walk(m.target);
				} else {
					Array.prototype.forEach.call(m.addedNodes, walk);
				}
			});
		}).observe(root, { childList: true, subtree: true, characterData: true });
	}

	/* قائمة الإعدادات تُترجم فور بنائها، ثم يُعاد قياس عرضها: المحاكي قاسه بالنصوص الإنجليزية. */
	function translateSettings(emu) {
		var walk = walker(emu);
		var menu = emu.settingsMenu;
		if (!walk || !menu) {
			return;
		}
		walk(menu);
		var nested = menu.querySelector('.ejs_settings_transition');
		var home = nested && nested.firstElementChild;
		if (!home || typeof emu.getElementSize !== 'function') {
			return;
		}
		var display = menu.style.display;
		menu.style.display = '';
		var size = emu.getElementSize(home);
		menu.style.display = display;
		if (size && size.width) {
			nested.style.width = (size.width + 20) + 'px';
			nested.style.height = size.height + 'px';
		}
	}

	/* ---------- فشل التنزيل ---------- */

	function lastFailure() {
		for (var i = loads.length - 1; i >= 0; i--) {
			if (loads[i].status === 0 || loads[i].status >= 400) {
				return loads[i];
			}
		}
		return null;
	}

	/** @return {string} مفتاح السبب في T و A.hints */
	function cause(f) {
		var url;
		try { url = new URL(f.url, window.location.href); } catch (e) { return 'network'; }
		var bios = f.url === window.EJS_biosUrl && f.url !== window.EJS_gameUrl;
		if (navigator.onLine === false) {
			return 'offline';
		}
		if (f.status === 0) {
			if (window.location.protocol === 'https:' && url.protocol === 'http:') {
				return bios ? 'bios' : 'mixed';
			}
			if (url.origin !== window.location.origin) {
				return bios ? 'bios' : 'cors';
			}
			return 'network';
		}
		if (bios) {
			return 'bios';
		}
		if (['session', 'token', 'origin', 'missing', 'password'].indexOf(f.reason) !== -1) {
			return f.reason;
		}
		if (!C.protected || url.origin !== window.location.origin) {
			return 'link';
		}
		return f.status >= 500 ? 'server' : 'blocked';
	}

	function retryUrl(auto) {
		var url = new URL(window.location.href);
		if (auto && url.searchParams.has('rv_retry')) {
			return '';
		}
		// رابط مختلف في كل محاولة: لا تعيد ذاكرة تخزين مؤقت الصفحة القديمة نفسها.
		url.searchParams.set('rv_retry', auto ? '1' : Date.now().toString(36));
		url.searchParams.set('autostart', '1');
		url.hash = '';
		return url.href;
	}

	function fill(text, value) {
		return String(text || '').replace('%s', value);
	}

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) {
			node.className = cls;
		}
		if (text) {
			node.textContent = text;
		}
		return node;
	}

	function show(emu, title, text, key, f) {
		var parent = emu.elements && emu.elements.parent;
		if (!parent) {
			return;
		}
		var old = parent.querySelector('.rv-error');
		if (old) {
			old.parentNode.removeChild(old);
		}
		var box = el('div', 'rv-error');
		box.setAttribute('role', 'alert');
		box.appendChild(el('p', 'rv-error__title', title));
		if (text) {
			box.appendChild(el('p', 'rv-error__text', text));
		}
		var retry = el('button', 'rv-error__retry', T.retry);
		retry.type = 'button';
		retry.addEventListener('click', function () {
			window.location.replace(retryUrl(false));
		});
		box.appendChild(retry);

		if (A && key && A.hints && A.hints[key]) {
			var info = el('div', 'rv-error__admin');
			info.appendChild(el('strong', '', A.label));
			var host = '';
			try { host = new URL(f.url, window.location.href).host; } catch (e) { /* تجاهل */ }
			info.appendChild(el('p', '', fill(A.hints[key], key === 'origin' ? A.home : (key === 'session' ? A.cookie : host))));
			[A.code && A.code[key], f.method + ' ' + String(f.url).split('#')[0] + ' → ' + (f.status || A.noResponse) + (f.reason ? ' (' + f.reason + ')' : '')].forEach(function (line) {
				if (line) {
					var code = el('code', '', line);
					code.dir = 'ltr';
					info.appendChild(code);
				}
			});
			var links = el('p', 'rv-error__links');
			[[A.edit, A.editLabel], [key === 'bios' ? A.system : '', A.systemLabel], [A.help, A.helpLabel]].forEach(function (item) {
				if (!item[0]) {
					return;
				}
				var a = el('a', '', item[1]);
				a.href = item[0];
				a.target = '_blank';
				a.rel = 'noopener';
				links.appendChild(a);
			});
			info.appendChild(links);
			box.appendChild(info);
		}
		parent.appendChild(box);
		if (emu.textElem) {
			emu.textElem.hidden = true;
		}
		try { retry.focus({ preventScroll: true }); } catch (e) { /* تجاهل */ }
	}

	function failed(emu, message) {
		if (emu.started) {
			return;
		}
		var f = lastFailure();
		if (!f || message !== emu.localization('Network Error', false)) {
			// خطأ آخر (المحاكي نفسه لم يُنزَّل مثلاً): الرسالة كما هي مع زر المحاولة.
			show(emu, T.failed, message, '', null);
			return;
		}
		var key = cause(f);
		if ((key === 'token' || key === 'session') && retryUrl(true)) {
			// رابط الملف قديم (صفحة من ذاكرة مؤقتة) أو الجلسة بدأت للتو: محاولة واحدة تلقائية برابط جديد.
			if (emu.textElem) {
				emu.textElem.textContent = T.renewing;
			}
			window.location.replace(retryUrl(true));
			return;
		}
		show(emu, T.download, fill(T[key] || T.network, f.status), key, f);
	}

	window.RVEmulatorUI = {
		attach: function (emu) {
			if (!emu || emu.rvUI) {
				return;
			}
			emu.rvUI = true;
			translate(emu, emu.elements && emu.elements.parent);
			var setup = emu.setupSettingsMenu;
			emu.setupSettingsMenu = function () {
				var out = setup.apply(this, arguments);
				translateSettings(this);
				return out;
			};
			var startGameError = emu.startGameError;
			emu.startGameError = function (message) {
				var out = startGameError.apply(this, arguments);
				try { failed(this, message); } catch (e) { /* تبقى رسالة المحاكي */ }
				return out;
			};
		}
	};
})();
