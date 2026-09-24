<?php
/**
 * المفضلة = متابعة اللعبة.
 *
 * جدول {prefix}rv_follows (عضو + لعبة) مفهرس بالاتجاهين:
 *   - ألعاب العضو (صفحة «حسابي»).
 *   - متابعو اللعبة (لإرسال إشعارات التحديث).
 * عدد المتابعين يُخزَّن أيضاً في _rv_fav_count للعرض والفرز السريع.
 *
 *   POST   /wp-json/retrovault/v1/games/{id}/favorite   إضافة
 *   DELETE /wp-json/retrovault/v1/games/{id}/favorite   إزالة
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Favorites {

	/** الصيغة القديمة (حتى 1.2): قائمة في بيانات العضو، تُرحَّل للجدول ثم تُحذف. */
	const LEGACY_META = '_rv_favorites';
	const COUNT       = '_rv_fav_count';
	const LIMIT       = 1000;

	/** @var array<string,bool> */
	private static $has = array();

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rv_follows';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			user_id bigint(20) unsigned NOT NULL,
			game_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (user_id,game_id),
			KEY game_id (game_id)
			) {$charset};"
		);
	}

	/**
	 * نقل المفضلة من بيانات الأعضاء (الصيغة القديمة) إلى الجدول.
	 */
	public static function migrate_legacy() {
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", self::LEGACY_META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$games = array();
		foreach ( (array) $rows as $row ) {
			$ids = maybe_unserialize( $row->meta_value );
			if ( is_array( $ids ) ) {
				$time = time();
				foreach ( array_values( $ids ) as $i => $game_id ) {
					// الأحدث كان أولاً في القائمة القديمة؛ نحافظ على الترتيب بفارق ثانية.
					self::insert( (int) $row->user_id, (int) $game_id, $time - $i );
					$games[ (int) $game_id ] = true;
				}
			}
			delete_user_meta( (int) $row->user_id, self::LEGACY_META );
		}
		foreach ( array_keys( $games ) as $game_id ) {
			self::recount( $game_id );
		}
	}

	/**
	 * @param int $user_id رقم العضو.
	 * @param int $game_id رقم اللعبة.
	 * @param int $time    وقت الإضافة.
	 * @return bool أُضيف فعلاً (لم يكن موجوداً).
	 */
	private static function insert( $user_id, $game_id, $time ) {
		global $wpdb;
		if ( ! $user_id || ! $game_id ) {
			return false;
		}
		$table = self::table();
		return (bool) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (user_id, game_id, created_at) VALUES (%d, %d, %s)", $user_id, $game_id, gmdate( 'Y-m-d H:i:s', $time ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * الألعاب المنشورة في مفضلة العضو (الأحدث إضافة أولاً).
	 *
	 * @param int $user_id رقم العضو.
	 * @return int[]
	 */
	public static function get( $user_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return array();
		}
		$table = self::table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT f.game_id FROM {$table} f INNER JOIN {$wpdb->posts} p ON p.ID = f.game_id WHERE f.user_id = %d AND p.post_type = %s AND p.post_status = 'publish' ORDER BY f.created_at DESC LIMIT %d", $user_id, Post_Types::GAME, self::LIMIT ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * @param int $game_id رقم اللعبة.
	 * @param int $user_id رقم العضو.
	 */
	public static function has( $game_id, $user_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return false;
		}
		$key = $user_id . ':' . $game_id;
		if ( ! isset( self::$has[ $key ] ) ) {
			$table             = self::table();
			self::$has[ $key ] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE user_id = %d AND game_id = %d", $user_id, $game_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		return self::$has[ $key ];
	}

	/**
	 * متى تابع العضو اللعبة (Unix)، أو 0.
	 *
	 * @param int $game_id رقم اللعبة.
	 * @param int $user_id رقم العضو.
	 */
	public static function since( $game_id, $user_id ) {
		global $wpdb;
		$table = self::table();
		$time  = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$table} WHERE user_id = %d AND game_id = %d", $user_id, $game_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $time ? (int) strtotime( $time . ' UTC' ) : 0;
	}

	/**
	 * متابعو لعبة أو أكثر (بلا تكرار، مرتبون ثابتاً لإرسال البريد على دفعات).
	 *
	 * @param int[] $game_ids الألعاب.
	 * @return int[]
	 */
	public static function followers( $game_ids ) {
		global $wpdb;
		$game_ids = array_values( array_filter( array_map( 'absint', (array) $game_ids ) ) );
		if ( ! $game_ids ) {
			return array();
		}
		$table = self::table();
		$in    = implode( ',', $game_ids );
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table} WHERE game_id IN ({$in}) ORDER BY user_id ASC" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param int $game_id رقم اللعبة.
	 */
	public static function count( $game_id ) {
		return max( 0, (int) get_post_meta( $game_id, self::COUNT, true ) );
	}

	/**
	 * @param int $game_id رقم اللعبة.
	 */
	private static function recount( $game_id ) {
		global $wpdb;
		$table = self::table();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE game_id = %d", $game_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		update_post_meta( $game_id, self::COUNT, $count );
		return $count;
	}

	/**
	 * @param int $game_id رقم اللعبة.
	 * @param int $user_id رقم العضو.
	 */
	public static function add( $game_id, $user_id ) {
		if ( self::insert( $user_id, $game_id, time() ) ) {
			self::recount( $game_id );
		}
		self::$has[ $user_id . ':' . $game_id ] = true;
		return true;
	}

	/**
	 * @param int $game_id رقم اللعبة.
	 * @param int $user_id رقم العضو.
	 */
	public static function remove( $game_id, $user_id ) {
		global $wpdb;
		if ( $wpdb->delete( self::table(), array( 'user_id' => $user_id, 'game_id' => $game_id ), array( '%d', '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::recount( $game_id );
		}
		self::$has[ $user_id . ':' . $game_id ] = false;
		return true;
	}

	/**
	 * @param int $user_id رقم العضو المحذوف.
	 */
	public static function on_delete_user( $user_id ) {
		global $wpdb;
		$table = self::table();
		$games = $wpdb->get_col( $wpdb->prepare( "SELECT game_id FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $games as $game_id ) {
			self::recount( (int) $game_id );
		}
	}

	/**
	 * @param int $post_id رقم المحتوى المحذوف.
	 */
	public static function on_delete_post( $post_id ) {
		global $wpdb;
		if ( Post_Types::GAME === get_post_type( $post_id ) ) {
			$wpdb->delete( self::table(), array( 'game_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	public static function routes() {
		register_rest_route(
			Rest::NS,
			'/games/(?P<id>\d+)/favorite',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_add' ),
					'permission_callback' => array( Rest::class, 'logged_in' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'rest_remove' ),
					'permission_callback' => array( Rest::class, 'logged_in' ),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function rest_add( $request ) {
		$post = Rest::game( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		self::add( $post->ID, get_current_user_id() );
		return rest_ensure_response( array( 'favorite' => true, 'count' => self::count( $post->ID ) ) );
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function rest_remove( $request ) {
		$post = Rest::game( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		self::remove( $post->ID, get_current_user_id() );
		return rest_ensure_response( array( 'favorite' => false, 'count' => self::count( $post->ID ) ) );
	}
}
