<?php
/**
 * حدود المحاولات: تخمين كلمات المرور وتكرار الطلبات.
 *
 * - الدخول: بعد 5 محاولات خاطئة لاسم واحد من اتصال واحد خلال 15 دقيقة يُرفض الدخول لهذا الاسم من هذا
 *   الاتصال 15 دقيقة، حتى بكلمة المرور الصحيحة. ووردبريس لا يحد التخمين، ويصل إليه أيضاً عبر
 *   xmlrpc.php وكلمات مرور التطبيقات. لا يُقفل الحساب لغير هذا الاتصال.
 *   إضافات الأمان التي تفعل ذلك لا تتعارض معه، ويُطفأ بالمرشّح retrovault_login_limit (0).
 * - صفحات الحساب لا تُعرض داخل إطار من موقع آخر (الخداع بالنقر).
 * - أسماء الدخول لا تُنشر: هي نصف ما يحتاجه من يخمّن كلمات المرور، وووردبريس يعرضها للزوار في واجهة
 *   REST للأعضاء، وفي روابط صفحات الكُتّاب (/?author=1 ← /author/admin/)، وفي بيانات التضمين (oEmbed)،
 *   وفي كلاسات التعليقات. صفحات الكُتّاب تُحوَّل إلى اليوميات. يُطفأ بالمرشّح retrovault_hide_usernames.
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
		add_filter( 'rest_endpoints', array( __CLASS__, 'rest_users' ) );
		add_action( 'template_redirect', array( __CLASS__, 'author_archive' ), 1 );
		add_filter( 'oembed_response_data', array( __CLASS__, 'oembed_author' ) );
		add_filter( 'comment_class', array( __CLASS__, 'comment_class' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health' ) );
	}

	/** هل تُخفى أسماء الدخول عن الزوار؟ */
	public static function hides_usernames() {
		return (bool) apply_filters( 'retrovault_hide_usernames', true );
	}

	/**
	 * قائمة الأعضاء في واجهة REST (/wp/v2/users) تعرض اسم دخول كل من نشر شيئاً. تبقى لمن سجّل الدخول
	 * (محرر المقالات يستعملها)، وتُحذف للزوار.
	 *
	 * @param array $endpoints مسارات REST.
	 */
	public static function rest_users( $endpoints ) {
		if ( is_user_logged_in() || ! self::hides_usernames() ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/**
	 * صفحة الكاتب (/author/admin/) ورابطها المختصر (?author=1) يكشفان اسم الدخول، ومقالاته هي اليوميات
	 * نفسها. قبل تحويل ووردبريس إلى /author/admin/ (الأولوية 10) تُحوَّل إلى اليوميات، وبالرد نفسه لكل رقم،
	 * فلا يُعرف من الرد أي الأرقام لأعضاء موجودين.
	 */
	public static function author_archive() {
		// رقم عضو غير موجود تجعله ووردبريس 404؛ يُحوَّل هو أيضاً كي لا يفرّق الرد بين الموجود وغيره.
		$author = is_author() || ( is_404() && ( get_query_var( 'author' ) || get_query_var( 'author_name' ) ) );
		if ( ! $author || ! self::hides_usernames() ) {
			return;
		}
		$url = Devlog::url();
		wp_safe_redirect( $url ? $url : home_url( '/' ), 301 );
		exit;
	}

	/**
	 * @param array $data بيانات التضمين.
	 */
	public static function oembed_author( $data ) {
		if ( isset( $data['author_url'] ) && self::hides_usernames() ) {
			$data['author_url'] = home_url( '/' );
		}
		return $data;
	}

	/**
	 * ووردبريس يضيف comment-author-{اسم الدخول} لكل تعليق من عضو.
	 *
	 * @param string[] $classes كلاسات التعليق.
	 */
	public static function comment_class( $classes ) {
		if ( ! self::hides_usernames() ) {
			return $classes;
		}
		return array_values(
			array_filter(
				$classes,
				static function ( $class ) {
					return 0 !== strpos( $class, 'comment-author-' );
				}
			)
		);
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

	/**
	 * فحص في «أدوات ← صحة الموقع»: اسم العرض الافتراضي في ووردبريس هو اسم الدخول نفسه، ويظهر للزوار
	 * كاتباً للتدوينات وفي التعليقات وبيانات المشاركة، مهما أُخفيت أسماء الدخول في غيرها.
	 *
	 * @param array $tests الفحوص.
	 */
	public static function site_health( $tests ) {
		$tests['direct']['retrovault_public_logins'] = array(
			'label' => __( 'أسماء الدخول الظاهرة للزوار', 'retrovault-core' ),
			'test'  => array( __CLASS__, 'site_health_logins' ),
		);
		return $tests;
	}

	/** @return array نتيجة الفحص. */
	public static function site_health_logins() {
		$exposed = array();
		foreach ( get_users( array( 'capability' => 'edit_posts', 'fields' => array( 'ID', 'user_login', 'display_name' ) ) ) as $user ) {
			if ( 0 === strcasecmp( trim( $user->user_login ), trim( $user->display_name ) ) ) {
				$exposed[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( get_edit_user_link( (int) $user->ID ) ), esc_html( $user->user_login ) );
			}
		}
		$result = array(
			'label'       => __( 'أسماء العرض لا تكشف أسماء الدخول', 'retrovault-core' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'الأمان', 'retrovault-core' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'من يكتب في الموقع يظهر للزوار باسم العرض، واسم الدخول لا يُنشر في خريطة الموقع ولا في واجهة REST ولا في روابط الكُتّاب.', 'retrovault-core' ) . '</p>',
			'test'        => 'retrovault_public_logins',
		);
		if ( $exposed ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'اسم الدخول ظاهر للزوار كاسم عرض', 'retrovault-core' );
			$result['description'] = '<p>' . esc_html__( 'اسم العرض لهؤلاء هو اسم الدخول نفسه، فيظهر للزوار كاتباً للتدوينات وفي التعليقات. معرفة اسم الدخول نصف ما يحتاجه من يخمّن كلمة المرور.', 'retrovault-core' ) . '</p><p>' . implode( '، ', $exposed ) . '</p>';
			$result['actions']     = '<p>' . esc_html__( 'افتح صفحة العضو، واكتب له اسماً أول أو اسماً مستعاراً، ثم اختره اسماً يظهر للعموم.', 'retrovault-core' ) . '</p>';
		}
		return $result;
	}

	/** يمنع عرض الصفحة الحالية داخل إطار من موقع آخر. */
	public static function no_framing() {
		if ( ! headers_sent() ) {
			send_frame_options_header();
			header( "Content-Security-Policy: frame-ancestors 'self'" );
		}
	}
}
