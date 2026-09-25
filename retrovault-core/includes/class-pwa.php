<?php
/**
 * تطبيق ويب (PWA) واللعب بدون إنترنت.
 *
 * - الموقع قابل للتثبيت كتطبيق (الجوال وسطح المكتب).
 * - أول مرة تُشغَّل فيها لعبة وأنت متصل تُحفظ ملفاتها (المحاكي، النواة، ملف اللعبة) في
 *   المتصفح؛ بعدها تعمل بدون إنترنت.
 * - بدون اتصال: الصفحات التي زرتها تُعرض من الذاكرة، والباقي يعرض صفحة «غير متصل»
 *   بقائمة الألعاب الجاهزة على هذا الجهاز.
 *
 * الروابط (بدون قواعد إعادة كتابة، فتعمل مع أي إعداد روابط):
 *   /?rv_sw=1        عامل الخدمة (نطاقه جذر الموقع)
 *   /?rv_manifest=1  ملف التطبيق
 *   /?rv_offline=1   صفحة «غير متصل»
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Pwa {

	const OFFLINE_CACHE = 'rv-offline';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function enabled() {
		return (bool) Settings::get( 'pwa' );
	}

	/** مسار الموقع (يدعم التثبيت في مجلد فرعي). */
	public static function scope() {
		$path = (string) wp_parse_url( get_option( 'home' ), PHP_URL_PATH );
		return '' === $path ? '/' : trailingslashit( $path );
	}

	/** جذر الموقع بلا بادئة لغة (عامل الخدمة واحد للغتين). */
	private static function root() {
		return trailingslashit( set_url_scheme( get_option( 'home' ) ) );
	}

	/**
	 * @param string $what sw|manifest|offline.
	 */
	public static function url( $what ) {
		return add_query_arg( 'rv_' . $what, '1', self::root() );
	}

	/**
	 * مفتاح «هذه اللعبة جاهزة بدون إنترنت» في ذاكرة المتصفح.
	 *
	 * @param int $game_id رقم اللعبة.
	 */
	public static function offline_key( $game_id ) {
		return self::scope() . '__rv-offline/' . (int) $game_id;
	}

	public static function serve() {
		if ( ! self::enabled() ) {
			return;
		}
		foreach ( array( 'sw', 'manifest', 'offline' ) as $what ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ 'rv_' . $what ] ) ) {
				call_user_func( array( __CLASS__, 'serve_' . $what ) );
				exit;
			}
		}
	}

	/**
	 * أيقونات التطبيق: أيقونة الموقع إن وُضعت (المظهر ← تخصيص ← هوية الموقع)، وإلا أيقونات الإضافة.
	 *
	 * @return array[]
	 */
	private static function icons() {
		if ( has_site_icon() ) {
			return array(
				array(
					'src'   => get_site_icon_url( 192 ),
					'sizes' => '192x192',
					'type'  => 'image/png',
				),
				array(
					'src'   => get_site_icon_url( 512 ),
					'sizes' => '512x512',
					'type'  => 'image/png',
				),
			);
		}
		return array(
			array(
				'src'   => RETROVAULT_URL . 'assets/icon-192.png',
				'sizes' => '192x192',
				'type'  => 'image/png',
			),
			array(
				'src'   => RETROVAULT_URL . 'assets/icon-512.png',
				'sizes' => '512x512',
				'type'  => 'image/png',
			),
			array(
				'src'     => RETROVAULT_URL . 'assets/icon-maskable-512.png',
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'maskable',
			),
		);
	}

	private static function serve_manifest() {
		$name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$short = (string) Settings::get( 'app_name' );
		$short = '' !== $short ? $short : ( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 12 ) : substr( $name, 0, 12 ) );

		$shortcuts = array(
			array(
				'name' => __( 'المكتبة', 'retrovault-core' ),
				'url'  => get_post_type_archive_link( Post_Types::GAME ),
			),
			array(
				'name' => __( 'لعبة عشوائية', 'retrovault-core' ),
				'url'  => add_query_arg( 'rv_random', '1', home_url( '/' ) ),
			),
		);
		if ( Account::url() ) {
			$shortcuts[] = array(
				'name' => __( 'حسابي', 'retrovault-core' ),
				'url'  => Account::url(),
			);
		}

		$manifest = array(
			'id'               => self::scope(),
			'name'             => $name,
			'short_name'       => $short,
			'description'      => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
			'start_url'        => add_query_arg( 'source', 'pwa', self::root() ),
			'scope'            => self::scope(),
			'display'          => 'standalone',
			'orientation'      => 'any',
			'dir'              => is_rtl() ? 'rtl' : 'ltr',
			'lang'             => get_bloginfo( 'language' ),
			'background_color' => '#cfcdd4',
			'theme_color'      => '#cfcdd4',
			'icons'            => self::icons(),
			'shortcuts'        => $shortcuts,
		);

		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( apply_filters( 'retrovault_pwa_manifest', $manifest ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function serve_sw() {
		$data_path = Settings::data_path();
		$uploads   = wp_upload_dir( null, false );
		$skip      = array(
			(string) wp_parse_url( admin_url(), PHP_URL_PATH ),
			(string) wp_parse_url( site_url( 'wp-login.php' ), PHP_URL_PATH ),
			(string) wp_parse_url( rest_url(), PHP_URL_PATH ),
			(string) wp_parse_url( site_url( 'wp-cron.php' ), PHP_URL_PATH ),
		);
		if ( Account::url() ) {
			$skip[] = (string) wp_parse_url( Account::url(), PHP_URL_PATH );
		}
		$config = array(
			'version'  => RETROVAULT_VERSION . '-' . substr( md5( $data_path . wp_json_encode( self::icons() ) ), 0, 8 ),
			'scope'    => self::scope(),
			'offline'  => self::url( 'offline' ),
			'precache' => array_merge( array( self::url( 'offline' ) ), wp_list_pluck( self::icons(), 'src' ) ),
			'dataPath' => $data_path,
			'uploads'  => trailingslashit( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) ),
			'romExt'   => Uploads::extensions(),
			'skip'     => array_values( array_filter( $skip ) ),
			'maxPages' => 60,
			'maxShell' => 300,
		);

		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . self::scope() );
		header( 'Cache-Control: no-cache' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$js = (string) file_get_contents( RETROVAULT_PATH . 'assets/sw.js' );
		echo 'self.RV_SW = ' . wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ";\n" . $js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function serve_offline() {
		$name = get_bloginfo( 'name' );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( __( 'غير متصل', 'retrovault-core' ) . ' — ' . $name ); ?></title>
<style>
:root{--plastic:#cfcdd4;--hi:#e3e1e7;--well:#bdbac5;--seam:#9b98a5;--ink:#25232b;--soft:#4e4b57;--link:#2e46a6;--bezel:#1e1d23;--menu:#1c2a96;--accent:#ffd84d}
@media (prefers-color-scheme:dark){:root{--plastic:#26252b;--hi:#302f36;--well:#1f1e24;--seam:#46444f;--ink:#ecebf0;--soft:#b4b1bd;--link:#9fb0ff;--bezel:#121115}}
*{box-sizing:border-box}
body{margin:0;background:var(--plastic);color:var(--ink);font:400 17px/1.7 "IBM Plex Sans Arabic","Segoe UI",Tahoma,system-ui,sans-serif}
main{width:min(100% - 32px,40rem);margin:0 auto;padding:40px 0}
.tv{padding:16px 16px 12px;border-radius:24px;background:var(--bezel)}
.screen{display:grid;gap:8px;place-content:center;min-height:180px;padding:24px;border-radius:16px/20px;background:var(--menu);color:#f2f1ff;text-align:center}
.screen strong{font:800 2.4rem/1.1 "Handjet","IBM Plex Sans Arabic",system-ui,sans-serif;color:var(--accent)}
h1{margin:28px 0 8px;font-size:1.3rem}
ul{display:grid;gap:10px;margin:0;padding:0;list-style:none}
li a{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;background:var(--hi);box-shadow:inset 0 0 0 1px var(--seam);color:var(--ink);font-weight:600;text-decoration:none}
.sys{padding:0 8px 2px;border-radius:999px;background:var(--sys,#555);color:#fff;font:600 1rem/1.4 "Handjet",system-ui,sans-serif}
.empty{padding:16px;border:1.5px dashed var(--seam);border-radius:12px;color:var(--soft)}
.retry{display:inline-block;margin-top:20px;color:var(--link)}
</style>
</head>
<body>
<main>
	<div class="tv"><div class="screen">
		<strong><?php esc_html_e( 'غير متصل', 'retrovault-core' ); ?></strong>
		<span><?php esc_html_e( 'لا يوجد اتصال بالإنترنت الآن.', 'retrovault-core' ); ?></span>
	</div></div>
	<h1><?php esc_html_e( 'ألعاب تعمل على هذا الجهاز بدون إنترنت', 'retrovault-core' ); ?></h1>
	<ul id="rv-offline-games"></ul>
	<p class="empty" id="rv-offline-empty" hidden><?php esc_html_e( 'لا توجد ألعاب جاهزة بعد. افتح أي لعبة وشغّلها مرة وأنت متصل، وستعمل بعدها بدون إنترنت.', 'retrovault-core' ); ?></p>
	<a class="retry" href="<?php echo esc_url( self::root() ); ?>"><?php esc_html_e( 'حاول الاتصال مرة أخرى', 'retrovault-core' ); ?></a>
</main>
<script>
(function () {
	var list = document.getElementById('rv-offline-games');
	var empty = document.getElementById('rv-offline-empty');
	if (!window.caches) { empty.hidden = false; return; }
	caches.open(<?php echo wp_json_encode( self::OFFLINE_CACHE ); ?>).then(function (cache) {
		return cache.keys().then(function (keys) {
			return Promise.all(keys.map(function (k) { return cache.match(k).then(function (r) { return r.json(); }); }));
		});
	}).then(function (games) {
		games = games.filter(function (g) { return g && g.protocol === 2; }).map(function (g) { return g.data; });
		games.sort(function (a, b) { return b.time - a.time; });
		games.forEach(function (g) {
			var li = document.createElement('li');
			var a = document.createElement('a');
			a.href = g.url;
			if (g.short) {
				var chip = document.createElement('span');
				chip.className = 'sys';
				chip.style.setProperty('--sys', g.color || '#555');
				chip.textContent = g.short;
				a.appendChild(chip);
			}
			a.appendChild(document.createTextNode(g.title));
			li.appendChild(a);
			list.appendChild(li);
		});
		empty.hidden = games.length > 0;
	}).catch(function () { empty.hidden = false; });
})();
</script>
</body>
</html>
		<?php
	}

	public static function head() {
		if ( ! self::enabled() ) {
			return;
		}
		$icons = self::icons();
		printf( '<link rel="manifest" href="%s">' . "\n", esc_url( self::url( 'manifest' ) ) );
		echo '<meta name="theme-color" content="#cfcdd4" media="(prefers-color-scheme: light)">' . "\n";
		echo '<meta name="theme-color" content="#26252b" media="(prefers-color-scheme: dark)">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		printf( '<link rel="apple-touch-icon" href="%s">' . "\n", esc_url( $icons[0]['src'] ) );
	}

	/** Configuration shared by the theme and the standalone player. */
	public static function client_config() {
		return array(
			'enabled' => self::enabled(),
			'sw'      => self::url( 'sw' ),
			'scope'   => self::scope(),
			'cache'   => self::OFFLINE_CACHE,
			'dataPath' => Settings::data_path(),
			'assets'  => RETROVAULT_URL . 'assets/',
			'user'    => is_user_logged_in() ? substr( wp_hash( 'rv-pwa|' . get_current_user_id() ), 0, 12 ) : '',
		);
	}

	public static function assets() {
		// Keep the small lifecycle script when disabled so existing installations are removed.
		wp_enqueue_script( 'retrovault-pwa', RETROVAULT_URL . 'assets/pwa.js', array(), RETROVAULT_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script( 'retrovault-pwa', 'RVPWA', self::client_config() );
	}

	/**
	 * سكربت صفحة المشغّل: يسجّل اللعبة «جاهزة بدون إنترنت» عند بدء تشغيلها.
	 *
	 * @param array $game بيانات اللعبة.
	 * @return string JavaScript
	 */
	public static function player_script( $game ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$marker = array(
			'key'  => self::offline_key( $game['id'] ),
			'data' => array(
				'id'    => (int) $game['id'],
				'title' => $game['title'],
				'url'   => $game['url'],
				'short' => $game['system'] ? $game['system']['short'] : '',
				'color' => $game['system'] ? $game['system']['color'] : '',
			),
		);
		$marker['rom']     = Roms::player_url( $game );
		$marker['version'] = $game['rom']['ver'];
		return 'window.RVOfflineGame = ' . wp_json_encode( $marker, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . ';';
	}
}
