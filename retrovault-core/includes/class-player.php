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
		global $wp_query;
		$vars = (array) $wp_query->query;
		if ( ! is_singular( Post_Types::GAME ) ) {
			// ملف لعبة لم تعد منشورة أو حُذفت: 404 بسبب يفهمه المشغّل بدل صفحة 404 من القالب.
			if ( is_404() && array_key_exists( 'rv_rom', $vars ) ) {
				Roms::not_found();
			}
			return;
		}
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

	/** لون الموقع (زر «أعد المحاولة»)، مثل لون المحاكي. */
	private static function accent() {
		$color = sanitize_hex_color( (string) Settings::get( 'accent' ) );
		return $color ? $color : '#D63A3A';
	}

	/** نص أبيض على اللون الداكن، وداكن على الفاتح (المدير يختار لون الموقع). */
	private static function accent_text() {
		$hex = ltrim( self::accent(), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$lum = 0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
			$c    = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$lum += $weight * ( $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 ) );
		}
		// التباين مع الأبيض أقل من 4.5 عندما تتجاوز الإضاءة 0.183 تقريباً.
		return $lum > 0.183 ? '#111' : '#fff';
	}

	/**
	 * EmulatorJS مصمَّم لاتجاه LTR فقط. في صفحة RTL ينعكس شريطه فيصير زر الإعدادات يساره، وقائمته
	 * (وقائمة الأقراص) تُفتح نحو اليسار كالعادة فتخرج من الشاشة. هنا نعكس ما يُحدَّد بـ left/right.
	 */
	private static function rtl_css() {
		return <<<'CSS'
[dir=rtl] .ejs_big_screen .ejs_settings_parent{right:auto;left:-3px}
[dir=rtl] .ejs_big_screen .ejs_settings_parent::before,[dir=rtl] .ejs_big_screen .ejs_settings_parent::after{right:auto;left:15px}
[dir=rtl] .ejs_settings_parent{text-align:right}
[dir=rtl] .ejs_settings_main_bar{padding-right:11px;padding-left:28px}
[dir=rtl] .ejs_settings_main_bar::after{right:auto;left:5px;border-left-color:transparent;border-right-color:rgba(79,91,95,.8)}
[dir=rtl] .ejs_settings_main_bar:hover::after{border-left-color:transparent;border-right-color:currentColor}
[dir=rtl] .ejs_settings_main_bar_selected{margin-left:-5px;margin-right:auto;padding-left:0;padding-right:25px}
[dir=rtl] .ejs_back_button::after{left:auto;right:7px;border-right-color:transparent;border-left-color:rgba(79,91,95,.8)}
[dir=rtl] .ejs_back_button:hover::after{border-right-color:transparent;border-left-color:currentColor}
[dir=rtl] .ejs_option_row::before{margin-right:0;margin-left:10px}
[dir=rtl] .ejs_option_row::after{left:auto;right:12px}
[dir=rtl] .ejs_big_screen .ejs_menu_text{left:auto;right:0;transform-origin:100% 100%}
[dir=rtl] .ejs_big_screen .ejs_menu_text::before{left:auto;right:16px;transform:translateX(50%)}
[dir=rtl] .ejs_big_screen .ejs_menu_text_right{left:0!important;right:auto;transform-origin:0 100%}
[dir=rtl] .ejs_big_screen .ejs_menu_text_right::before{left:16px!important;right:auto;transform:translateX(-50%)!important}
[dir=rtl] .ejs_volume_parent{padding-right:0;padding-left:15px}
[dir=rtl] .ejs_volume_parent input[type=range]{direction:ltr}
[dir=rtl] .ejs_small_screen .ejs_menu_button svg{float:right}
[dir=rtl] .ejs_message{text-align:right}
[dir=rtl] .ejs_popup_container [style*="float: left"],[dir=rtl] .ejs_popup_container [style*="float:left"]{float:right!important}
[dir=rtl] .ejs_popup_container [style*="float: right"],[dir=rtl] .ejs_popup_container [style*="float:right"]{float:left!important}

CSS;
	}

	/**
	 * نصوص assets/emulator-ui.js: سبب فشل تنزيل ملف اللعبة بدل «تحقق من اتصالك» العامة.
	 * التفاصيل التقنية (وروابط الإصلاح) لمن يحرّر اللعبة فقط.
	 *
	 * @param array $game بيانات اللعبة.
	 * @return array
	 */
	public static function ui_config( $game ) {
		$config = array(
			'protected' => ! empty( $game['rom']['protected'] ),
			'i18n'      => array(
				'retry'    => __( 'أعد المحاولة', 'retrovault-core' ),
				'renewing' => __( 'جارٍ تجديد رابط اللعبة…', 'retrovault-core' ),
				'failed'   => __( 'تعذّر تشغيل اللعبة', 'retrovault-core' ),
				'download' => __( 'تعذّر تنزيل اللعبة', 'retrovault-core' ),
				'offline'  => __( 'لا يوجد اتصال بالإنترنت. تحقق من اتصالك ثم أعد المحاولة.', 'retrovault-core' ),
				'network'  => __( 'انقطع الاتصال قبل اكتمال تنزيل ملف اللعبة. أعد المحاولة.', 'retrovault-core' ),
				'cors'     => __( 'ملف اللعبة على موقع آخر لا يسمح بتشغيله من هنا.', 'retrovault-core' ),
				'mixed'    => __( 'رابط ملف اللعبة غير آمن (http) فمنعه المتصفح.', 'retrovault-core' ),
				'session'  => __( 'المتصفح لم يحتفظ بملف تعريف الارتباط اللازم لتشغيل اللعبة. اسمح بملفات تعريف الارتباط لهذا الموقع ثم أعد المحاولة.', 'retrovault-core' ),
				'token'    => __( 'انتهت صلاحية رابط ملف اللعبة. أعد المحاولة.', 'retrovault-core' ),
				'origin'   => __( 'رُفض طلب ملف اللعبة لأنه لم يأتِ من المشغّل.', 'retrovault-core' ),
				'missing'  => __( 'ملف اللعبة غير موجود على الخادم.', 'retrovault-core' ),
				'password' => __( 'هذه اللعبة محمية بكلمة مرور. افتح صفحتها وأدخل كلمة المرور أولاً.', 'retrovault-core' ),
				/* translators: %s: HTTP status code */
				'blocked'  => __( 'تعذّر الوصول إلى ملف اللعبة (خطأ %s).', 'retrovault-core' ),
				/* translators: %s: HTTP status code */
				'server'   => __( 'حدث خطأ في الخادم أثناء إرسال ملف اللعبة (خطأ %s). أعد المحاولة بعد قليل.', 'retrovault-core' ),
				/* translators: %s: HTTP status code */
				'link'     => __( 'رابط ملف اللعبة لا يعمل (خطأ %s).', 'retrovault-core' ),
				'bios'     => __( 'تعذّر تنزيل ملف BIOS الذي يحتاجه نظام هذه اللعبة.', 'retrovault-core' ),
			),
		);
		if ( ! current_user_can( 'edit_post', $game['id'] ) ) {
			return $config;
		}
		$system = $game['system'] ? get_edit_term_link( (int) $game['system']['term_id'], Post_Types::SYSTEM ) : '';
		$config['admin'] = array(
			'label'       => __( 'لمدير الموقع:', 'retrovault-core' ),
			'noResponse'  => __( 'لا رد', 'retrovault-core' ),
			'home'        => home_url( '/' ),
			'cookie'      => Roms::cookie_name(),
			'edit'        => (string) get_edit_post_link( $game['id'], 'raw' ),
			'editLabel'   => __( 'تحرير اللعبة', 'retrovault-core' ),
			'system'      => (string) $system,
			'systemLabel' => __( 'تحرير النظام', 'retrovault-core' ),
			'help'        => current_user_can( 'view_site_health_checks' ) ? admin_url( 'site-health.php' ) : '',
			'helpLabel'   => __( 'فحص الموقع', 'retrovault-core' ),
			'hints'       => array(
				'offline'  => __( 'الجهاز غير متصل بالإنترنت.', 'retrovault-core' ),
				'network'  => __( 'الخادم لم يُكمل إرسال الملف. إن تكرر هذا مع الألعاب الكبيرة فقد تقطع مهلة الخادم أو جدار الحماية التنزيل؛ جرّب الملف مضغوطاً (zip) أو اطلب من الاستضافة رفع المهلة.', 'retrovault-core' ),
				/* translators: %s: host name */
				'cors'     => __( 'الملف على نطاق آخر (%s) لا يرسل الترويسة Access-Control-Allow-Origin، أو الرابط صفحة مشاركة وليس الملف نفسه (Google Drive مثلاً). ارفع الملف إلى مكتبة الوسائط من صفحة تحرير اللعبة بدل الرابط المباشر.', 'retrovault-core' ),
				'mixed'    => __( 'الموقع يعمل بـ https والرابط المباشر للملف يبدأ بـ http://، والمتصفح يمنع ذلك. استخدم رابط https أو ارفع الملف إلى مكتبة الوسائط.', 'retrovault-core' ),
				/* translators: %s: cookie name */
				'session'  => __( 'طلب الملف وصل بلا ملف تعريف الارتباط %s الذي تضعه صفحة المشغّل. إن كانت الاستضافة أو CDN (Varnish مثلاً) تحذف ملفات تعريف الارتباط غير المعروفة، فاطلب منها السماح به.', 'retrovault-core' ),
				'token'    => __( 'رمز رابط الملف لا يطابق جلسة المتصفح حتى بعد تجديد الصفحة، وغالباً السبب أن صفحة المشغّل محفوظة في ذاكرة تخزين مؤقت. استثنِ هذه الروابط من إضافة التخزين المؤقت أو CDN:', 'retrovault-core' ),
				/* translators: %s: site address */
				'origin'   => __( 'الطلب وصل بلا ترويسات Sec-Fetch، ولا ترويسة المشغّل X-RetroVault-Player، ولا Referer يطابق عنوان الموقع (%s). وكيل (Proxy) أو CDN أو جدار حماية يحذفها في الطريق؛ اسمح بمرورها. وتأكد أن «عنوان الموقع» في الإعدادات ← عام هو نفسه الذي يظهر في المتصفح (https و www).', 'retrovault-core' ),
				'missing'  => __( 'الملف المرفوع لهذه اللعبة غير موجود أو لا يمكن قراءته في مجلد uploads/retrovault-roms. أعد رفعه من صفحة تحرير اللعبة.', 'retrovault-core' ),
				'password' => __( 'اللعبة محمية بكلمة مرور.', 'retrovault-core' ),
				'blocked'  => __( 'الطلب لم يصل إلى ووردبريس أصلاً: رفضه الخادم أو جدار حماية قبل ذلك. في nginx يجب أن تُحيل قاعدة الملفات الثابتة الطلب إلى index.php إن لم يكن الملف موجوداً، هكذا:', 'retrovault-core' ),
				'server'   => __( 'راجع سجل أخطاء PHP في الاستضافة؛ الملفات الكبيرة قد تتجاوز حدود الذاكرة أو المهلة.', 'retrovault-core' ),
				'link'     => __( 'الرابط المحفوظ لملف اللعبة يعيد هذا الخطأ. تحقق منه في صفحة تحرير اللعبة، أو ارفع الملف إلى مكتبة الوسائط.', 'retrovault-core' ),
				/* translators: %s: host name */
				'bios'     => __( 'رابط BIOS في إعدادات النظام (%s) لا يعمل أو على موقع لا يسمح بالتنزيل من موقعك. عدّله من الألعاب ← الأنظمة، أو ارفع الملف إلى مكتبة الوسائط.', 'retrovault-core' ),
			),
		);
		// أسطر تُعرض كما هي (من اليسار لليمين): قاعدة nginx، ومسارات صفحة المشغّل وملف اللعبة في هذا الموقع.
		global $wp_rewrite;
		$slug  = (string) get_post_field( 'post_name', $game['id'] );
		$paths = array( '?rv_play=', '?rv_rom=' );
		if ( $wp_rewrite && $wp_rewrite->using_permalinks() && '' !== $slug ) {
			$paths = array();
			foreach ( array( 'play' => '', 'rom' => '*' ) as $endpoint => $suffix ) {
				$paths[] = str_replace( '/' . $slug . '/', '/*/', (string) wp_make_link_relative( Games::endpoint_url( $game['id'], $endpoint ) ) ) . $suffix;
			}
		}
		$config['admin']['code'] = array(
			'blocked' => 'try_files $uri $uri/ /index.php?$args;',
			'token'   => implode( "\n", $paths ),
		);
		return $config;
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
.rv-error{position:absolute;inset:0;z-index:10000;margin:auto;width:min(92%,440px);height:fit-content;max-height:92%;overflow:auto;box-sizing:border-box;display:grid;gap:10px;justify-items:center;padding:18px 16px;border-radius:12px;background:rgba(12,12,14,.94);box-shadow:0 8px 30px rgba(0,0,0,.6);color:#ddd;font:14px/1.7 system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;text-align:center}
.rv-error p{margin:0}
.rv-error__title{color:#fff;font-weight:700;font-size:16px}
.rv-error__retry{font:inherit;font-weight:700;color:<?php echo esc_html( self::accent_text() ); ?>;background:<?php echo esc_html( self::accent() ); ?>;border:0;border-radius:999px;padding:7px 24px;cursor:pointer}
.rv-error__retry:focus-visible{outline:2px solid #fff;outline-offset:2px}
.rv-error__admin{justify-self:stretch;display:grid;gap:6px;padding:10px 12px;border-radius:8px;background:rgba(255,255,255,.07);color:#bbb;font-size:13px;text-align:start}
.rv-error__admin strong{color:#fff}
.rv-error__admin code{display:block;font-size:12px;overflow-wrap:anywhere;white-space:pre-wrap;text-align:left;color:#9ad}
.rv-error__links{display:flex;flex-wrap:wrap;gap:4px 14px}
.rv-error__links a{color:#fff}
			<?php
			if ( 'rtl' === $dir ) {
				echo self::rtl_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS ثابت.
			}
			?>
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
	 * وemulator-ui.js يشرح سببها ويعرض زر «أعد المحاولة». */
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
	if (window.RVEmulatorUI) { window.RVEmulatorUI.attach(emu); }
};
window.EJS_onGameStart = function () {
	var d = <?php echo wp_json_encode( $ping, $flags ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	try { fetch(d.url, { method: 'POST', credentials: 'same-origin', keepalive: true }).catch(function () {}); } catch (e) {}
	try { if (window.parent !== window) { window.parent.postMessage({ type: 'rv:game-start', id: d.id }, window.location.origin); } } catch (e) {}
	if (typeof window.RV_afterStart === 'function') { window.RV_afterStart(); }
	if (typeof window.RV_markOffline === 'function') { window.RV_markOffline(); }
};
			<?php
			echo 'window.RVPlayerUI = ' . wp_json_encode( self::ui_config( $game ), $flags ) . ";\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON آمن مع JSON_HEX_TAG.
			echo 'window.RVPWA = ' . wp_json_encode( Pwa::client_config(), $flags ) . ';';
				echo Pwa::player_script( $game ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON مُرمَّز.
			?>

			<?php
			if ( is_user_logged_in() && Saves::enabled() ) {
				self::cloud_script( $game, $flags );
			}
			?>
</script>
<script src="<?php echo esc_url( RETROVAULT_URL . 'assets/emulator-ui.js?ver=' . RETROVAULT_VERSION ); ?>"></script>
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
			$message = ( ! $game['rom']['id'] && '' === $game['rom']['raw_url'] )
				? __( 'ملف هذه اللعبة لم يُرفع بعد.', 'retrovault-core' )
				: __( 'هذه اللعبة غير جاهزة للتشغيل بعد.', 'retrovault-core' );
			// لمن يحرّر اللعبة: ما الذي ينقصها، ورابط لإكماله.
			$fix  = '';
			$edit = current_user_can( 'edit_post', $game['id'] ) ? get_edit_post_link( $game['id'] ) : '';
			if ( $edit ) {
				$fix = sprintf(
					'<a class="rv-player__fix" href="%1$s">%2$s</a>',
					esc_url( $edit ),
					/* translators: %s: what is missing */
					esc_html( sprintf( __( 'أكملها من لوحة التحكم: %s', 'retrovault-core' ), implode( '، ', Game_Meta::problems( $game ) ) ) )
				);
			}
			return sprintf(
				'<div class="rv-player rv-player--empty" style="%1$s"><div class="rv-player__screen"><p class="rv-player__notice">%2$s%3$s</p></div></div>',
				esc_attr( $style ),
				esc_html( $message ),
				$fix // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- مُهرَّب أعلاه.
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
						<?php // أكبر صورة في أعلى صفحة اللعبة: تُطلب مع الخطوط بدل انتظار دورها بعدها. ?>
						<img class="rv-player__poster<?php echo $poster['pixel'] ? ' is-pixel' : ''; ?>" src="<?php echo esc_url( $poster['url'] ); ?>" alt="" decoding="async" fetchpriority="high">
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
