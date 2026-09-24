<?php
/**
 * صفحة «حسابي» للأعضاء: الحفظ السحابي، المفضلة، التقييمات.
 *
 * صفحة ووردبريس عادية تحتوي الرمز [retrovault_account] (تُنشأ تلقائياً)،
 * فتستطيع تغيير عنوانها ورابطها وإضافتها للقوائم كأي صفحة.
 * القالب يرسم محتواها عبر الخطاف retrovault_account، وإلا يظهر عرض بسيط افتراضي.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Account {

	const OPTION    = 'retrovault_account_page';
	const SHORTCODE = 'retrovault_account';

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'guard' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	public static function page_id() {
		$id = (int) get_option( self::OPTION );
		return ( $id && 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) ? $id : 0;
	}

	public static function url() {
		$id = self::page_id();
		return $id ? (string) get_permalink( $id ) : '';
	}

	public static function is_page() {
		$id = self::page_id();
		return $id && is_page( $id );
	}

	/** إنشاء الصفحة إن لم تكن موجودة (عند التفعيل أو الترقية). */
	public static function ensure_page() {
		if ( self::page_id() ) {
			return;
		}
		$existing = get_page_by_path( 'account' );
		if ( $existing && 'publish' === $existing->post_status && has_shortcode( $existing->post_content, self::SHORTCODE ) ) {
			update_option( self::OPTION, (int) $existing->ID );
			return;
		}
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'حسابي', 'retrovault-core' ),
				'post_name'      => 'account',
				'post_content'   => '[' . self::SHORTCODE . ']',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( self::OPTION, (int) $id );
		}
	}

	/** الزائر يُحوَّل لتسجيل الدخول ثم يعود للصفحة. */
	public static function guard() {
		if ( self::is_page() && ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url() ) );
			exit;
		}
	}

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		ob_start();
		if ( has_action( 'retrovault_account' ) ) {
			do_action( 'retrovault_account', get_current_user_id() );
		} else {
			self::fallback( get_current_user_id() );
		}
		return (string) ob_get_clean();
	}

	/**
	 * عرض بسيط يعمل مع أي قالب.
	 *
	 * @param int $user_id رقم العضو.
	 */
	private static function fallback( $user_id ) {
		$saves = Saves::enabled() ? Saves::for_user( $user_id ) : array();
		$favs  = Favorites::get( $user_id );
		echo '<div class="rv-account">';
		if ( $saves ) {
			echo '<h2>' . esc_html__( 'استكمل اللعب', 'retrovault-core' ) . '</h2><ul>';
			foreach ( $saves as $save ) {
				printf( '<li><a href="%1$s#resume">%2$s</a> — %3$s</li>', esc_url( get_permalink( $save['game_id'] ) ), esc_html( get_the_title( $save['game_id'] ) ), esc_html( $save['ago'] ) );
			}
			echo '</ul>';
		}
		echo '<h2>' . esc_html__( 'مفضلتي', 'retrovault-core' ) . '</h2>';
		if ( $favs ) {
			echo '<ul>';
			foreach ( $favs as $id ) {
				printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( get_permalink( $id ) ), esc_html( get_the_title( $id ) ) );
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'لا توجد ألعاب في مفضلتك بعد.', 'retrovault-core' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * @param string[] $states حالات الصفحة.
	 * @param \WP_Post $post   الصفحة.
	 */
	public static function post_state( $states, $post ) {
		if ( (int) $post->ID === self::page_id() ) {
			$states['rv_account'] = __( 'صفحة حساب الأعضاء', 'retrovault-core' );
		}
		return $states;
	}

	/**
	 * @param array $robots توجيهات robots.
	 */
	public static function robots( $robots ) {
		if ( self::is_page() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}
}
