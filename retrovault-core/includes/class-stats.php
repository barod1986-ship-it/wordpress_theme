<?php
/**
 * عدّادات اللعب والتنزيل.
 * كل زائر يُحسب مرة واحدة لكل لعبة خلال 30 دقيقة (بصمة IP مشفّرة، لا يُخزَّن الـ IP نفسه).
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Stats {

	const WINDOW = 30 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( 'save_post_' . Post_Types::GAME, array( __CLASS__, 'flush_totals' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_totals' ) );
	}

	public static function flush_totals() {
		delete_transient( 'rv_totals' );
	}

	/**
	 * زيادة عدّاد إن لم يُحسب هذا الزائر مؤخراً.
	 *
	 * @param int    $post_id رقم اللعبة.
	 * @param string $key     _rv_play_count أو _rv_download_count.
	 * @return int القيمة الحالية.
	 */
	public static function hit( $post_id, $key ) {
		$lock = 'rv_hit_' . md5( $key . '|' . $post_id . '|' . self::fingerprint() );
		if ( false === get_transient( $lock ) ) {
			set_transient( $lock, 1, self::WINDOW );
			self::bump( $post_id, $key );
			Analytics::record( $post_id, '_rv_download_count' === $key ? 'downloads' : 'plays' );
		}
		return (int) get_post_meta( $post_id, $key, true );
	}

	/**
	 * زيادة ذرّية مباشرة في قاعدة البيانات (آمنة مع الطلبات المتزامنة).
	 *
	 * @param int    $post_id رقم اللعبة.
	 * @param string $key     المفتاح.
	 */
	public static function bump( $post_id, $key ) {
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s", $post_id, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $updated ) {
			add_post_meta( $post_id, $key, 1, true );
		}
		wp_cache_delete( $post_id, 'post_meta' );
		Games::flush( $post_id );
	}

	/** بصمة الزائر: عنوان IP بعد التشفير مع مفتاح الموقع. */
	private static function fingerprint() {
		return wp_hash( self::client_ip() . '|' . get_current_user_id() );
	}

	/**
	 * عنوان الزائر للعدّادات والحدود. عنوان IPv6 يُختصر إلى شبكته (/64): الجهاز الواحد يغيّر باقي
	 * العنوان متى شاء، فيُحسب زائراً جديداً في كل مرة. خلف وسيط (CDN) يمرر الموقع العنوان
	 * الحقيقي عبر المرشّح retrovault_client_ip.
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'retrovault_client_ip', $ip );
		if ( false !== strpos( $ip, ':' ) && function_exists( 'inet_pton' ) ) {
			$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- عنوان غير صالح يرجع false.
			if ( false !== $bin && 16 === strlen( $bin ) ) {
				$ip = bin2hex( substr( $bin, 0, 8 ) ) . '::/64';
			}
		}
		return $ip;
	}

	/**
	 * إجماليات للصفحة الرئيسية (مخزنة 10 دقائق).
	 *
	 * @return array{games:int,systems:int,plays:int,ratings:int}
	 */
	public static function totals() {
		$cached = get_transient( 'rv_totals' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$counts  = wp_count_posts( Post_Types::GAME );
		$plays   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(pm.meta_value + 0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish'", '_rv_play_count', Post_Types::GAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ratings = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Ratings::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$systems = get_terms(
			array(
				'taxonomy'   => Post_Types::SYSTEM,
				'hide_empty' => true,
				'fields'     => 'ids',
			)
		);

		$data = array(
			'games'   => isset( $counts->publish ) ? (int) $counts->publish : 0,
			'systems' => is_wp_error( $systems ) ? 0 : count( $systems ),
			'plays'   => $plays,
			'ratings' => $ratings,
		);
		set_transient( 'rv_totals', $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}
}
