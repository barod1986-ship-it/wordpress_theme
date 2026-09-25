<?php
/**
 * حماية ملفات الألعاب من التنزيل.
 *
 * - ملف اللعبة المرفوع يُنقل إلى uploads/retrovault-roms/ باسم فيه لاحقة عشوائية. في المجلد ملف
 *   .htaccess يمنع فتح أي ملف مباشرة (Apache وLiteSpeed)، والاسم العشوائي لا يظهر لأحد فلا يُخمَّن
 *   (nginx يحتاج قاعدة منع صريحة). ويصبح المرفق «خاصاً» فلا يظهر في واجهة REST ولا في صفحة المرفق.
 * - المشغّل يطلب الملف من /games/{slug}/rom/{رمز}/{اسم الملف}: رمز موقّع مرتبط بجلسة المتصفح يتغير كل 6 ساعات، ويُرفض
 *   الطلب إن فُتح الرابط في المتصفح مباشرة أو جاء من موقع آخر (ترويسات Sec-Fetch).
 * - زر «تنزيل الملف» (للألعاب التي يسمح المدير بتنزيلها) يرسل الملف نفسه دون كشف مكانه.
 *
 * لا يمكن منع التنزيل كلياً: اللعبة تعمل داخل المتصفح فلا بد أن يصله الملف، ومن يعرف أدوات المطوّرين
 * يستطيع التقاطه. الهدف إغلاق الطرق السهلة: الرابط المباشر، وفتحه في المتصفح، ومشاركته.
 * الملفات المضافة بحقل «رابط مباشر» (على خادم آخر) لا تُحمى.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Roms {

	/** المجلد المحمي داخل uploads. */
	const DIR = 'retrovault-roms';

	/** الاسم الأصلي للملف (يظهر في رابط المشغّل وفي التنزيل بدل الاسم العشوائي). */
	const NAME = '_rv_rom_name';

	/** مدة «نافذة» الرمز بالثواني: الرمز صالح في نافذته والتي تليها (6 إلى 12 ساعة). */
	const WINDOW = 21600;

	/** A separate HttpOnly cookie per site, used only to bind player links to this browser. */
	public static function cookie_name() {
		return 'rv_player_' . get_current_blog_id();
	}

	private static function session() {
		$value = isset( $_COOKIE[ self::cookie_name() ] ) ? wp_unslash( $_COOKIE[ self::cookie_name() ] ) : '';
		return is_string( $value ) && preg_match( '/^[A-Za-z0-9]{64}$/D', $value ) ? $value : '';
	}

	/** Issue the player cookie before rendering its signed URL; file requests never issue one. */
	public static function prepare_session() {
		if ( '' !== self::session() ) {
			return true;
		}
		if ( headers_sent() ) {
			return false;
		}
		$value = wp_generate_password( 64, false, false );
		$path  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$ok    = setcookie(
			self::cookie_name(),
			$value,
			array(
				'expires'  => 0,
				'path'     => $path ? $path : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		if ( $ok ) {
			$_COOKIE[ self::cookie_name() ] = $value;
		}
		return $ok;
	}

	public static function init() {
		add_filter( 'add_post_metadata', array( __CLASS__, 'guard_meta' ), 10, 4 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'guard_meta' ), 10, 4 );
		// أي طريقة تربط ملفاً بلعبة (المحرر، REST، الاستيراد) تحميه فوراً.
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 4 );
		// وحفظ اللعبة يعيد المحاولة إن فشل النقل سابقاً.
		add_action( 'save_post_' . Post_Types::GAME, array( __CLASS__, 'on_save' ), 30 );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
	}

	/** Protect every metadata write path, including the classic custom-fields AJAX endpoint. */
	public static function guard_meta( $check, $post_id, $key, $value ) {
		if ( null !== $check || Game_Meta::PREFIX . 'rom_id' !== $key || Post_Types::GAME !== get_post_type( $post_id ) ) {
			return $check;
		}
		return is_wp_error( self::validate_attachment( absint( $value ), get_current_user_id() > 0 ) ) ? false : $check;
	}

	/**
	 * @param int    $meta_id  رقم الحقل.
	 * @param int    $post_id  رقم المنشور.
	 * @param string $meta_key اسم الحقل.
	 * @param mixed  $value    القيمة.
	 */
	public static function on_meta( $meta_id, $post_id, $meta_key, $value ) {
		if ( Game_Meta::PREFIX . 'rom_id' === $meta_key && (int) $value > 0 && Post_Types::GAME === get_post_type( $post_id ) ) {
			self::protect( (int) $value );
		}
	}

	/**
	 * @param int $post_id رقم اللعبة.
	 */
	public static function on_save( $post_id ) {
		$rom_id = (int) get_post_meta( $post_id, Game_Meta::PREFIX . 'rom_id', true );
		if ( $rom_id > 0 ) {
			self::protect( $rom_id );
		}
	}

	/** يحمي ملفات كل الألعاب الحالية (عند التفعيل والترقية). */
	public static function protect_all() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s", Game_Meta::PREFIX . 'rom_id', Post_Types::GAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			if ( $id > 0 ) {
				self::protect( $id );
			}
		}
	}

	/**
	 * ينقل ملف المرفق إلى المجلد المحمي باسم عشوائي ويجعله خاصاً. آمن للتكرار.
	 *
	 * @param int $attachment_id رقم المرفق.
	 * @return bool هل الملف محمي الآن.
	 */
	public static function protect( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id || is_wp_error( self::validate_attachment( $attachment_id ) ) ) {
			return false;
		}
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false; // الملف ليس على هذا الخادم (مكتبة وسائط على CDN مثلاً).
		}
		$dir = self::dir();
		if ( ! $dir ) {
			return false;
		}
		if ( 'private' !== get_post_status( $attachment_id ) ) {
			$result = wp_update_post( array( 'ID' => $attachment_id, 'post_status' => 'private' ), true );
			if ( is_wp_error( $result ) || ! $result ) {
				return false;
			}
		}
		if ( ! self::is_protected_path( $path ) ) {
			$name = wp_basename( $path );
			$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
			$base = sanitize_file_name( (string) pathinfo( $name, PATHINFO_FILENAME ) );
			$new  = $dir . '/' . ( '' !== $base ? $base : 'game' ) . '-' . strtolower( wp_generate_password( 16, false ) ) . ( '' !== $ext ? '.' . $ext : '' );
			if ( ! @rename( $path, $new ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				return false;
			}
			if ( ! update_attached_file( $attachment_id, $new ) ) {
				@rename( $new, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				return false;
			}
			if ( '' === (string) get_post_meta( $attachment_id, self::NAME, true ) ) {
				update_post_meta( $attachment_id, self::NAME, $name );
			}
		}
		return true;
	}

	/**
	 * Validate both the file type and (for user input) permission over this attachment.
	 * Background upgrades deliberately use only the structural validation.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_attachment( $attachment_id, $check_permission = false ) {
		$attachment_id = (int) $attachment_id;
		if ( 0 === $attachment_id ) {
			return true; // Removing the selection is allowed.
		}
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error( 'rv_invalid_rom', __( 'اختر ملف لعبة صالحاً من مكتبة الوسائط.', 'retrovault-core' ), array( 'status' => 400 ) );
		}
		if ( $check_permission && ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'rv_rom_forbidden', __( 'لا تملك صلاحية استخدام هذا المرفق كملف لعبة.', 'retrovault-core' ), array( 'status' => 403 ) );
		}
		$name = (string) get_attached_file( $attachment_id );
		if ( '' === $name ) {
			$name = (string) wp_parse_url( (string) wp_get_attachment_url( $attachment_id ), PHP_URL_PATH );
		}
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, Uploads::extensions(), true ) ) {
			return new \WP_Error( 'rv_invalid_rom', __( 'هذا المرفق ليس ملف لعبة مدعوماً. الصور والمستندات لا تُنقل إلى مجلد الألعاب.', 'retrovault-core' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * المجلد المحمي، يُنشأ مع ملفَي المنع عند الحاجة.
	 *
	 * @return string|null المسار، أو null إن تعذّر إنشاؤه.
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$files = array(
			'.htaccess' => "# RetroVault: ملفات الألعاب تُرسَل عبر المشغّل فقط، ولا تُفتح مباشرة.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $file => $content ) {
			$target = "{$dir}/{$file}";
			if ( ! is_file( $target ) || $content !== @file_get_contents( $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( strlen( $content ) !== @file_put_contents( $target, $content, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					return null;
				}
			}
		}
		return $dir;
	}

	/**
	 * @param string $path مسار الملف.
	 */
	public static function is_protected_path( $path ) {
		if ( ! $path ) {
			return false;
		}
		$uploads = wp_upload_dir( null, false );
		$real = realpath( $path );
		$dir  = realpath( trailingslashit( $uploads['basedir'] ) . self::DIR );
		return $real && $dir && 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $dir ) ) );
	}

	/**
	 * الاسم الظاهر لملف اللعبة: الأصلي، بلا اللاحقة العشوائية.
	 *
	 * @param int    $attachment_id رقم المرفق.
	 * @param string $path          مساره (اختياري).
	 */
	public static function name( $attachment_id, $path = '' ) {
		$name = (string) get_post_meta( $attachment_id, self::NAME, true );
		return '' !== $name ? $name : wp_basename( $path ? $path : (string) get_attached_file( $attachment_id ) );
	}

	/**
	 * رمز رابط المشغّل: توقيع برقم اللعبة والنافذة الزمنية وسرّ الموقع.
	 *
	 * @param int      $game_id رقم اللعبة.
	 * @param int|null $window  النافذة (الحالية افتراضياً).
	 */
	public static function token( $game_id, $window = null ) {
		$session = self::session();
		if ( '' === $session ) {
			return '';
		}
		return self::sign( $game_id, $window, $session . '|' . get_current_user_id() . '|' . wp_get_session_token() );
	}

	/**
	 * @param int      $game_id  رقم اللعبة.
	 * @param int|null $window   النافذة (الحالية افتراضياً).
	 * @param string   $identity جلسة المتصفح|رقم العضو|رمز جلسة الدخول.
	 */
	private static function sign( $game_id, $window, $identity ) {
		if ( null === $window ) {
			$window = (int) floor( time() / self::WINDOW );
		}
		return substr( hash_hmac( 'sha256', 'rv-rom|' . (int) $game_id . '|' . (int) $window . '|' . $identity, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * @param int    $game_id رقم اللعبة.
	 * @param string $token   الرمز من الرابط.
	 */
	public static function valid_token( $game_id, $token ) {
		if ( '' === self::session() || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			return false;
		}
		$now = (int) floor( time() / self::WINDOW );
		foreach ( array( $now, $now - 1 ) as $window ) {
			if ( hash_equals( self::token( $game_id, $window ), (string) $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * رابط ملف اللعبة للمشغّل: رابط مؤقت للملف المحمي، أو الرابط العادي لغيره.
	 * اسم الملف آخر الرابط لأن EmulatorJS يعرف نوعه من امتداده، ويحفظ نسخته في المتصفح باسمه مع ?v=.
	 *
	 * @param array       $game  بيانات اللعبة.
	 * @param string|null $token رمز جاهز (فحص الموقع)، وإلا فرمز هذا المتصفح.
	 */
	public static function player_url( $game, $token = null ) {
		$rom = $game['rom'];
		if ( empty( $rom['protected'] ) ) {
			return $rom['url'];
		}
		global $wp_rewrite;
		$post  = get_post( $game['id'] );
		$token = null === $token ? self::token( $game['id'] ) : $token;
		$name  = rawurlencode( $rom['file'] );
		if ( $wp_rewrite && $wp_rewrite->using_permalinks() && 'publish' === get_post_status( $post ) ) {
			return trailingslashit( get_permalink( $post ) ) . 'rom/' . $token . '/' . $name . '?v=' . $rom['ver'];
		}
		return add_query_arg(
			array(
				'v'      => $rom['ver'],
				'rv_rom' => $token . '/' . $name,
			),
			get_permalink( $post )
		);
	}

	/**
	 * /games/{slug}/rom/{رمز}/{اسم}: يرسل ملف اللعبة للمشغّل فقط.
	 *
	 * @param \WP_Post $post اللعبة.
	 */
	public static function serve( $post ) {
		// رد خاص بهذا المتصفح: لا تحفظه إضافات التخزين المؤقت ولو سمحت بغيره.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$game = Games::get( $post );
		$path = ( $game && ! empty( $game['rom']['protected'] ) ) ? get_attached_file( $game['rom']['id'] ) : '';
		if ( ! $path || ! is_readable( $path ) || ( 'publish' !== get_post_status( $post ) && ! current_user_can( 'edit_post', $post->ID ) ) ) {
			self::deny( 404, 'missing' );
		}
		$requested = get_query_var( 'rv_rom' );
		$token     = is_string( $requested ) ? (string) strtok( $requested, '/' ) : '';
		// السبب في ترويسة X-RetroVault-Rom: المشغّل يشرحه للاعب ولمدير الموقع بدل «تحقق من اتصالك».
		if ( post_password_required( $post ) ) {
			self::deny( 403, 'password' );
		}
		if ( ! self::from_player() ) {
			self::deny( 403, 'origin' );
		}
		if ( '' === self::session() ) {
			self::deny( 403, 'session' );
		}
		if ( ! self::valid_token( $post->ID, $token ) ) {
			self::deny( 403, 'token' );
		}
		self::send(
			$path,
			array(
				'Cache-Control: private, no-store',
				'Cross-Origin-Resource-Policy: same-origin',
				'X-RetroVault-Rom: ok',
			)
		);
	}

	/**
	 * زر «تنزيل الملف»: يرسل الملف المحمي كتنزيل دون كشف مكانه، وينهي الطلب.
	 *
	 * @param array $game بيانات اللعبة (تنزيلها مسموح).
	 * @return bool false إن لم يكن الملف محمياً (فيُحوَّل لرابطه كالمعتاد).
	 */
	public static function download( $game ) {
		if ( empty( $game['rom']['id'] ) ) {
			return false; // An explicitly configured external URL.
		}
		$path = empty( $game['rom']['protected'] ) ? '' : get_attached_file( $game['rom']['id'] );
		if ( ! $path || ! is_readable( $path ) ) {
			self::deny( 404, 'missing' ); // Never redirect to the physical attachment URL after a failure.
		}
		$name  = $game['rom']['file'];
		$ascii = trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name ), '-' );
		self::send(
			$path,
			array(
				'Content-Disposition: attachment; filename="' . ( '' !== $ascii ? $ascii : 'game' ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ),
				'Cache-Control: private, no-store',
			)
		);
		return true;
	}

	/**
	 * هل الطلب من المشغّل؟ المتصفحات الحديثة ترسل ترويسات Sec-Fetch: طلب EmulatorJS يكون
	 * mode=cors من نفس الموقع، أما فتح الرابط في المتصفح أو «حفظ باسم» فـ navigate، وطلب صفحة موقع
	 * آخر فـ cross-site. عند غيابها يلزم Origin أو Referer مطابق، مع رمز جلسة المتصفح.
	 */
	private static function from_player() {
		$get  = static function ( $name ) {
			return isset( $_SERVER[ $name ] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) ) ) : '';
		};
		$mode = $get( 'HTTP_SEC_FETCH_MODE' );
		$dest = $get( 'HTTP_SEC_FETCH_DEST' );
		$site = $get( 'HTTP_SEC_FETCH_SITE' );
		if ( 'navigate' === $mode || in_array( $dest, array( 'document', 'iframe', 'frame', 'embed', 'object' ), true ) ) {
			return false;
		}
		if ( '' !== $site ) {
			return 'same-origin' === $site && in_array( $mode, array( 'cors', 'same-origin' ), true ) && in_array( $dest, array( '', 'empty' ), true );
		}
		$source = isset( $_SERVER['HTTP_ORIGIN'] ) ? wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : ( isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '' );
		$origin = wp_parse_url( $source );
		$home   = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $origin ) || ! isset( $origin['scheme'], $origin['host'] ) || isset( $origin['user'] ) || isset( $origin['pass'] ) ) {
			return false;
		}
		$port = static function ( $url ) { return isset( $url['port'] ) ? (int) $url['port'] : ( 'https' === strtolower( $url['scheme'] ) ? 443 : 80 ); };
		return strtolower( $origin['scheme'] ) === strtolower( $home['scheme'] ) && strtolower( $origin['host'] ) === strtolower( $home['host'] ) && $port( $origin ) === $port( $home );
	}

	/**
	 * يرسل الملف، أو ترويساته فقط لطلب HEAD (يستخدمه EmulatorJS لمعرفة الحجم قبل التنزيل).
	 *
	 * @param string   $path    المسار.
	 * @param string[] $headers ترويسات إضافية.
	 */
	private static function send( $path, $headers ) {
		status_header( 200 );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . (int) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		foreach ( $headers as $header ) {
			header( $header );
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			exit;
		}
		// الضغط يفسد Content-Length، والملف يُرسل كما هو.
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * فحص الموقع: طلب ملف لعبة كما يطلبه المشغّل، من الموقع إلى نفسه. يكشف ما يمنع كل الألعاب عن كل
	 * الزوار ولا يظهر في بيئة التطوير: قاعدة nginx للملفات الثابتة، أو جدار حماية، أو CDN يحذف ملف تعريف
	 * الارتباط أو الترويسات، أو عنوان موقع لا يطابق العنوان الفعلي.
	 *
	 * @param array $tests الفحوص.
	 */
	public static function site_health( $tests ) {
		$tests['async']['retrovault_roms'] = array(
			'label'             => __( 'ملفات الألعاب تصل إلى المشغّل', 'retrovault-core' ),
			'test'              => rest_url( Rest::NS . '/site-health/roms' ),
			'has_rest'          => true,
			'async_direct_test' => array( __CLASS__, 'site_health_roms' ),
		);
		return $tests;
	}

	public static function rest() {
		register_rest_route(
			Rest::NS,
			'/site-health/roms',
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					return rest_ensure_response( self::site_health_roms() );
				},
				'permission_callback' => static function () {
					return current_user_can( 'view_site_health_checks' );
				},
			)
		);
	}

	/** أول لعبة منشورة (بلا كلمة مرور) ملفها في المجلد المحمي. */
	private static function health_game() {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::GAME,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Game_Meta::PREFIX . 'rom_id',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$game = Games::get( $id );
			if ( $game && $game['playable'] && ! empty( $game['rom']['protected'] ) ) {
				return $game;
			}
		}
		return null;
	}

	/** @return array نتيجة الفحص. */
	public static function site_health_roms() {
		$result = array(
			'label'       => __( 'ملفات الألعاب تصل إلى المشغّل', 'retrovault-core' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'الألعاب', 'retrovault-core' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'طلبنا ملف لعبة كما يطلبه المشغّل، فوصل الطلب إلى الإضافة وعاد بالملف.', 'retrovault-core' ) . '</p>',
			'actions'     => '',
			'test'        => 'retrovault_roms',
		);
		$game = self::health_game();
		if ( ! $game ) {
			$result['description'] = '<p>' . esc_html__( 'لا توجد بعد لعبة منشورة بملف مرفوع إلى الموقع لفحصها.', 'retrovault-core' ) . '</p>';
			return $result;
		}

		$session  = wp_generate_password( 64, false, false );
		$url      = self::player_url( $game, self::sign( $game['id'], null, $session . '|0|' ) );
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				/** This filter is documented in wp-includes/class-wp-site-health.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'     => array( self::cookie_name() => $session ),
				'headers'     => array(
					'Sec-Fetch-Site' => 'same-origin',
					'Sec-Fetch-Mode' => 'cors',
					'Sec-Fetch-Dest' => 'empty',
					'Referer'        => $game['player_url'],
					'Cache-Control'  => 'no-cache',
				),
			)
		);
		/* translators: %s: game title */
		$which = '<p>' . esc_html( sprintf( __( 'اللعبة المفحوصة: %s', 'retrovault-core' ), $game['title'] ) ) . '</p><p><code dir="ltr">' . esc_html( remove_query_arg( 'v', $url ) ) . '</code></p>';

		if ( is_wp_error( $response ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'تعذّر فحص وصول ملفات الألعاب', 'retrovault-core' );
			/* translators: %s: error message */
			$result['description'] = '<p>' . esc_html( sprintf( __( 'الموقع لم يستطع الاتصال بنفسه لطلب ملف لعبة: %s', 'retrovault-core' ), $response->get_error_message() ) ) . '</p>' . $which;
			return $result;
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$reason = strtolower( (string) wp_remote_retrieve_header( $response, 'x-retrovault-rom' ) );
		if ( 200 === $code && 'ok' === $reason ) {
			return $result;
		}

		$result['status'] = 'critical';
		$result['label']  = __( 'ملفات الألعاب لا تصل إلى المشغّل', 'retrovault-core' );
		if ( $code >= 300 && $code < 400 ) {
			$result['label']       = __( 'روابط ملفات الألعاب تُحوَّل إلى عنوان آخر', 'retrovault-core' );
			/* translators: %s: redirect target */
			$result['description'] = '<p>' . esc_html( sprintf( __( 'الخادم يحوّل طلب ملف اللعبة إلى %s، والمتصفح يرفض ذلك إن تغيّر النطاق أو البروتوكول. عنوان الموقع في الإعدادات ← عام لا يطابق العنوان الفعلي (https أو www).', 'retrovault-core' ), (string) wp_remote_retrieve_header( $response, 'location' ) ) ) . '</p>';
			$result['actions']     = '<p><a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'الإعدادات ← عام', 'retrovault-core' ) . '</a></p>';
		} elseif ( 'session' === $reason ) {
			/* translators: %s: cookie name */
			$result['description'] = '<p>' . esc_html( sprintf( __( 'طلب الملف وصل إلى ووردبريس بلا ملف تعريف الارتباط %s، فالخادم أو CDN (Varnish مثلاً) يحذفه. الألعاب لن تعمل لأي زائر حتى يُسمح به.', 'retrovault-core' ), self::cookie_name() ) ) . '</p>';
		} elseif ( 'origin' === $reason ) {
			$result['description'] = '<p>' . esc_html__( 'طلب الملف وصل إلى ووردبريس بلا ترويسات Sec-Fetch ولا Referer، فالخادم أو CDN يحذفها. الألعاب لن تعمل لأي زائر حتى يُسمح بها.', 'retrovault-core' ) . '</p>';
		} elseif ( '' !== $reason ) {
			/* translators: 1: HTTP status code, 2: reason */
			$result['description'] = '<p>' . esc_html( sprintf( __( 'الإضافة رفضت الطلب (%1$d، %2$s).', 'retrovault-core' ), $code, $reason ) ) . '</p>';
		} elseif ( $code >= 500 ) {
			/* translators: %d: HTTP status code */
			$result['description'] = '<p>' . esc_html( sprintf( __( 'الخادم ردّ بالخطأ %d. راجع سجل أخطاء PHP في الاستضافة.', 'retrovault-core' ), $code ) ) . '</p>';
		} else {
			/* translators: %d: HTTP status code */
			$result['description'] = '<p>' . esc_html( sprintf( __( 'الخادم ردّ بالخطأ %d قبل أن يصل الطلب إلى ووردبريس، فلن تعمل الألعاب المرفوعة لأي زائر. السبب غالباً قاعدة nginx للملفات الثابتة (الروابط المنتهية بـ ‎.zip أو ‎.nes مثلاً) لا تُحيل إلى index.php، أو جدار حماية.', 'retrovault-core' ), $code ) ) . '</p>';
			$result['actions']     = '<p>' . esc_html__( 'في إعدادات nginx، اجعل قاعدة الملفات الثابتة تُحيل الطلب إلى ووردبريس إن لم يكن الملف موجوداً:', 'retrovault-core' ) . '</p><p><code dir="ltr">try_files $uri $uri/ /index.php?$args;</code></p>';
		}
		$result['description'] .= $which;
		return $result;
	}

	/**
	 * يفحص «رابطاً مباشراً» لملف لعبة على موقع آخر: المتصفح لا ينزّله إلا إن سمح ذلك الموقع بذلك
	 * (Access-Control-Allow-Origin)، ورابط http لا يعمل في موقع https، ورابط المشاركة صفحة لا ملف.
	 *
	 * @param string $url الرابط.
	 * @return array|null array( نوع التنبيه, الرسالة ) أو null إن بدا سليماً أو تعذّر فحصه.
	 */
	public static function check_link( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$link = wp_parse_url( (string) $url );
		if ( ! is_array( $link ) || empty( $link['host'] ) || empty( $link['scheme'] ) ) {
			return null;
		}
		if ( 'https' === strtolower( $home['scheme'] ) && 'http' === strtolower( $link['scheme'] ) ) {
			return array( 'error', __( 'الرابط المباشر لملف اللعبة يبدأ بـ http:// والموقع يعمل بـ https://، فيمنعه المتصفح ولن تعمل اللعبة. استخدم رابط https أو ارفع الملف إلى مكتبة الوسائط.', 'retrovault-core' ) );
		}
		$port   = static function ( $u ) {
			return isset( $u['port'] ) ? (int) $u['port'] : ( 'https' === strtolower( $u['scheme'] ) ? 443 : 80 );
		};
		$origin = strtolower( $home['scheme'] . '://' . $home['host'] ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( strtolower( $link['host'] ) === strtolower( $home['host'] ) && strtolower( $link['scheme'] ) === strtolower( $home['scheme'] ) && $port( $link ) === $port( $home ) ) {
			return null; // نفس الموقع: لا يحتاج إذناً.
		}
		$key = 'rv_link_' . md5( $url . '|' . $origin );
		if ( get_transient( $key ) ) {
			return null;
		}
		$args     = array(
			'timeout'     => 6,
			'redirection' => 5,
			'headers'     => array( 'Origin' => $origin ),
		);
		$response = wp_safe_remote_head( $url, $args );
		if ( ! is_wp_error( $response ) && in_array( (int) wp_remote_retrieve_response_code( $response ), array( 403, 405, 501 ), true ) ) {
			// خوادم لا تقبل HEAD: أول بايت فقط.
			$args['headers']['Range']    = 'bytes=0-0';
			$args['limit_response_size'] = 1024;
			$response                    = wp_safe_remote_get( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			return null; // لم نتمكن من الفحص (جدار حماية الخادم مثلاً): لا نحكم على الرابط.
		}
		$code  = (int) wp_remote_retrieve_response_code( $response );
		$type  = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$allow = trim( (string) wp_remote_retrieve_header( $response, 'access-control-allow-origin' ) );
		if ( $code >= 400 ) {
			/* translators: %d: HTTP status code */
			return array( 'error', sprintf( __( 'الرابط المباشر لملف اللعبة يعيد الخطأ %d، فلن تعمل اللعبة. تحقق منه أو ارفع الملف إلى مكتبة الوسائط.', 'retrovault-core' ), $code ) );
		}
		if ( 0 === strpos( $type, 'text/html' ) ) {
			return array( 'error', __( 'الرابط المباشر يفتح صفحة ويب وليس ملف اللعبة نفسه (رابط مشاركة من Google Drive أو غيره مثلاً). استخدم رابط التنزيل المباشر أو ارفع الملف إلى مكتبة الوسائط.', 'retrovault-core' ) );
		}
		if ( '*' !== $allow && strtolower( rtrim( $allow, '/' ) ) !== $origin ) {
			/* translators: %s: host name */
			return array( 'error', sprintf( __( 'الموقع الذي عليه ملف اللعبة (%s) لا يسمح بتنزيله من موقعك (ترويسة Access-Control-Allow-Origin)، فلن تعمل اللعبة. ارفع الملف إلى مكتبة الوسائط بدلاً من الرابط.', 'retrovault-core' ), $link['host'] ) );
		}
		set_transient( $key, 1, 12 * HOUR_IN_SECONDS );
		return null;
	}

	/** رابط ملف للعبة غير موجودة أو غير منشورة. */
	public static function not_found() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		self::deny( 404, 'missing' );
	}

	/**
	 * @param int    $status 403 أو 404.
	 * @param string $reason missing | password | origin | session | token.
	 */
	private static function deny( $status, $reason ) {
		nocache_headers();
		header( 'X-RetroVault-Rom: ' . $reason );
		wp_die(
			esc_html( 403 === $status ? __( 'ملفات الألعاب تعمل داخل المشغّل فقط، ولا يمكن فتحها أو تنزيلها مباشرة.', 'retrovault-core' ) : __( 'الملف غير موجود.', 'retrovault-core' ) ),
			esc_html( 403 === $status ? __( 'غير مسموح', 'retrovault-core' ) : __( 'غير موجود', 'retrovault-core' ) ),
			array( 'response' => (int) $status )
		);
	}
}
