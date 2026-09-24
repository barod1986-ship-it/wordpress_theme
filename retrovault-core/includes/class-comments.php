<?php
/**
 * تعليقات الألعاب: للأعضاء المسجلين فقط.
 * القراءة متاحة للجميع؛ الكتابة تُرفض لغير المسجّل مهما كان مصدر الطلب.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Comments {

	public static function init() {
		add_action( 'pre_comment_on_post', array( __CLASS__, 'require_login' ) );
		add_filter( 'preprocess_comment', array( __CLASS__, 'block_guests' ) );
		add_filter( 'get_default_comment_status', array( __CLASS__, 'default_open' ), 10, 3 );
		add_filter( 'pings_open', array( __CLASS__, 'no_pings' ), 10, 2 );
	}

	/**
	 * أنواع المحتوى التي تعليقاتها للأعضاء فقط: الألعاب ويوميات التطوير.
	 *
	 * @return string[]
	 */
	public static function members_only_types() {
		return (array) apply_filters( 'retrovault_members_only_comment_types', array( Post_Types::GAME, 'post' ) );
	}

	/**
	 * @param int $post_id رقم المقالة.
	 */
	public static function is_game( $post_id ) {
		return in_array( get_post_type( $post_id ), self::members_only_types(), true );
	}

	/**
	 * نموذج التعليقات (wp-comments-post.php).
	 *
	 * @param int $post_id رقم المقالة.
	 */
	public static function require_login( $post_id ) {
		if ( self::is_game( $post_id ) && ! is_user_logged_in() ) {
			wp_die(
				esc_html__( 'التعليق متاح للأعضاء المسجّلين فقط.', 'retrovault-core' ),
				esc_html__( 'سجّل دخولك', 'retrovault-core' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * خط الدفاع الأخير لكل القنوات (REST، XML-RPC، أي إضافة).
	 *
	 * @param array $data بيانات التعليق.
	 */
	public static function block_guests( $data ) {
		$post_id = isset( $data['comment_post_ID'] ) ? (int) $data['comment_post_ID'] : 0;
		$type    = isset( $data['comment_type'] ) ? $data['comment_type'] : 'comment';
		if ( $post_id && self::is_game( $post_id ) && empty( $data['user_id'] ) && in_array( $type, array( '', 'comment' ), true ) ) {
			wp_die(
				esc_html__( 'التعليق متاح للأعضاء المسجّلين فقط.', 'retrovault-core' ),
				'',
				array( 'response' => 403 )
			);
		}
		return $data;
	}

	/**
	 * التعليقات مفتوحة افتراضياً للألعاب الجديدة.
	 *
	 * @param string $status       الحالة.
	 * @param string $post_type    نوع المحتوى.
	 * @param string $comment_type نوع التعليق.
	 */
	public static function default_open( $status, $post_type, $comment_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return ( Post_Types::GAME === $post_type && 'comment' === $comment_type ) ? 'open' : $status;
	}

	/**
	 * @param bool $open    الحالة.
	 * @param int  $post_id رقم المقالة.
	 */
	public static function no_pings( $open, $post_id ) {
		return self::is_game( $post_id ) ? false : $open;
	}

	/**
	 * هل كاتب التعليق هو مطوّر اللعبة (كاتب المقالة أو مدير الموقع)؟
	 *
	 * @param \WP_Comment $comment التعليق.
	 */
	public static function is_developer( $comment ) {
		if ( ! $comment || ! $comment->user_id ) {
			return false;
		}
		$post = get_post( $comment->comment_post_ID );
		if ( $post && (int) $post->post_author === (int) $comment->user_id ) {
			return true;
		}
		return user_can( (int) $comment->user_id, 'manage_options' );
	}
}
