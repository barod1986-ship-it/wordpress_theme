<?php
/**
 * المشغّل (EmulatorJS).
 *
 * التصميم:
 * - /games/{slug}/play/ صفحة HTML مستقلة وخفيفة تحمّل EmulatorJS فقط، بعيداً عن CSS و JS القالب.
 * - صفحة اللعبة تعرض صورة وزر «ابدأ اللعب»؛ عند الضغط فقط يُنشأ iframe لتلك الصفحة.
 *   النتيجة: لا يُحمَّل المحاكي (عدة ميغابايت) إلا لمن يريد اللعب فعلاً، وأزرار الأسهم داخل
 *   اللعبة لا تحرّك الصفحة، ويمكن فتح المشغّل في نافذة كاملة على الجوال.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Player {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_script( 'retrovault-player', RETROVAULT_URL . 'assets/player.js', array(), RETROVAULT_VERSION, true );
		wp_register_style( 'retrovault-player', RETROVAULT_URL . 'assets/player.css', array(), RETROVAULT_VERSION );
	}

	public static function route() {
		if ( ! is_singular( Post_Types::GAME ) ) {
			return;
		}
		global $wp_query;
		$vars = (array) $wp_query->query;

		if ( array_key_exists( 'rv_play', $vars ) ) {
			self::render( get_queried_object() );
			exit;
		}
		if ( array_key_exists( 'rv_download', $vars ) ) {
			self::download( get_queried_object() );
			exit;
		}
	}

	/**
	 * إعدادات EmulatorJS (تُطبع كمتغيرات EJS_* عامة).
	 *
	 * @param array $game      بيانات اللعبة.
	 * @param bool  $autostart تشغيل تلقائي (عند الفتح من زر صفحة اللعبة).
	 * @return array
	 */
	public static function config( $game, $autostart ) {
		$config = array(
			'EJS_player'          => '#rv-game',
			'EJS_core'            => $game['core'],
			'EJS_gameUrl'         => $game['rom']['url'],
			'EJS_gameName'        => get_post_field( 'post_name', $game['id'] ),
			'EJS_pathtodata'      => Settings::data_path(),
			'EJS_color'           => (string) Settings::get( 'accent' ),
			'EJS_language'        => Settings::emulator_language(),
			'EJS_startOnLoaded'   => (bool) $autostart,
			'EJS_startButtonName' => __( 'ابدأ اللعب', 'retrovault-core' ),
			'EJS_backgroundBlur'  => true,
			// رقم اللعبة: يفصل إعدادات المحاكي بين الألعاب، ويلزم للعب الجماعي.
			'EJS_gameID'          => (int) $game['id'],
			// «حفظ الحالة» للزائر يبقى في متصفحه، وحفظ اللعبة الداخلي يُكتب كل 30 ثانية بدل 5 دقائق.
			'EJS_defaultOptions'  => array(
				'save-state-location' => 'browser',
				'save-save-interval'  => '30',
			),
			'EJS_Buttons'         => array( 'netplay' => false ),
		);

		if ( Settings::get( 'netplay' ) ) {
			unset( $config['EJS_Buttons'] );
			$server = (string) Settings::get( 'netplay_url' );
			if ( '' !== $server ) {
				$config['EJS_netplayServer'] = $server;
			}
		}

		if ( ! $autostart ) {
			$poster = Games::poster( $game );
			if ( $poster['url'] ) {
				$config['EJS_backgroundImage'] = $poster['url'];
			}
		}
		if ( ! empty( $game['system']['bios'] ) ) {
			$config['EJS_biosUrl'] = $game['system']['bios'];
		}

		return (array) apply_filters( 'retrovault_player_config', $config, $game );
	}

	/**
	 * @param \WP_Post $post اللعبة.
	 */
	private static function render( $post ) {
		$game = Games::get( $post );
		if ( ! $game || post_password_required( $post ) ) {
			wp_safe_redirect( $game ? $game['url'] : home_url( '/' ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$autostart = ! empty( $_GET['autostart'] );
		$lang      = get_bloginfo( 'language' );
		$dir       = is_rtl() ? 'rtl' : 'ltr';
		$flags     = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( "Content-Security-Policy: frame-ancestors 'self'" );
		?>
<!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $game['title'] . ' — ' . get_bloginfo( 'name' ) ); ?></title>
<style>
html,body{margin:0;height:100%;background:#000;color:#eee;overflow:hidden;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}
#rv-game{position:fixed;inset:0;width:100%;height:100%}
.rv-msg{position:fixed;inset:0;display:grid;place-content:center;gap:12px;text-align:center;padding:24px;line-height:1.7}
.rv-msg a{color:#fff}
</style>
</head>
<body>
		<?php if ( ! $game['playable'] ) : ?>
<div class="rv-msg">
	<p><?php esc_html_e( 'هذه اللعبة غير جاهزة للتشغيل بعد.', 'retrovault-core' ); ?></p>
	<p><a href="<?php echo esc_url( $game['url'] ); ?>" target="_top"><?php esc_html_e( 'العودة إلى صفحة اللعبة', 'retrovault-core' ); ?></a></p>
</div>
		<?php else : ?>
<div id="rv-game"></div>
<noscript><div class="rv-msg"><?php esc_html_e( 'تشغيل الألعاب يحتاج تفعيل JavaScript في المتصفح.', 'retrovault-core' ); ?></div></noscript>
<script>
			<?php
			foreach ( self::config( $game, $autostart ) as $name => $value ) {
				echo 'window.' . preg_replace( '/[^A-Za-z0-9_]/', '', $name ) . ' = ' . wp_json_encode( $value, $flags ) . ";\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON آمن مع JSON_HEX_TAG.
			}
			$ping = array(
				'id'  => $game['id'],
				'url' => rest_url( 'retrovault/v1/games/' . $game['id'] . '/play' ),
			);
			?>
window.EJS_onGameStart = function () {
	var d = <?php echo wp_json_encode( $ping, $flags ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	try { fetch(d.url, { method: 'POST', credentials: 'same-origin', keepalive: true }).catch(function () {}); } catch (e) {}
	try { if (window.parent !== window) { window.parent.postMessage({ type: 'rv:game-start', id: d.id }, window.location.origin); } } catch (e) {}
	if (typeof window.RV_afterStart === 'function') { window.RV_afterStart(); }
	if (typeof window.RV_markOffline === 'function') { window.RV_markOffline(); }
};
			<?php
			echo Pwa::player_script( $game ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON مُرمَّز.
			?>

			<?php
			if ( is_user_logged_in() && Saves::enabled() ) {
				self::cloud_script( $game, $flags );
			}
			?>
</script>
<script src="<?php echo esc_url( Settings::data_path() . 'loader.js' ); ?>"></script>
		<?php endif; ?>
</body>
</html>
		<?php
	}

	/**
	 * @param \WP_Post $post اللعبة.
	 */
	private static function download( $post ) {
		$game = Games::get( $post );
		if ( ! $game || ! $game['downloadable'] || post_password_required( $post ) ) {
			wp_die(
				esc_html__( 'تنزيل هذه اللعبة غير متاح.', 'retrovault-core' ),
				esc_html__( 'غير متاح', 'retrovault-core' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		Stats::hit( $game['id'], '_rv_download_count' );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		wp_redirect( $game['rom']['raw_url'], 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- رابط أدخله المدير (قد يكون على نطاق آخر).
		exit;
	}

	/**
	 * الحفظ السحابي داخل صفحة المشغّل (للأعضاء فقط).
	 * EmulatorJS يستدعي EJS_onSaveState/EJS_onLoadState بدل الحفظ المحلي عندما تكون معرّفة.
	 *
	 * @param array $game  بيانات اللعبة.
	 * @param int   $flags خيارات JSON.
	 */
	private static function cloud_script( $game, $flags ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$resume = isset( $_GET['resume'] ) ? sanitize_key( wp_unslash( $_GET['resume'] ) ) : '';
		if ( '1' !== $resume && ! Saves::valid_token( $resume ) ) {
			$resume = '';
		}
		$cfg = array(
			'rest'   => rest_url( Rest::NS . '/' ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'id'     => (int) $game['id'],
			'user'   => get_current_user_id(),
			'core'   => $game['core'],
			'max'    => Saves::max_bytes(),
			'resume' => $resume,
			'sram'   => Saves::sram_enabled(),
			'i18n'   => array(
				'saving'       => __( 'جارٍ الحفظ في حسابك…', 'retrovault-core' ),
				'saved'        => __( 'حُفظ في حسابك ✓', 'retrovault-core' ),
				'loaded'       => __( 'استُكمل من الحفظ ✓', 'retrovault-core' ),
				'none'         => __( 'لا يوجد حفظ في حسابك لهذه اللعبة بعد', 'retrovault-core' ),
				'tooBig'       => __( 'الحفظ أكبر من الحد المسموح', 'retrovault-core' ),
				'failed'       => __( 'تعذّر الاتصال بالحفظ السحابي', 'retrovault-core' ),
				'sramRestored' => __( 'استُعيد حفظ اللعبة من حسابك ✓', 'retrovault-core' ),
				'savedLocal'   => __( 'لا يوجد اتصال: حُفظ على هذا الجهاز', 'retrovault-core' ),
				'loadedLocal'  => __( 'لا يوجد اتصال: استُكمل من حفظ هذا الجهاز', 'retrovault-core' ),
				'noneLocal'    => __( 'لا يوجد اتصال، ولا يوجد حفظ على هذا الجهاز بعد', 'retrovault-core' ),
			),
		);
		?>
(function () {
	var C = <?php echo wp_json_encode( $cfg, $flags ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
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
		try { ejs().storage.states.put(localName(), state); say(C.i18n.savedLocal); } catch (e) { say(C.i18n.failed); }
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
		if (!navigator.onLine) { saveLocal(e.state); return; }
		say(C.i18n.saving);
		Promise.all([pack(e.state), shot(e)]).then(function (res) {
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
			if (offlineError(err)) { saveLocal(e.state); return; }
			say((err && err.message) ? err.message : C.i18n.failed);
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

	/* ---------- حفظ اللعبة الداخلي (SRAM): مزامنة تلقائية ----------
	 * ثلاث نسخ: المحلية، السحابية، وآخر نسخة زامنها هذا الجهاز (base).
	 * تغيّرت المحلية فقط ← رفع. تغيّرت السحابية فقط ← تنزيل. تغيّرتا معاً ← نسخة الحساب تفوز. */
	var baseKey = 'rv-sram-' + C.user + '-' + C.id;
	var lastHash = null;
	var leaving = false;
	var hash = function (b) {
		var h = 0x811c9dc5;
		for (var i = 0; i < b.length; i++) { h ^= b[i]; h = Math.imul(h, 0x01000193); }
		return (h >>> 0).toString(16) + '-' + b.length;
	};
	var getBase = function () { try { return localStorage.getItem(baseKey) || ''; } catch (e) { return ''; } };
	var setBase = function (h) { lastHash = h; try { localStorage.setItem(baseKey, h); } catch (e) {} };

	var uploadSram = function (bytes, h) {
		lastHash = h;
		return pack(bytes).then(function (p) {
			var form = new FormData();
			form.append('sram', p.data, 'game.srm');
			form.append('encoding', p.enc);
			form.append('hash', h);
			/* keepalive يُكمل الرفع حتى لو أُغلقت الصفحة (حد المتصفح 64KB) */
			return api('/sram', { method: 'POST', body: form, keepalive: p.data.size < 60000 });
		}).then(function (r) {
			if (r.ok) { setBase(h); } else { lastHash = null; }
		}).catch(function () { lastHash = null; });
	};
	/* عند إغلاق الصفحة لا وقت للضغط: رفع فوري بدون ضغط إن كان صغيراً */
	var beaconSram = function (bytes, h) {
		if (!navigator.sendBeacon || bytes.length > 60000) { return; }
		var form = new FormData();
		form.append('sram', new Blob([bytes]), 'game.srm');
		form.append('encoding', 'raw');
		form.append('hash', h);
		if (navigator.sendBeacon(C.rest + 'games/' + C.id + '/sram?_wpnonce=' + encodeURIComponent(C.nonce), form)) { setBase(h); }
	};
	/* EmulatorJS يطلق saveSaveFiles كلما كتب حفظ اللعبة (كل 30 ثانية وعند الإغلاق) */
	var onFlush = function (bytes) {
		if (!bytes || !bytes.length) { return; }
		var h = hash(bytes);
		if (h === (lastHash || getBase())) { return; }
		if (leaving) { beaconSram(bytes, h); } else { uploadSram(bytes, h); }
	};
	var writeSram = function (bytes, restart) {
		var gm = ejs().gameManager;
		var path = gm.getSaveFilePath();
		try { if (gm.FS.analyzePath(path).exists) { gm.FS.unlink(path); } } catch (e) {}
		gm.FS.writeFile(path, bytes);
		gm.loadSaveFiles();
		/* إعادة تشغيل الجهاز لتقرأ اللعبة حفظها من البداية */
		if (restart) { gm.restart(); }
	};
	var syncSram = function (restart) {
		var gm, path, local = null;
		try { gm = ejs().gameManager; path = gm.getSaveFilePath(); } catch (e) { return Promise.resolve(); }
		if (!path) { return Promise.resolve(); }
		try { if (gm.FS.analyzePath(path).exists) { local = gm.FS.readFile(path); } } catch (e) {}
		var localHash = (local && local.length) ? hash(local) : '';
		var base = getBase();
		return api('/sram')
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (info) {
				var cloudHash = (info && info.exists) ? info.hash : '';
				if (!cloudHash && !localHash) { return null; }
				if (cloudHash === localHash) { setBase(cloudHash); return null; }
				if (!cloudHash || (localHash && localHash !== base && cloudHash === base)) {
					return uploadSram(local, localHash);
				}
				return api('/sram/file')
					.then(function (r) {
						if (!r.ok) { throw new Error('http'); }
						var enc = r.headers.get('X-RV-Encoding') || 'raw';
						return r.arrayBuffer().then(function (b) { return unpack(b, enc); });
					})
					.then(function (bytes) {
						writeSram(bytes, restart);
						setBase(cloudHash);
						say(C.i18n.sramRestored);
					});
			})
			.catch(function () {});
	};

	window.addEventListener('beforeunload', function () { leaving = true; });
	window.addEventListener('pagehide', function () { leaving = true; });
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState !== 'hidden') { return; }
		try { if (ejs() && ejs().started) { ejs().gameManager.saveSaveFiles(); } } catch (e) {}
	});

	window.RV_afterStart = function () {
		/* المزامنة أولاً، ثم الاستكمال من حالة (الحالة تتضمن ذاكرة اللعبة فلا داعي لإعادة التشغيل) */
		var first = C.sram ? syncSram(!C.resume) : Promise.resolve();
		first.then(function () {
			if (C.sram) { try { ejs().on('saveSaveFiles', onFlush); } catch (e) {} }
			if (C.resume) { setTimeout(function () { load(true, C.resume); }, 300); }
		});
	};
})();
		<?php
	}

	/**
	 * كود المشغّل داخل صفحة اللعبة.
	 *
	 * @param int|\WP_Post|null $post اللعبة.
	 * @return string HTML
	 */
	public static function markup( $post = null ) {
		$game = Games::get( $post );
		if ( ! $game ) {
			return '';
		}

		wp_enqueue_script( 'retrovault-player' );
		if ( ! current_theme_supports( 'retrovault' ) ) {
			wp_enqueue_style( 'retrovault-player' );
		}

		$ratio = $game['system'] ? $game['system']['ratio'] : '4/3';
		$color = $game['system'] ? $game['system']['color'] : '#555560';
		$style = sprintf( '--rv-ratio:%s;--rv-sys:%s', $ratio, $color );

		if ( ! $game['playable'] ) {
			return sprintf(
				'<div class="rv-player rv-player--empty" style="%1$s"><div class="rv-player__screen"><p class="rv-player__notice">%2$s</p></div></div>',
				esc_attr( $style ),
				esc_html__( 'ملف هذه اللعبة لم يُرفع بعد.', 'retrovault-core' )
			);
		}

		$poster = Games::poster( $game );
		$src    = add_query_arg( 'autostart', '1', $game['player_url'] );
		$save   = ( is_user_logged_in() && Saves::enabled() ) ? Saves::info( get_current_user_id(), $game['id'] ) : null;

		ob_start();
		?>
		<div class="rv-player" data-rv-player<?php echo $save ? ' data-rv-can-resume' : ''; ?> data-src="<?php echo esc_url( $src ); ?>" data-title="<?php echo esc_attr( $game['title'] ); ?>" style="<?php echo esc_attr( $style ); ?>">
			<div class="rv-player__screen">
				<button type="button" class="rv-player__start" data-rv-start>
					<?php if ( $poster['url'] ) : ?>
						<img class="rv-player__poster<?php echo $poster['pixel'] ? ' is-pixel' : ''; ?>" src="<?php echo esc_url( $poster['url'] ); ?>" alt="" decoding="async">
					<?php endif; ?>
					<span class="rv-player__cta"><?php echo self::icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'ابدأ اللعب', 'retrovault-core' ); ?></span></span>
					<span class="rv-player__hint"><?php esc_html_e( 'يعمل داخل المتصفح — لوحة المفاتيح أو يد التحكم أو اللمس', 'retrovault-core' ); ?></span>
				</button>
				<?php if ( $save ) : ?>
					<button type="button" class="rv-player__resume" data-rv-start data-rv-resume>
						<?php echo self::icon( 'cloud' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="rv-player__resume-text">
							<span><?php esc_html_e( 'استكمل من آخر حفظ', 'retrovault-core' ); ?></span>
							<small>
								<?php
								echo esc_html( $save['ago'] );
								if ( $save['outdated'] ) {
									/* translators: %s: game version */
									echo ' — ' . esc_html( sprintf( __( 'من الإصدار %s وقد لا يعمل مع الحالي', 'retrovault-core' ), $save['version'] ) );
								} elseif ( $save['core_mismatch'] ) {
									echo ' — ' . esc_html__( 'من نواة محاكٍ مختلفة وقد لا يعمل', 'retrovault-core' );
								}
								?>
							</small>
						</span>
					</button>
				<?php endif; ?>
				<noscript><a class="rv-player__nojs" href="<?php echo esc_url( $game['player_url'] ); ?>"><?php esc_html_e( 'افتح المشغّل', 'retrovault-core' ); ?></a></noscript>
			</div>
			<div class="rv-player__bar">
				<button type="button" class="rv-player__btn" data-rv-action="fullscreen"><?php echo self::icon( 'fullscreen' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'ملء الشاشة', 'retrovault-core' ); ?></span></button>
				<button type="button" class="rv-player__btn" data-rv-action="theater" aria-pressed="false"><?php echo self::icon( 'theater' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'وضع العرض الواسع', 'retrovault-core' ); ?></span></button>
				<button type="button" class="rv-player__btn" data-rv-action="reload" hidden><?php echo self::icon( 'reload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'إعادة التشغيل', 'retrovault-core' ); ?></span></button>
				<a class="rv-player__btn" href="<?php echo esc_url( $game['player_url'] ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'نافذة مستقلة', 'retrovault-core' ); ?></span></a>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * الأيقونات يوفّرها القالب عبر الفلتر (الإضافة لا تفرض شكلاً).
	 *
	 * @param string $name اسم الأيقونة.
	 */
	private static function icon( $name ) {
		return (string) apply_filters( 'retrovault_icon', '', $name );
	}
}
