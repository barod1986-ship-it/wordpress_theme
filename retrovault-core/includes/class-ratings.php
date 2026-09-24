<?php
/**
 * التقييمات بالنجوم (1–5) للأعضاء المسجلين.
 *
 * - جدول خاص {prefix}rv_ratings مع قيد فريد (لعبة + عضو) = تقييم واحد لكل عضو، قابل للتعديل.
 * - الملخّص (المتوسط والعدد) يُخزَّن في حقول اللعبة للعرض والفرز السريع.
 * - «الأعلى تقييماً» يُرتّب بمتوسط بايزي مرجّح، كي لا تتصدر لعبة بتقييم 5 وحيد
 *   أمام لعبة حاصلة على 4.8 من خمسين عضواً.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Ratings {

	/** وزن «التقييمات الافتراضية» في المتوسط المرجّح. */
	const PRIOR_WEIGHT = 5;

	public static function init() {
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rv_ratings';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			rating tinyint(3) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY game_user (game_id,user_id),
			KEY user_id (user_id)
			) {$charset};"
		);
	}

	/**
	 * @param int $game_id رقم اللعبة.
	 * @param int $user_id رقم العضو.
	 */
	public static function get_user_rating( $game_id, $user_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return 0;
		}
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT rating FROM {$table} WHERE game_id = %d AND user_id = %d", $game_id, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * إضافة أو تعديل تقييم عضو.
	 *
	 * @param int $game_id رقم اللعبة.
	 * @param int $user_id رقم العضو.
	 * @param int $rating  من 1 إلى 5.
	 * @return array الملخّص الجديد.
	 */
	public static function set( $game_id, $user_id, $rating ) {
		global $wpdb;
		$table  = self::table();
		$rating = max( 1, min( 5, (int) $rating ) );
		$now    = current_time( 'mysql', true );

		$exists = self::get_user_rating( $game_id, $user_id );
		if ( $exists ) {
			$wpdb->update( $table, array( 'rating' => $rating, 'updated_at' => $now ), array( 'game_id' => $game_id, 'user_id' => $user_id ), array( '%d', '%s' ), array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->insert( $table, array( 'game_id' => $game_id, 'user_id' => $user_id, 'rating' => $rating, 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%d', '%d', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		do_action( 'retrovault_rated', $game_id, $user_id, $rating );
		return self::recalculate( $game_id );
	}

	/**
	 * @param int $game_id رقم اللعبة.
	 * @param int $user_id رقم العضو.
	 * @return array الملخّص الجديد.
	 */
	public static function remove( $game_id, $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'game_id' => $game_id, 'user_id' => $user_id ), array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::recalculate( $game_id );
	}

	/**
	 * تقييمات كل الأعضاء للعبة (لعرض نجوم كل معلّق بجانب تعليقه).
	 *
	 * @param int $game_id رقم اللعبة.
	 * @return array<int,int> user_id => rating
	 */
	public static function for_game( $game_id ) {
		global $wpdb;
		static $cache = array();
		if ( isset( $cache[ $game_id ] ) ) {
			return $cache[ $game_id ];
		}
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, rating FROM {$table} WHERE game_id = %d", $game_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->user_id ] = (int) $row->rating;
		}
		$cache[ $game_id ] = $out;
		return $out;
	}

	/**
	 * تقييمات عضو (للعرض في صفحة حسابه)، الأحدث أولاً.
	 *
	 * @param int $user_id رقم العضو.
	 * @param int $limit   الحد الأقصى.
	 * @return array[] [ 'game_id' => int, 'rating' => int, 'time' => int ]
	 */
	public static function for_user( $user_id, $limit = 100 ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT game_id, rating, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d", $user_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$out   = array();
		foreach ( (array) $rows as $row ) {
			if ( Post_Types::GAME === get_post_type( (int) $row->game_id ) && 'publish' === get_post_status( (int) $row->game_id ) ) {
				$out[] = array(
					'game_id' => (int) $row->game_id,
					'rating'  => (int) $row->rating,
					'time'    => (int) strtotime( $row->updated_at . ' UTC' ),
				);
			}
		}
		return $out;
	}

	/**
	 * @param int $game_id رقم اللعبة.
	 * @return array{average:float,count:int,score:float}
	 */
	public static function summary( $game_id ) {
		return array(
			'average' => round( (float) get_post_meta( $game_id, '_rv_rating_avg', true ), 2 ),
			'count'   => (int) get_post_meta( $game_id, '_rv_rating_count', true ),
			'score'   => (float) get_post_meta( $game_id, '_rv_rating_score', true ),
		);
	}

	/**
	 * إعادة حساب الملخّص من الجدول وتخزينه في حقول اللعبة.
	 *
	 * @param int $game_id رقم اللعبة.
	 * @return array
	 */
	public static function recalculate( $game_id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS c, AVG(rating) AS a FROM {$table} WHERE game_id = %d", $game_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$count = $row ? (int) $row->c : 0;
		$avg   = ( $row && $count ) ? (float) $row->a : 0.0;

		$mean  = self::global_mean( true );
		$prior = (int) apply_filters( 'retrovault_rating_prior_weight', self::PRIOR_WEIGHT );
		$score = $count ? ( ( $prior * $mean ) + ( $avg * $count ) ) / ( $prior + $count ) : 0.0;

		update_post_meta( $game_id, '_rv_rating_avg', round( $avg, 4 ) );
		update_post_meta( $game_id, '_rv_rating_count', $count );
		update_post_meta( $game_id, '_rv_rating_score', round( $score, 4 ) );

		Games::flush( $game_id );
		delete_transient( 'rv_totals' );

		return self::summary( $game_id );
	}

	/**
	 * متوسط كل التقييمات في الموقع (أساس المتوسط المرجّح).
	 *
	 * @param bool $fresh تجاهل الذاكرة المؤقتة.
	 */
	public static function global_mean( $fresh = false ) {
		global $wpdb;
		$mean = $fresh ? false : get_transient( 'rv_rating_mean' );
		if ( false === $mean ) {
			$table = self::table();
			$mean  = (float) $wpdb->get_var( "SELECT AVG(rating) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$mean  = $mean > 0 ? $mean : 3.5;
			set_transient( 'rv_rating_mean', $mean, HOUR_IN_SECONDS );
		}
		return (float) $mean;
	}

	/**
	 * عند حذف عضو: احذف تقييماته وأعد حساب ألعابه.
	 *
	 * @param int $user_id رقم العضو.
	 */
	public static function on_delete_user( $user_id ) {
		global $wpdb;
		$table = self::table();
		$games = $wpdb->get_col( $wpdb->prepare( "SELECT game_id FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! $games ) {
			return;
		}
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $games as $game_id ) {
			self::recalculate( (int) $game_id );
		}
	}

	/**
	 * @param int $post_id رقم المقالة المحذوفة.
	 */
	public static function on_delete_post( $post_id ) {
		global $wpdb;
		if ( Post_Types::GAME !== get_post_type( $post_id ) ) {
			return;
		}
		$wpdb->delete( self::table(), array( 'game_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
