/* RetroVault — المشغّل داخل صفحة اللعبة.
 * المحاكي لا يُحمَّل إلا عند الضغط على «ابدأ اللعب»: يُنشأ iframe لصفحة /play/ الخفيفة. */
(function () {
	'use strict';

	/* على الجوال تملأ شاشة اللعب الجهاز كله (iPhone لا يدعم ملء الشاشة لغير الفيديو). زر الإغلاق أو
	 * زر الرجوع في المتصفح يعيدانك للصفحة، واللعبة تبقى تعمل فيها. */
	var immersiveQuery = window.matchMedia ? window.matchMedia('(pointer: coarse) and (max-width: 900px), (pointer: coarse) and (max-height: 500px)') : null;

	function setImmersive(root, on, fromHistory) {
		var exit = root.querySelector('[data-rv-action="exit-immersive"]');
		if (on) {
			if (!immersiveQuery || !immersiveQuery.matches || root.classList.contains('is-immersive')) {
				return;
			}
			root.classList.add('is-immersive');
			document.documentElement.classList.add('rv-immersive');
			if (exit) {
				exit.hidden = false;
			}
			try { window.history.pushState({ rvImmersive: true }, ''); } catch (e) { /* تجاهل */ }
			return;
		}
		if (!root.classList.contains('is-immersive')) {
			return;
		}
		root.classList.remove('is-immersive');
		document.documentElement.classList.remove('rv-immersive');
		if (exit) {
			exit.hidden = true;
		}
		if (!fromHistory && window.history.state && window.history.state.rvImmersive) {
			window.history.back();
		}
		root.scrollIntoView({ block: 'center' });
	}

	/* «إنهاء اللعب»: شاشة البداية كما كانت قبل الضغط على «ابدأ اللعب» */
	function stop(root) {
		if (!root || !root.classList.contains('is-running')) {
			return;
		}
		setImmersive(root, false);
		var screen = root.querySelector('.rv-player__screen');
		screen.textContent = '';
		(root.rvIdle || []).forEach(function (node) { screen.appendChild(node); });
		root.classList.remove('is-running');
		var reload = root.querySelector('[data-rv-action="reload"]');
		if (reload) {
			reload.hidden = true;
		}
		var startBtn = screen.querySelector('[data-rv-start]');
		if (startBtn) {
			startBtn.focus();
		}
		root.dispatchEvent(new CustomEvent('rv:player-stop', { bubbles: true }));
	}

	function start(root, resume) {
		if (!root || root.classList.contains('is-running')) {
			return;
		}
		var screen = root.querySelector('.rv-player__screen');
		var frame = document.createElement('iframe');
		var src = root.getAttribute('data-src');
		if (resume) {
			var slot = (typeof resume === 'string' && /^[a-z0-9]{16}$/.test(resume)) ? resume : '1';
			src += (src.indexOf('?') === -1 ? '?' : '&') + 'resume=' + slot;
		}
		frame.className = 'rv-player__frame';
		frame.src = src;
		frame.title = root.getAttribute('data-title') || '';
		frame.setAttribute('allow', 'fullscreen; gamepad; autoplay');
		frame.setAttribute('allowfullscreen', '');

		root.rvIdle = Array.prototype.slice.call(screen.childNodes);
		screen.textContent = '';
		screen.appendChild(frame);
		root.classList.add('is-running');
		setImmersive(root, true);

		var reload = root.querySelector('[data-rv-action="reload"]');
		if (reload) {
			reload.hidden = false;
		}
		frame.addEventListener('load', function () {
			try { frame.focus(); } catch (e) { /* تجاهل */ }
		});
		root.dispatchEvent(new CustomEvent('rv:player-start', { bubbles: true }));
	}

	function canFullscreen() {
		return !!(document.fullscreenEnabled || document.webkitFullscreenEnabled);
	}

	function toggleFullscreen(root) {
		/* على الجوال «ملء الشاشة» هو وضع اللعب الكامل نفسه (ويعمل على iPhone أيضاً) */
		if (immersiveQuery && immersiveQuery.matches) {
			if (!root.classList.contains('is-running')) {
				start(root);
			} else {
				setImmersive(root, !root.classList.contains('is-immersive'));
			}
			return;
		}
		var target = root.querySelector('.rv-player__screen');
		if (document.fullscreenElement || document.webkitFullscreenElement) {
			(document.exitFullscreen || document.webkitExitFullscreen).call(document);
			return;
		}
		if (!root.classList.contains('is-running')) {
			start(root);
		}
		var request = target.requestFullscreen || target.webkitRequestFullscreen;
		if (request) {
			var result = request.call(target);
			if (result && result.catch) {
				result.catch(function () { /* المتصفح رفض */ });
			}
		}
	}

	function setTheater(btn, on) {
		document.body.classList.toggle('rv-theater', on);
		document.querySelectorAll('[data-rv-action="theater"]').forEach(function (b) {
			b.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		if (on && btn) {
			btn.closest('[data-rv-player]').scrollIntoView({ block: 'center', behavior: 'smooth' });
		}
	}

	document.addEventListener('click', function (e) {
		var startBtn = e.target.closest('[data-rv-start]');
		if (startBtn) {
			start(startBtn.closest('[data-rv-player]'), startBtn.hasAttribute('data-rv-resume'));
			return;
		}
		var action = e.target.closest('[data-rv-action]');
		if (!action) {
			return;
		}
		var root = action.closest('[data-rv-player]');
		switch (action.getAttribute('data-rv-action')) {
			case 'fullscreen':
				toggleFullscreen(root);
				break;
			case 'theater':
				setTheater(action, !document.body.classList.contains('rv-theater'));
				break;
			case 'reload':
				var frame = root.querySelector('iframe');
				if (frame) {
					frame.src = frame.src;
				}
				break;
			case 'exit-immersive':
				setImmersive(root, false);
				break;
		}
	});

	window.addEventListener('popstate', function () {
		document.querySelectorAll('[data-rv-player].is-immersive').forEach(function (root) {
			setImmersive(root, false, true);
		});
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && document.body.classList.contains('rv-theater')) {
			setTheater(null, false);
		}
	});

	if (!canFullscreen() && !(immersiveQuery && immersiveQuery.matches)) {
		document.querySelectorAll('[data-rv-action="fullscreen"]').forEach(function (b) {
			b.hidden = true;
		});
	}

	/* رابط «استكمل» من صفحة الحساب: #resume (آخر حفظ) أو #resume=رمز (خانة محددة) */
	function resumeFromHash() {
		var match = /^#resume(?:=([a-z0-9]{16}))?$/.exec(window.location.hash);
		var root = match ? document.querySelector('[data-rv-player][data-rv-can-resume]') : null;
		if (!root) {
			return;
		}
		root.scrollIntoView({ block: 'center' });
		if (root.classList.contains('is-running')) {
			/* المشغّل يعمل: أعد تشغيله من الخانة المطلوبة */
			var frame = root.querySelector('iframe');
			if (frame) {
				frame.src = root.getAttribute('data-src') + '&resume=' + (match[1] || '1');
			}
			return;
		}
		start(root, match[1] || true);
	}
	resumeFromHash();
	window.addEventListener('hashchange', resumeFromHash);

	/* رسالة من داخل المشغّل عند بدء اللعبة فعلياً (بعد تحميل النواة والملف) */
	window.addEventListener('message', function (e) {
		if (e.origin !== window.location.origin || !e.data) {
			return;
		}
		if (e.data.type === 'rv:game-start') {
			document.dispatchEvent(new CustomEvent('rv:game-start', { detail: e.data }));
		} else if (e.data.type === 'rv:exit') {
			document.querySelectorAll('[data-rv-player].is-running').forEach(function (root) {
				var frame = root.querySelector('iframe');
				if (frame && frame.contentWindow === e.source) {
					stop(root);
				}
			});
		} else if (e.data.type === 'rv:saved') {
			document.dispatchEvent(new CustomEvent('rv:game-saved', { detail: e.data }));
		}
	});
})();
