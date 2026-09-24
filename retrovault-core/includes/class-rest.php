<?php
/**
 * واجهة REST:
 *   GET    /wp-json/retrovault/v1/games/{id}/rating   ملخّص التقييم + تقييمك
 *   POST   /wp-json/retrovault/v1/games/{id}/rating   { rating: 1..5 }  (عضو مسجّل + nonce)
 *   DELETE /wp-json/retrovault/v1/games/{id}/rating   حذف تقييمك
 *   POST   /wp-json/retrovault/v1/games/{id}/play     تسجيل مرة لعب (عام، محمي من التكرار)
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'retrovault/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$id = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NS,
			'/games/(?P<id>\d+)/rating',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_rating' ),
					'permission_callback' => '__return_true',
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_rating' ),
					'permission_callback' => array( __CLASS__, 'must_login' ),
					'args'                => $id + array(
						'rating' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
							'maximum'  => 5,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_rating' ),
					'permission_callback' => array( __CLASS__, 'must_login' ),
					'args'                => $id,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/games/(?P<id>\d+)/play',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'play' ),
				'permission_callback' => '__return_true',
				'args'                => $id,
			)
		);
	}

	public static function must_login() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new \WP_Error( 'rv_login_required', __( 'سجّل دخولك لتقييم الألعاب.', 'retrovault-core' ), array( 'status' => 401 ) );
	}

	/** صلاحية عامة لميزات الأعضاء (المفضلة، الحفظ السحابي). */
	public static function logged_in() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new \WP_Error( 'rv_login_required', __( 'هذه الميزة للأعضاء المسجّلين. سجّل دخولك أولاً.', 'retrovault-core' ), array( 'status' => 401 ) );
	}

	/**
	 * لعبة منشورة ومتاحة، أو خطأ 404.
	 *
	 * @param int $id رقم اللعبة.
	 * @return \WP_Post|\WP_Error
	 */
	public static function game( $id ) {
		$post = get_post( $id );
		if ( ! $post || Post_Types::GAME !== $post->post_type || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return new \WP_Error( 'rv_not_found', __( 'اللعبة غير موجودة.', 'retrovault-core' ), array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * @param array $summary الملخّص.
	 * @param int   $game_id رقم اللعبة.
	 */
	private static function payload( $summary, $game_id ) {
		return array(
			'average' => (float) $summary['average'],
			'count'   => (int) $summary['count'],
			'user'    => Ratings::get_user_rating( $game_id, get_current_user_id() ),
		);
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function get_rating( $request ) {
		$post = self::game( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return rest_ensure_response( self::payload( Ratings::summary( $post->ID ), $post->ID ) );
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function set_rating( $request ) {
		$post = self::game( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$summary = Ratings::set( $post->ID, get_current_user_id(), (int) $request['rating'] );
		return rest_ensure_response( self::payload( $summary, $post->ID ) );
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function delete_rating( $request ) {
		$post = self::game( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$summary = Ratings::remove( $post->ID, get_current_user_id() );
		return rest_ensure_response( self::payload( $summary, $post->ID ) );
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function play( $request ) {
		$post = self::game( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		return rest_ensure_response( array( 'plays' => Stats::hit( $post->ID, '_rv_play_count' ) ) );
	}
}
