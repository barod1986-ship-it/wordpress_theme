<?php
/**
 * حماية ملفات الألعاب من التنزيل.
 *
 * - ملف اللعبة المرفوع يُنقل إلى uploads/retrovault-roms/ باسم فيه لاحقة عشوائية. في المجلد ملف
 *   .htaccess يمنع فتح أي ملف مباشرة (Apache وLiteSpeed)، والاسم العشوائي لا يظهر لأحد فلا يُخمَّن
 *   (يحمي على nginx أيضاً). ويصبح المرفق «خاصاً» فلا يظهر في واجهة REST ولا في صفحة المرفق.
 * - المشغّل يطلب الملف من /games/{slug}/rom/{رمز}/{اسم الملف}: رمز موقّع يتغير كل 6 ساعات، ويُرفض
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

	public static function init() {
		add_filter( 'add_post_metadata', array( __CLASS__, 'guard_meta' ), 10, 4 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'guard_meta' ), 10, 4 );
		// أي طريقة تربط ملفاً بلعبة (المحرر، REST، الاستيراد) تحميه فوراً.
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 4 );
		// وحفظ اللعبة يعيد المحاولة إن فشل النقل سابقاً.
		add_action( 'save_post_' . Post_Types::GAME, array( __CLASS__, 'on_save' ), 30 );
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
		if ( ! $path || ! file_exists( $path ) ) {
			return false; // الملف ليس على هذا الخادم (مكتبة وسائط على CDN مثلاً).
		}
		if ( ! self::is_protected_path( $path ) ) {
			$dir = self::dir();
			if ( ! $dir ) {
				return false;
			}
			$name = wp_basename( $path );
			$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
			$base = sanitize_file_name( (string) pathinfo( $name, PATHINFO_FILENAME ) );
			$new  = $dir . '/' . ( '' !== $base ? $base : 'game' ) . '-' . strtolower( wp_generate_password( 16, false ) ) . ( '' !== $ext ? '.' . $ext : '' );
			if ( ! @rename( $path, $new ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				return false;
			}
			update_attached_file( $attachment_id, $new );
			if ( '' === (string) get_post_meta( $attachment_id, self::NAME, true ) ) {
				update_post_meta( $attachment_id, self::NAME, $name );
			}
		}
		if ( 'private' !== get_post_status( $attachment_id ) ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_status' => 'private',
				)
			);
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
			if ( ! file_exists( "{$dir}/{$file}" ) ) {
				file_put_contents( "{$dir}/{$file}", $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
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
		return 0 === strpos( wp_normalize_path( $path ), wp_normalize_path( trailingslashit( $uploads['basedir'] ) . self::DIR . '/' ) );
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
		if ( null === $window ) {
			$window = (int) floor( time() / self::WINDOW );
		}
		return substr( hash_hmac( 'sha256', 'rv-rom|' . (int) $game_id . '|' . (int) $window, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * @param int    $game_id رقم اللعبة.
	 * @param string $token   الرمز من الرابط.
	 */
	public static function valid_token( $game_id, $token ) {
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
	 * @param array $game بيانات اللعبة.
	 */
	public static function player_url( $game ) {
		$rom = $game['rom'];
		if ( empty( $rom['protected'] ) ) {
			return $rom['url'];
		}
		global $wp_rewrite;
		$post  = get_post( $game['id'] );
		$token = self::token( $game['id'] );
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
		$game = Games::get( $post );
		$path = ( $game && ! empty( $game['rom']['protected'] ) ) ? get_attached_file( $game['rom']['id'] ) : '';
		if ( ! $path || ! is_readable( $path ) || ( 'publish' !== get_post_status( $post ) && ! current_user_can( 'edit_post', $post->ID ) ) ) {
			self::deny( 404 );
		}
		$token = (string) strtok( (string) get_query_var( 'rv_rom' ), '/' );
		if ( post_password_required( $post ) || ! self::valid_token( $post->ID, $token ) || ! self::from_player() ) {
			self::deny( 403 );
		}
		self::send(
			$path,
			array(
				'Cache-Control: private, max-age=' . self::WINDOW,
				'Cross-Origin-Resource-Policy: same-origin',
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
		$path = empty( $game['rom']['protected'] ) ? '' : get_attached_file( $game['rom']['id'] );
		if ( ! $path || ! is_readable( $path ) ) {
			return false;
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
	 * آخر فـ cross-site. المتصفحات القديمة لا ترسلها فيُكتفى بالرمز.
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
		return '' === $site || 'same-origin' === $site;
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
	 * @param int $status 403 أو 404.
	 */
	private static function deny( $status ) {
		nocache_headers();
		wp_die(
			esc_html( 403 === $status ? __( 'ملفات الألعاب تعمل داخل المشغّل فقط، ولا يمكن فتحها أو تنزيلها مباشرة.', 'retrovault-core' ) : __( 'الملف غير موجود.', 'retrovault-core' ) ),
			esc_html( 403 === $status ? __( 'غير مسموح', 'retrovault-core' ) : __( 'غير موجود', 'retrovault-core' ) ),
			array( 'response' => (int) $status )
		);
	}
}
