<?php
/**
 * التسجيل الفوري: يختار الزائر كلمة مروره في نموذج التسجيل، فيدخل مباشرة ويعود للصفحة التي كان فيها.
 *
 * بدونه يتبع التسجيل طريقة ووردبريس: رابط لتعيين كلمة المرور يصل بالبريد، ولا يدخل الزائر قبل فتحه،
 * ويتعطل التسجيل كلياً إن كان بريد الموقع لا يصل. يُطفأ من «الألعاب ← الإعدادات»، وعندها لا يتغير شيء
 * في نموذج ووردبريس.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Signup {

	const MIN_LENGTH = 8;

	/** ملف تعريف ارتباط قصير يرحّب به القالب بالعضو الجديد مرة واحدة. */
	const WELCOME_COOKIE = 'rv_welcome';

	/**
	 * كلمة المرور المختارة بعد التحقق منها، كما وصلت في الطلب: wp_signon يقارن كلمة المرور عند الدخول
	 * بالصيغة نفسها (مع الشرطات المائلة التي يضيفها ووردبريس)، فتُحفظ هكذا ليدخل بها العضو لاحقاً.
	 *
	 * @var string|null
	 */
	private static $password = null;

	public static function init() {
		add_action( 'register_form', array( __CLASS__, 'fields' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'validate' ), 10, 3 );
		add_action( 'register_new_user', array( __CLASS__, 'sign_in' ), 5 );
		add_filter( 'registration_redirect', array( __CLASS__, 'registration_redirect' ) );
		add_filter( 'register_url', array( __CLASS__, 'register_url' ) );
		add_filter( 'login_url', array( __CLASS__, 'login_url' ), 10, 2 );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'login_footer', array( __CLASS__, 'scripts' ) );
	}

	public static function enabled() {
		return Settings::get( 'instant_signup' ) && get_option( 'users_can_register' ) && ! is_multisite();
	}

	/**
	 * رابط «حساب جديد» يعيد الزائر بعد التسجيل إلى $redirect، أو الصفحة الحالية إن لم يُحدَّد.
	 *
	 * @param string $redirect وجهة العودة (يُسمح بـ #القسم في آخرها).
	 */
	public static function url( $redirect = '' ) {
		$url = wp_registration_url();
		if ( '' !== $redirect && self::enabled() ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
		}
		return $url;
	}

	/** شاشة التسجيل في wp-login.php، عرضاً أو إرسالاً. */
	private static function on_register_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- تحديد الشاشة فقط.
		return isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] && isset( $_REQUEST['action'] ) && 'register' === $_REQUEST['action'];
	}

	public static function fields() {
		if ( ! self::enabled() ) {
			return;
		}
		?>
		<p class="rv-signup-hint" id="rv-login-hint"><?php esc_html_e( 'اسم المستخدم للدخول فقط: أحرف إنجليزية وأرقام. اسمك الظاهر للآخرين تختاره بعدها من صفحة حسابك.', 'retrovault-core' ); ?></p>
		<div class="user-pass-wrap rv-signup-pass">
			<label for="rv_pass"><?php esc_html_e( 'كلمة المرور', 'retrovault-core' ); ?></label>
			<div class="wp-pwd">
				<input type="password" name="rv_pass" id="rv_pass" class="input password-input" value="" size="20" autocomplete="new-password" spellcheck="false" required="required" aria-describedby="rv-pass-hint">
				<button type="button" class="button button-secondary wp-hide-pw hide-if-no-js" data-rv-toggle-pass aria-controls="rv_pass" aria-pressed="false" aria-label="<?php esc_attr_e( 'إظهار كلمة المرور', 'retrovault-core' ); ?>">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
				</button>
			</div>
			<p class="rv-signup-hint" id="rv-pass-hint">
				<?php
				/* translators: %d: minimum password length */
				echo esc_html( sprintf( __( '%d أحرف على الأقل. تدخل بعدها مباشرة، دون انتظار بريد.', 'retrovault-core' ), self::MIN_LENGTH ) );
				?>
			</p>
		</div>
		<p class="rv-hp">
			<label for="rv_website"><?php esc_html_e( 'اترك هذا الحقل فارغاً', 'retrovault-core' ); ?></label>
			<input type="text" name="rv_website" id="rv_website" value="" tabindex="-1" autocomplete="off">
		</p>
		<?php
	}

	/**
	 * @param \WP_Error $errors أخطاء التسجيل.
	 * @param string    $login  اسم المستخدم.
	 * @param string    $email  البريد.
	 * @return \WP_Error
	 */
	public static function validate( $errors, $login, $email ) {
		if ( ! self::enabled() || ! self::on_register_screen() ) {
			return $errors;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- نموذج التسجيل في ووردبريس بلا nonce، وكلمة المرور لا تُعدَّل.
		if ( ! empty( $_POST['rv_website'] ) ) {
			// حقل مخفي عن الناس لا تملؤه إلا البرامج الآلية.
			$errors->add( 'rv_signup_blocked', self::error( __( 'تعذّر إنشاء الحساب. حاول مرة أخرى.', 'retrovault-core' ) ) );
			return $errors;
		}
		$password = isset( $_POST['rv_pass'] ) && is_string( $_POST['rv_pass'] ) ? $_POST['rv_pass'] : '';
		// phpcs:enable
		if ( '' === $password ) {
			$errors->add( 'rv_pass_empty', self::error( __( 'اختر كلمة مرور لحسابك.', 'retrovault-core' ) ) );
		} elseif ( self::too_short( $password ) ) {
			/* translators: %d: minimum password length */
			$errors->add( 'rv_pass_short', self::error( sprintf( __( 'كلمة المرور قصيرة: %d أحرف على الأقل.', 'retrovault-core' ), self::MIN_LENGTH ) ) );
		} else {
			self::$password = $password;
		}
		return $errors;
	}

	/**
	 * @param string $password كلمة المرور كما وصلت في الطلب.
	 */
	public static function too_short( $password ) {
		return mb_strlen( wp_unslash( (string) $password ), 'UTF-8' ) < self::MIN_LENGTH;
	}

	/**
	 * @param string $message نص الخطأ.
	 */
	private static function error( $message ) {
		return '<strong>' . esc_html__( 'خطأ:', 'retrovault-core' ) . '</strong> ' . esc_html( $message );
	}

	/**
	 * بعد إنشاء الحساب: كلمة المرور المختارة، ثم تسجيل الدخول. يسبق رسائل ووردبريس (الأولوية 10).
	 *
	 * @param int $user_id العضو الجديد.
	 */
	public static function sign_in( $user_id ) {
		if ( null === self::$password || ! self::enabled() ) {
			return;
		}
		$password       = self::$password;
		self::$password = null;

		wp_set_password( $password, $user_id );
		delete_user_meta( $user_id, 'default_password_nag' );

		// إشعار «عضو جديد» للمدير كالمعتاد. رسالة العضو فيها رابط لتعيين كلمة مرور اختارها للتو، فلا تُرسل.
		remove_action( 'register_new_user', 'wp_send_new_user_notifications' );
		wp_send_new_user_notifications( $user_id, 'admin' );

		$user = get_userdata( $user_id );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user );

		if ( ! headers_sent() ) {
			// بلا نطاق، ليحذفه سكربت القالب من الصفحة نفسها بعد عرض الترحيب.
			setcookie( self::WELCOME_COOKIE, '1', time() + 5 * MINUTE_IN_SECONDS, COOKIEPATH, '', is_ssl(), false );
		}
		// نموذج بلا وجهة عودة يذهب إلى «تحقق من بريدك»، وهي لا تنطبق هنا.
		add_filter( 'wp_redirect', array( __CLASS__, 'after_sign_in' ) );
	}

	/**
	 * @param string $location الوجهة.
	 */
	public static function after_sign_in( $location ) {
		return false !== strpos( (string) $location, 'checkemail=registered' ) ? self::default_target() : $location;
	}

	/**
	 * الحقل المخفي redirect_to في النموذج: الصفحة التي جاء منها الزائر، وإلا صفحة حسابه.
	 *
	 * @param string $redirect الوجهة المطلوبة.
	 */
	public static function registration_redirect( $redirect ) {
		if ( ! self::enabled() ) {
			return $redirect;
		}
		$redirect = self::safe_target( (string) $redirect );
		return '' !== $redirect ? $redirect : self::default_target();
	}

	/**
	 * روابط «حساب جديد» تحمل الصفحة الحالية لتعود إليها بعد التسجيل.
	 *
	 * @param string $url رابط التسجيل.
	 */
	public static function register_url( $url ) {
		if ( ! self::enabled() || false !== strpos( $url, 'redirect_to=' ) ) {
			return $url;
		}
		$target = self::return_target();
		return '' !== $target ? add_query_arg( 'redirect_to', rawurlencode( $target ), $url ) : $url;
	}

	/**
	 * رابط «تسجيل الدخول» في شاشة التسجيل يحمل وجهة العودة نفسها، لمن تذكّر أن لديه حساباً.
	 *
	 * @param string $url      رابط الدخول.
	 * @param string $redirect الوجهة المطلوبة.
	 */
	public static function login_url( $url, $redirect ) {
		if ( '' !== (string) $redirect || false !== strpos( $url, 'redirect_to=' ) || ! self::on_register_screen() || ! self::enabled() ) {
			return $url;
		}
		$target = self::return_target();
		return '' !== $target ? add_query_arg( 'redirect_to', rawurlencode( $target ), $url ) : $url;
	}

	/** إلى أين يعود الزائر: وجهة شاشة الدخول/التسجيل الحالية، أو الصفحة التي يتصفحها. */
	private static function return_target() {
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- يُتحقق منها في safe_target.
			return isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? self::safe_target( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		}
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! did_action( 'wp' ) || is_feed() ) {
			return '';
		}
		global $wp;
		$url = home_url( ( $wp && $wp->request ) ? user_trailingslashit( $wp->request ) : '/' );
		// الاستعلام يحدد الصفحة مع الروابط غير الجميلة، ويحفظ فلاتر المكتبة.
		$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- يُتحقق من الرابط كاملاً في safe_target.
		return self::safe_target( '' !== $query ? $url . '?' . $query : $url );
	}

	/**
	 * وجهة آمنة للعضو الجديد: داخل الموقع، وليست لوحة التحكم أو شاشة الدخول.
	 *
	 * @param string $url الوجهة.
	 */
	private static function safe_target( $url ) {
		$url = wp_validate_redirect( $url, '' );
		if ( '' === $url || 0 === strpos( $url, admin_url() ) || false !== strpos( $url, 'wp-login.php' ) ) {
			return '';
		}
		return $url;
	}

	private static function default_target() {
		$account = Account::url();
		return '' !== $account ? $account : home_url( '/' );
	}

	public static function styles() {
		if ( ! self::enabled() || ! self::on_register_screen() ) {
			return;
		}
		// رسالة «سيصلك تأكيد التسجيل بالبريد» لا تنطبق هنا، وحقل البرامج الآلية مخفي عن الناس.
		wp_add_inline_style( 'login', '#reg_passmail,.rv-hp{display:none}#login form .rv-signup-hint{margin:-8px 0 16px;font-size:13px;line-height:1.6}' );
	}

	public static function scripts() {
		if ( ! self::enabled() || ! self::on_register_screen() ) {
			return;
		}
		// تلميح اسم المستخدم تحت حقله (بدون JavaScript يبقى بعد حقل البريد).
		wp_print_inline_script_tag( self::toggle_script() . "(function(){var h=document.getElementById('rv-login-hint'),l=document.getElementById('user_login');if(h&&l){l.parentNode.insertAdjacentElement('afterend',h);l.setAttribute('aria-describedby','rv-login-hint');}})();" );
	}

	/**
	 * زر «إظهار كلمة المرور» لحقول [data-rv-toggle-pass] في الصفحة، ويعيدها مخفية قبل الإرسال
	 * كي لا يحفظها المتصفح بين النصوص المقترحة.
	 */
	public static function toggle_script() {
		return "(function(){[].forEach.call(document.querySelectorAll('[data-rv-toggle-pass]'),function(b){var i=document.getElementById(b.getAttribute('aria-controls'));if(!i){return;}b.hidden=false;b.addEventListener('click',function(){var s=i.type==='password';i.type=s?'text':'password';b.setAttribute('aria-pressed',s?'true':'false');var d=b.querySelector('.dashicons');if(d){d.className='dashicons '+(s?'dashicons-hidden':'dashicons-visibility');}});if(i.form){i.form.addEventListener('submit',function(){i.type='password';});}});})();";
	}
}
