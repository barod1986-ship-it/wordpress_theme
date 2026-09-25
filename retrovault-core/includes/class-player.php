<?php
/**
 * المشغّل (EmulatorJS).
 *
 * التصميم:
 * - /games/{slug}/play/ صفحة HTML مستقلة وخفيفة تحمّل EmulatorJS فقط، بعيداً عن CSS و JS القالب.
 * - صفحة اللعبة تعرض صورة وزر «ابدأ اللعب»؛ عند الضغط فقط يُنشأ iframe لتلك الصفحة.
 *   النتيجة: لا يُحمَّل المحاكي (عدة ميغابايت) إلا لمن يريد اللعب فعلاً، وأزرار الأسهم داخل
 *   اللعبة لا تحرّك الصفحة، ويمكن فتح المشغّل في نافذة كاملة على الجوال.
 * - ملف اللعبة المرفوع يصل للمشغّل برابط مؤقت من /games/{slug}/rom/ فقط (Roms).
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
		if ( array_intersect( array( 'rv_rom', 'rv_play', 'rv_download' ), array_keys( $vars ) ) && ! in_array( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '', array( 'GET', 'HEAD' ), true ) ) {
			header( 'Allow: GET, HEAD' );
			nocache_headers();
			wp_die( esc_html__( 'طريقة الطلب غير مسموحة.', 'retrovault-core' ), '', array( 'response' => 405 ) );
		}

		if ( array_key_exists( 'rv_rom', $vars ) ) {
			Roms::serve( get_queried_object() );
			exit;
		}
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
			'EJS_gameUrl'         => Roms::player_url( $game ),
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
	 * ترجمة واجهة EmulatorJS المرفقة مع الإضافة (languages/emulatorjs-{لغة}.json). ترجمته العربية آلية
	 * وفيها أخطاء كثيرة («Load State» ← «الدولة الحمل»). ما لا يوجد في الملف يظهر كما في المحاكي.
	 *
	 * @param string $language رمز لغة EmulatorJS مثل ar-AR.
	 * @return array|null
	 */
	public static function emulator_strings( $language ) {
		if ( ! preg_match( '/^[a-z]{2,3}-[A-Z]{2,3}$/', (string) $language ) ) {
			return null;
		}
		$file    = RETROVAULT_PATH . 'languages/emulatorjs-' . $language . '.json';
		$strings = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ملف محلي.
		$strings = (array) apply_filters( 'retrovault_emulator_strings', is_array( $strings ) ? $strings : array(), $language );
		return $strings ? $strings : null;
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

		// رابط ملف اللعبة مؤقت، فلا تحفظ إضافات التخزين المؤقت هذه الصفحة.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		Roms::prepare_session();
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
#rv-game{position:fixed;inset:0;width:100%;height:100%;touch-action:none}
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
			$config = self::config( $game, $autostart );
			foreach ( $config as $name => $value ) {
				echo 'window.' . preg_replace( '/[^A-Za-z0-9_]/', '', $name ) . ' = ' . wp_json_encode( $value, $flags ) . ";\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON آمن مع JSON_HEX_TAG.
			}
			$language = isset( $config['EJS_language'] ) ? (string) $config['EJS_language'] : '';
			$strings  = self::emulator_strings( $language );
			if ( $strings ) {
				// المحاكي يطلب ملف لغته من EJS_paths؛ نعطيه الترجمة من الصفحة نفسها فتعمل بدون إنترنت أيضاً.
				printf(
					"window.EJS_paths = window.EJS_paths || {};\nif (!window.EJS_paths[%1\$s]) { window.EJS_paths[%1\$s] = URL.createObjectURL(new Blob([JSON.stringify(%2\$s)], { type: 'application/json' })); }\n",
					wp_json_encode( $language ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					wp_json_encode( $strings, $flags ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
			}
			$ping = array(
				'id'   => $game['id'],
				'url'  => rest_url( 'retrovault/v1/games/' . $game['id'] . '/play' ),
				'page' => $game['url'],
			);
			?>
/* المحاكي يطلق «exit» أيضاً عند مغادرة الصفحة؛ هذا ليس طلب إنهاء من اللاعب */
window.rvUnloading = false;
window.addEventListener('beforeunload', function () { window.rvUnloading = true; });
window.addEventListener('pagehide', function () { window.rvUnloading = true; });
window.EJS_ready = function () {
	var emu = window.EJS_emulator;
	var d = <?php echo wp_json_encode( $ping, $flags ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	if (!emu || emu.rvReady) { return; }
	emu.rvReady = true;
	/* EmulatorJS 4.2 عند فشل تنزيل اللعبة (انقطاع الاتصال مثلاً) يسأل النواة عن خياراتها قبل أن تعمل، ثم
	 * يكمل تشغيل اللعبة بلا ملف؛ كلاهما يُنهي تبويب المتصفح كله. نُبقي رسالة الخطأ ظاهرة بدل ذلك،
	 * وزر «إعادة التشغيل» في صفحة اللعبة يعيد المحاولة. */
	var startGameError = emu.startGameError;
	emu.startGameError = function () {
		if (this.gameManager && !this.started) {
			this.gameManager.getCoreOptions = function () { return ''; };
		}
		return startGameError.apply(this, arguments);
	};
	var startGame = emu.startGame;
	emu.startGame = function () {
		if (this.failedToStart) { return; }
		return startGame.apply(this, arguments);
	};
	/* «إنهاء اللعب» من قائمة المحاكي: العودة لشاشة البداية في صفحة اللعبة (أو إليها في النافذة المستقلة).
	 * بعد ثانية ونصف: المحاكي يحفظ اللعبة ويُغلق نواته خلال ثانية من الإنهاء. */
	emu.on('exit', function () {
		if (!emu.started || emu.failedToStart || window.rvUnloading) { return; }
		setTimeout(function () {
			emu.started = false; // وإلا أطلق المحاكي «exit» ثانية عند مغادرة الصفحة وحاول إغلاق ما أغلقه
			if (window.parent !== window) {
				try { window.parent.postMessage({ type: 'rv:exit', id: d.id }, window.location.origin); } catch (e) {}
			} else {
				window.location.href = d.page;
			}
		}, 1500);
	});
};
window.EJS_onGameStart = function () {
	var d = <?php echo wp_json_encode( $ping, $flags ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	try { fetch(d.url, { method: 'POST', credentials: 'same-origin', keepalive: true }).catch(function () {}); } catch (e) {}
	try { if (window.parent !== window) { window.parent.postMessage({ type: 'rv:game-start', id: d.id }, window.location.origin); } } catch (e) {}
	if (typeof window.RV_afterStart === 'function') { window.RV_afterStart(); }
	if (typeof window.RV_markOffline === 'function') { window.RV_markOffline(); }
};
			<?php
			echo 'window.RVPWA = ' . wp_json_encode( Pwa::client_config(), $flags ) . ';';
				echo Pwa::player_script( $game ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON مُرمَّز.
			?>

			<?php
			if ( is_user_logged_in() && Saves::enabled() ) {
				self::cloud_script( $game, $flags );
			}
			?>
</script>
<script src="<?php echo esc_url( RETROVAULT_URL . 'assets/pwa.js?ver=' . RETROVAULT_VERSION ); ?>"></script>
			<?php if ( is_user_logged_in() && Saves::enabled() ) : ?>
<script src="<?php echo esc_url( RETROVAULT_URL . 'assets/cloud-saves.js?ver=' . RETROVAULT_VERSION ); ?>"></script>
			<?php endif; ?>
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
		// الملف المحمي يُرسل مباشرة دون كشف مكانه؛ غيره (رابط خارجي) يُحوَّل إليه كالسابق.
		Roms::download( $game );
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
				'sramChanged'  => __( 'تغيّر الحفظ أثناء اللعب. بقي تقدّمك على هذا الجهاز؛ أعد فتح اللعبة للمزامنة.', 'retrovault-core' ),
				'sramConflict' => __( 'يوجد تقدّم مختلف على هذا الجهاز وفي حسابك. اختر موافق لاستخدام حفظ الحساب، أو إلغاء للاحتفاظ بتقدّم هذا الجهاز ورفعه.', 'retrovault-core' ),
				'sramRestored' => __( 'استُعيد حفظ اللعبة من حسابك ✓', 'retrovault-core' ),
				'savedLocal'   => __( 'حُفظ على هذا الجهاز؛ لم يُؤكّد الحفظ السحابي بعد', 'retrovault-core' ),
				'loadedLocal'  => __( 'لا يوجد اتصال: استُكمل من حفظ هذا الجهاز', 'retrovault-core' ),
				'noneLocal'    => __( 'لا يوجد اتصال، ولا يوجد حفظ على هذا الجهاز بعد', 'retrovault-core' ),
			),
		);
		?>
window.RVCloud = <?php echo wp_json_encode( $cfg, $flags ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
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
			<?php $close = self::icon( 'close' ); ?>
			<button type="button" class="rv-player__exit" data-rv-action="exit-immersive" aria-label="<?php esc_attr_e( 'العودة إلى صفحة اللعبة', 'retrovault-core' ); ?>" hidden><?php echo '' !== $close ? $close : '<span aria-hidden="true">&times;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
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
