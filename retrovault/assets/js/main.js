/* RetroVault — سكربت القالب (بدون مكتبات). كل ميزة هنا تحسين؛ الموقع يعمل بدونها. */
(function () {
	'use strict';

	var RVT = window.RVT || { i18n: {} };
	var i18n = RVT.i18n || {};
	var isRtl = document.documentElement.dir === 'rtl';

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	/* ---------- إشعار عابر ---------- */
	var toastEl = null;
	var toastTimer = 0;
	function toast(msg) {
		if (!toastEl) {
			toastEl = document.createElement('div');
			toastEl.className = 'toast';
			toastEl.setAttribute('role', 'status');
			document.body.appendChild(toastEl);
		}
		toastEl.textContent = msg;
		toastEl.classList.add('is-on');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { toastEl.classList.remove('is-on'); }, 2600);
	}

	/* ---------- العدّ بالعربية (مطابق لدالة rvt_count في PHP) ---------- */
	function countLabel(n, forms) {
		if (!forms || forms.length < 5) { return String(n); }
		var mod = n % 100;
		var form;
		if (n === 0) { form = forms[0]; }
		else if (n === 1) { form = forms[1]; }
		else if (n === 2) { form = forms[2]; }
		else if (mod >= 3 && mod <= 10) { form = forms[3]; }
		else { form = forms[4]; }
		return form.replace('%s', String(n));
	}

	/* ترويسات REST */
	function restHeaders(json) {
		var h = { 'X-WP-Nonce': RVT.nonce };
		if (json) { h['Content-Type'] = 'application/json'; }
		return h;
	}

	function formatAverage(avg) {
		return Number(avg).toFixed(1).replace('.', RVT.decimal || '.');
	}

	/* ---------- القائمة على الجوال ---------- */
	var navToggle = $('[data-nav-toggle]');
	if (navToggle) {
		navToggle.addEventListener('click', function () {
			var open = document.body.classList.toggle('nav-open');
			navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	}

	/* قوائم details تُغلق عند النقر خارجها أو Escape */
	document.addEventListener('click', function (e) {
		$$('details[data-dropdown][open]').forEach(function (d) {
			if (!d.contains(e.target)) { d.removeAttribute('open'); }
		});
	});
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		$$('details[data-dropdown][open]').forEach(function (d) { d.removeAttribute('open'); });
		if (document.body.classList.contains('nav-open') && navToggle) {
			document.body.classList.remove('nav-open');
			navToggle.setAttribute('aria-expanded', 'false');
		}
	});

	/* ---------- شاشة الملتي كارت ---------- */
	var mc = $('[data-multicart]');
	if (mc) {
		var items = $$('[data-mc-item]', mc);
		var art = $('[data-mc-art]', mc);
		var blank = $('[data-mc-blank]', mc);
		var sys = $('[data-mc-sys]', mc);
		var genre = $('[data-mc-genre]', mc);

		var select = function (link) {
			items.forEach(function (a) {
				var active = a === link;
				a.parentNode.classList.toggle('is-active', active);
				a.setAttribute('tabindex', active ? '0' : '-1');
			});
			var src = link.getAttribute('data-art');
			if (art) {
				art.hidden = !src;
				if (src) { art.src = src; }
			}
			if (blank) {
				blank.hidden = !!src;
				blank.textContent = link.getAttribute('data-sys') || '';
			}
			if (sys) {
				sys.textContent = link.getAttribute('data-sys') || '';
				sys.style.setProperty('--sys', link.getAttribute('data-sys-color') || '#444');
				sys.hidden = !link.getAttribute('data-sys');
			}
			if (genre) { genre.textContent = link.getAttribute('data-genre') || ''; }
		};

		items.forEach(function (a) {
			a.addEventListener('mouseenter', function () { select(a); });
			a.addEventListener('focus', function () { select(a); });
		});

		mc.addEventListener('keydown', function (e) {
			var idx = items.indexOf(document.activeElement);
			if (idx < 0) { return; }
			var next = null;
			if (e.key === 'ArrowDown') { next = items[(idx + 1) % items.length]; }
			else if (e.key === 'ArrowUp') { next = items[(idx - 1 + items.length) % items.length]; }
			else if (e.key === 'Home') { next = items[0]; }
			else if (e.key === 'End') { next = items[items.length - 1]; }
			if (next) {
				e.preventDefault();
				next.focus();
			}
		});

		/* تحميل مسبق للأغلفة لتبديل فوري */
		items.forEach(function (a) {
			var src = a.getAttribute('data-art');
			if (src) { var im = new Image(); im.src = src; }
		});
	}

	/* ---------- الفلترة دون إعادة تحميل الصفحة ---------- */
	var form = $('[data-filters]');
	if (form && window.fetch && window.DOMParser && window.history && window.URL) {
		var controller = null;

		var urlFromForm = function () {
			var url = new URL(form.getAttribute('action'), window.location.href);
			new FormData(form).forEach(function (value, key) {
				if (value === '' || (key === 'sort' && value === 'newest')) { return; }
				url.searchParams.set(key, value);
			});
			return url.toString();
		};

		var syncForm = function (url) {
			var params = new URL(url).searchParams;
			$$('select, input[type="search"]', form).forEach(function (el) {
				if (el.name === 'system' && !params.has('system') && el.value && form.dataset.lockedSystem) { return; }
				var v = params.get(el.name);
				el.value = v !== null ? v : (el.name === 'sort' ? 'newest' : '');
			});
		};

		var load = function (url, push) {
			var results = document.getElementById('rv-results');
			if (!results) { window.location.href = url; return; }
			if (controller) { controller.abort(); }
			controller = window.AbortController ? new AbortController() : null;
			results.setAttribute('aria-busy', 'true');

			fetch(url, { credentials: 'same-origin', signal: controller ? controller.signal : undefined })
				.then(function (res) {
					if (!res.ok) { throw new Error(res.status); }
					return res.text();
				})
				.then(function (html) {
					var doc = new DOMParser().parseFromString(html, 'text/html');
					var fresh = doc.getElementById('rv-results');
					if (!fresh) { window.location.href = url; return; }
					results.replaceWith(fresh);
					document.title = doc.title;
					if (push) { window.history.pushState({ rvFilters: true }, '', url); }
				})
				.catch(function (err) {
					if (err && err.name === 'AbortError') { return; }
					window.location.href = url;
				})
				.then(function () {
					var r = document.getElementById('rv-results');
					if (r) { r.removeAttribute('aria-busy'); }
				});
		};

		form.addEventListener('change', function (e) {
			if (e.target.tagName === 'SELECT') { load(urlFromForm(), true); }
		});
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			load(urlFromForm(), true);
		});
		document.addEventListener('click', function (e) {
			var a = e.target.closest('#rv-results .pagination a');
			if (!a || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
			e.preventDefault();
			load(a.href, true);
			form.scrollIntoView({ block: 'start', behavior: 'smooth' });
		});
		window.addEventListener('popstate', function () {
			syncForm(window.location.href);
			load(window.location.href, false);
		});
	}

	/* ---------- التقييم بالنجوم ---------- */
	$$('[data-rating-box]').forEach(function (box) {
		var gameId = box.getAttribute('data-game');
		var msg = $('[data-rating-msg]', box);
		var clearBtn = $('[data-rating-clear]', box);
		var timer = 0;
		var busy = false;

		var render = function (data) {
			$$('[data-rating-avg]').forEach(function (el) {
				el.textContent = data.count ? formatAverage(data.average) : '—';
			});
			$$('[data-rating-count]').forEach(function (el) {
				el.textContent = countLabel(data.count, i18n.ratingForms);
			});
			$$('[data-rating-stars]').forEach(function (el) {
				el.style.setProperty('--fill', (Math.max(0, Math.min(5, data.average)) / 5 * 100) + '%');
			});
			$$('input[name="rv-rating"]', box).forEach(function (input) {
				input.checked = Number(input.value) === Number(data.user);
			});
			if (clearBtn) { clearBtn.hidden = !data.user; }
		};

		var send = function (method, rating) {
			if (busy) { return; }
			busy = true;
			if (msg) { msg.textContent = i18n.saving || ''; }
			fetch(RVT.rest + 'games/' + gameId + '/rating', {
				method: method,
				credentials: 'same-origin',
				headers: restHeaders(true),
				body: rating ? JSON.stringify({ rating: rating }) : undefined
			})
				.then(function (res) {
					return res.json().then(function (json) {
						if (!res.ok) { throw json; }
						return json;
					});
				})
				.then(function (data) {
					render(data);
					if (msg) { msg.textContent = method === 'DELETE' ? (i18n.removed || '') : (i18n.saved || ''); }
				})
				.catch(function (err) {
					if (msg) { msg.textContent = (err && err.message) ? err.message : (i18n.error || ''); }
				})
				.then(function () { busy = false; });
		};

		box.addEventListener('change', function (e) {
			if (e.target.name !== 'rv-rating') { return; }
			var value = Number(e.target.value);
			clearTimeout(timer);
			timer = setTimeout(function () { send('POST', value); }, 300);
		});
		if (clearBtn) {
			clearBtn.addEventListener('click', function () { send('DELETE'); });
		}
	});

	/* ---------- طلب REST عام للأعضاء ---------- */
	function member(method, path) {
		return fetch(RVT.rest + path, {
			method: method,
			credentials: 'same-origin',
			headers: restHeaders()
		}).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok) { throw json; }
				return json;
			});
		});
	}

	/* ---------- المفضلة ---------- */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-favorite]');
		if (!btn || btn.disabled) { return; }
		var on = btn.getAttribute('aria-pressed') === 'true';
		btn.disabled = true;
		member(on ? 'DELETE' : 'POST', 'games/' + btn.getAttribute('data-game') + '/favorite')
			.then(function (data) {
				btn.setAttribute('aria-pressed', data.favorite ? 'true' : 'false');
				var label = btn.querySelector('[data-fav-label]');
				if (label) { label.textContent = data.favorite ? i18n.favOn : i18n.favOff; }
				toast(data.favorite ? i18n.favAdded : i18n.favRemoved);
				if (!data.favorite && btn.hasAttribute('data-remove-on-off')) {
					var item = btn.closest('[data-fav-item]');
					if (item) { item.remove(); }
				}
			})
			.catch(function (err) { toast((err && err.message) || i18n.error); })
			.then(function () { btn.disabled = false; });
	});

	/* ---------- حذف الحفظات (صفحة حسابي): خانة واحدة أو كل حالات اللعبة ---------- */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-delete-save]');
		if (!btn || btn.disabled) { return; }
		var slot = btn.getAttribute('data-slot') || '';
		if (!window.confirm(slot ? i18n.delSlot : i18n.delConfirm)) { return; }
		btn.disabled = true;
		member('DELETE', 'games/' + btn.getAttribute('data-game') + '/save' + (slot ? '?slot=' + encodeURIComponent(slot) : ''))
			.then(function (data) {
				var card = btn.closest('[data-save-card]');
				if (slot) {
					var item = btn.closest('[data-save-slot]');
					var list = item ? item.closest('[data-state-ui]') : null;
					if (item) { item.remove(); }
					if (list && !list.querySelector('[data-save-slot]')) { list.remove(); }
				} else if (card && card.hasAttribute('data-has-sram')) {
					/* يبقى حفظ اللعبة الداخلي: نزيل عناصر الحالات فقط ونُظهر زر «العب» */
					$$('[data-state-ui]', card).forEach(function (el) { el.remove(); });
					var play = $('[data-play-link]', card);
					if (play) { play.hidden = false; }
				} else if (card) {
					card.remove();
				}
				toast(i18n.delDone);
			})
			.catch(function (err) {
				btn.disabled = false;
				toast((err && err.message) || i18n.error);
			});
	});

	/* ---------- رسائل التحديثات (صفحة حسابي) ---------- */
	document.addEventListener('change', function (e) {
		var box = e.target.closest('[data-notify-toggle]');
		if (!box) { return; }
		box.disabled = true;
		fetch(RVT.rest + 'me/notify', {
			method: 'POST',
			credentials: 'same-origin',
			headers: restHeaders(true),
			body: JSON.stringify({ email: box.checked })
		})
			.then(function (res) { return res.json().then(function (j) { if (!res.ok) { throw j; } return j; }); })
			.then(function (data) {
				box.checked = !!data.email;
				toast(data.email ? i18n.notifyOn : i18n.notifyOff);
			})
			.catch(function (err) {
				box.checked = !box.checked;
				toast((err && err.message) || i18n.error);
			})
			.then(function () { box.disabled = false; });
	});

	/* ---------- المشاركة ---------- */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-share]');
		if (!btn) { return; }
		var data = { title: btn.getAttribute('data-title') || document.title, url: btn.getAttribute('data-url') || window.location.href };
		if (navigator.share) {
			navigator.share(data).catch(function () { /* أُلغيت */ });
			return;
		}
		if (navigator.clipboard) {
			navigator.clipboard.writeText(data.url).then(function () { toast(i18n.copied || data.url); });
		}
	});

	/* ---------- عارض اللقطات ---------- */
	var dialog = $('[data-lightbox]');
	if (dialog && typeof dialog.showModal === 'function') {
		var shots = $$('[data-shot]');
		var lbImg = $('.lightbox__img', dialog);
		var lbCount = $('[data-lb-count]', dialog);
		var current = 0;

		var show = function (i) {
			current = (i + shots.length) % shots.length;
			var a = shots[current];
			lbImg.src = a.href;
			lbImg.alt = a.getAttribute('data-alt') || '';
			lbImg.classList.toggle('is-pixel', a.classList.contains('is-pixel'));
			if (lbCount) { lbCount.textContent = (current + 1) + ' / ' + shots.length; }
		};

		shots.forEach(function (a, i) {
			a.addEventListener('click', function (e) {
				e.preventDefault();
				show(i);
				dialog.showModal();
			});
		});
		$('[data-lb-prev]', dialog).addEventListener('click', function () { show(current - 1); });
		$('[data-lb-next]', dialog).addEventListener('click', function () { show(current + 1); });
		$('[data-lb-close]', dialog).addEventListener('click', function () { dialog.close(); });
		dialog.addEventListener('click', function (e) { if (e.target === dialog) { dialog.close(); } });
		dialog.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowLeft') { show(current + (isRtl ? 1 : -1)); }
			if (e.key === 'ArrowRight') { show(current + (isRtl ? -1 : 1)); }
		});
	}

	/* ---------- 404: «متابعة؟ 9…0» ---------- */
	var countdown = $('[data-countdown]');
	if (countdown && !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
		var n = 9;
		var tick = setInterval(function () {
			n -= 1;
			if (n <= 0) {
				clearInterval(tick);
				countdown.textContent = countdown.getAttribute('data-end') || i18n.gameOver || '';
				var wrap = countdown.closest('.gameover');
				if (wrap) { wrap.classList.add('is-over'); }
				return;
			}
			countdown.textContent = String(n);
		}, 1000);
	}
})();
