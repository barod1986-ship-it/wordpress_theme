<?php
/**
 * الأعضاء (المشتركون): يرون الموقع فقط، لا لوحة التحكم.
 * - لا يظهر لهم شريط الإدارة.
 * - أي محاولة لفتح wp-admin تعيدهم للموقع (عدا صفحة ملفهم الشخصي).
 * - بعد تسجيل الدخول يعودون للصفحة التي كانوا فيها (مثلاً صفحة اللعبة لتقييمها).
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Members {

	public static function init() {
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	/** العضو «عادي» إن لم يكن يملك صلاحية الكتابة. */
	public static function is_member_only() {
		return is_user_logged_in() && ! current_user_can( 'edit_posts' );
	}

	/**
	 * @param bool $show إظهار الشريط.
	 */
	public static function admin_bar( $show ) {
		return self::is_member_only() ? false : $show;
	}

	public static function keep_out_of_admin() {
		if ( ! self::is_member_only() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'profile.php', 'admin-post.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * @param string            $redirect_to الوجهة.
	 * @param string            $requested   الوجهة المطلوبة.
	 * @param \WP_User|\WP_Error $user        المستخدم.
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof \WP_User || $user->has_cap( 'edit_posts' ) ) {
			return $redirect_to;
		}
		if ( '' === (string) $requested || 0 === strpos( (string) $requested, admin_url() ) ) {
			return home_url( '/' );
		}
		return $requested;
	}
}
