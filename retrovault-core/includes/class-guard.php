<?php
/**
 * حدود المحاولات: تخمين كلمات المرور وتكرار الطلبات.
 *
 * - الدخول: بعد 5 محاولات خاطئة لاسم واحد من اتصال واحد خلال 15 دقيقة يُرفض الدخول لهذا الاسم من هذا
 *   الاتصال 15 دقيقة، حتى بكلمة المرور الصحيحة. ووردبريس لا يحد التخمين، ويصل إليه أيضاً عبر
 *   xmlrpc.php وكلمات مرور التطبيقات. لا يُقفل الحساب لغير هذا الاتصال.
 *   إضافات الأمان التي تفعل ذلك لا تتعارض معه، ويُطفأ بالمرشّح retrovault_login_limit (0).
 * - صفحات الحساب لا تُعرض داخل إطار من موقع آخر (الخداع بالنقر).
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Guard {

	const WINDOW = 15 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ), 10, 2 );
		add_filter( 'authenticate', array( __CLASS__, 'login_locked' ), 99, 2 );
		add_action( 'wp_login', array( __CLASS__, 'login_succeeded' ), 10, 2 );
	}

	/** عدد المحاولات الخاطئة قبل الإيقاف المؤقت (0 يطفئ الحد). */
	private static function login_limit() {
		return max( 0, (int) apply_filters( 'retrovault_login_limit', 5 ) );
	}

	/**
	 * @param string $scope نوع الحد.
	 * @param string $who   ما يُحد (اسم الدخول، رقم العضو…).
	 */
	private static function key( $scope, $who ) {
		return 'rv_guard_' . md5( $scope . '|' . Stats::client_ip() . '|' . strtolower( trim( (string) $who ) ) );
	}

	/**
	 * هل بلغ هذا الاتصال الحد؟
	 *
	 * @param string $scope نوع الحد.
	 * @param string $who   ما يُحد.
	 * @param int    $limit عدد المحاولات.
	 */
	public static function blocked( $scope, $who, $limit ) {
		return $limit > 0 && (int) get_transient( self::key( $scope, $who ) ) >= $limit;
	}

	/**
	 * محاولة خاطئة أخرى؛ العدّ يبدأ من جديد بعد 15 دقيقة من آخر محاولة.
	 *
	 * @param string $scope نوع الحد.
	 * @param string $who   ما يُحد.
	 */
	public static function fail( $scope, $who ) {
		$key = self::key( $scope, $who );
		set_transient( $key, (int) get_transient( $key ) + 1, self::WINDOW );
	}

	/**
	 * @param string $scope نوع الحد.
	 * @param string $who   ما يُحد.
	 */
	public static function clear( $scope, $who ) {
		delete_transient( self::key( $scope, $who ) );
	}

	/**
	 * @param string    $username الاسم المستخدم في المحاولة.
	 * @param \WP_Error $error    سبب الفشل.
	 */
	public static function login_failed( $username, $error = null ) {
		if ( '' === (string) $username || ( $error instanceof \WP_Error && 'rv_login_locked' === $error->get_error_code() ) ) {
			return;
		}
		if ( self::login_limit() ) {
			self::fail( 'login', $username );
		}
	}

	/**
	 * بعد كل فحوص الدخول: أثناء الإيقاف لا يُقبل حتى الدخول الصحيح.
	 *
	 * @param \WP_User|\WP_Error|null $user     النتيجة حتى الآن.
	 * @param string                  $username الاسم.
	 */
	public static function login_locked( $user, $username ) {
		if ( '' === (string) $username || ! self::blocked( 'login', $username, self::login_limit() ) ) {
			return $user;
		}
		/* translators: %d: minutes */
		return new \WP_Error( 'rv_login_locked', '<strong>' . esc_html__( 'خطأ:', 'retrovault-core' ) . '</strong> ' . esc_html( sprintf( __( 'محاولات دخول خاطئة كثيرة لهذا الحساب من اتصالك. انتظر %d دقيقة ثم حاول مرة أخرى، أو استعد كلمة المرور.', 'retrovault-core' ), self::WINDOW / MINUTE_IN_SECONDS ) ) );
	}

	/**
	 * @param string   $login الاسم.
	 * @param \WP_User $user  العضو.
	 */
	public static function login_succeeded( $login, $user ) {
		self::clear( 'login', $login );
		if ( $user instanceof \WP_User ) {
			self::clear( 'login', $user->user_email );
		}
	}

	/** يمنع عرض الصفحة الحالية داخل إطار من موقع آخر. */
	public static function no_framing() {
		if ( ! headers_sent() ) {
			send_frame_options_header();
			header( "Content-Security-Policy: frame-ancestors 'self'" );
		}
	}
}
