<?php
/**
 * الفلترة والفرز في صفحات المكتبة.
 *
 * كل الفلاتر معاملات GET عادية في الرابط، فتعمل بدون JavaScript وتصلح للمشاركة:
 *   /games/?system=gba&genre=platformer&players=multi&status=released&sort=rating&q=نص
 * (system و genre معاملات التصنيفات الأصلية في ووردبريس؛ الباقي تتعامل معه هذه الفئة.)
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Query {

	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'main_query' ) );
		add_action( 'template_redirect', array( __CLASS__, 'random' ), 0 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/**
	 * @return array<string,string>
	 */
	public static function sorts() {
		return array(
			'newest'   => __( 'الأحدث', 'retrovault-core' ),
			'trending' => __( 'الرائجة الآن', 'retrovault-core' ),
			'rating'  => __( 'الأعلى تقييماً', 'retrovault-core' ),
			'plays'   => __( 'الأكثر لعباً', 'retrovault-core' ),
			'updated' => __( 'آخر تحديث', 'retrovault-core' ),
			'year'    => __( 'سنة الإصدار', 'retrovault-core' ),
			'title'   => __( 'الاسم', 'retrovault-core' ),
		);
	}

	/**
	 * @param string $name اسم المعامل.
	 */
	private static function param( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- فلاتر عرض عامة للقراءة فقط.
		$value = isset( $_GET[ $name ] ) ? wp_unslash( $_GET[ $name ] ) : '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * الفلاتر الحالية بعد التنقية.
	 *
	 * @return array{q:string,system:string,genre:string,players:string,status:string,sort:string}
	 */
	public static function filters() {
		$sort    = sanitize_key( self::param( 'sort' ) );
		$status  = sanitize_key( self::param( 'status' ) );
		$players = sanitize_key( self::param( 'players' ) );

		return array(
			'q'       => sanitize_text_field( self::param( 'q' ) ),
			'system'  => sanitize_title( (string) get_query_var( 'system' ) ),
			'genre'   => sanitize_title( (string) get_query_var( 'genre' ) ),
			'players' => in_array( $players, array( 'single', 'multi' ), true ) ? $players : '',
			'status'  => array_key_exists( $status, Games::statuses() ) ? $status : '',
			'sort'    => array_key_exists( $sort, self::sorts() ) ? $sort : 'newest',
		);
	}

	/**
	 * هل الطلب الحالي صفحة من صفحات المكتبة؟
	 *
	 * @param \WP_Query|null $query الاستعلام.
	 */
	public static function is_library( $query = null ) {
		if ( null === $query ) {
			global $wp_query;
			$query = $wp_query;
		}
		if ( ! $query instanceof \WP_Query ) {
			return false;
		}
		return $query->is_post_type_archive( Post_Types::GAME ) || $query->is_tax( array( Post_Types::SYSTEM, Post_Types::GENRE ) );
	}

	/**
	 * @param \WP_Query $query الاستعلام الرئيسي.
	 */
	public static function main_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! self::is_library( $query ) ) {
			return;
		}
		$f = self::filters();

		$query->set( 'post_type', Post_Types::GAME );
		$query->set( 'posts_per_page', (int) Settings::get( 'per_page' ) );
		if ( '' !== $f['q'] ) {
			$query->set( 's', $f['q'] );
		}

		$meta = array();
		if ( 'multi' === $f['players'] ) {
			$meta[] = array(
				'key'     => '_rv_players',
				'value'   => 2,
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		} elseif ( 'single' === $f['players'] ) {
			$meta[] = array(
				'key'     => '_rv_players',
				'value'   => 1,
				'compare' => '=',
				'type'    => 'NUMERIC',
			);
		}
		if ( $f['status'] ) {
			$meta[] = array(
				'key'   => '_rv_status',
				'value' => $f['status'],
			);
		}

		foreach ( self::sort_args( $f['sort'], $meta ) as $key => $value ) {
			$query->set( $key, $value );
		}
	}

	/**
	 * وسائط الفرز لـ WP_Query. كل لعبة تملك كل الحقول (انظر Game_Meta::ensure_defaults)
	 * لذلك يكفي شرط EXISTS مُسمّى نرتّب به.
	 *
	 * @param string $sort نوع الترتيب.
	 * @param array  $meta شروط حقول إضافية.
	 * @return array
	 */
	public static function sort_args( $sort, array $meta = array() ) {
		$args    = array();
		$numeric = array(
			'rating' => array( '_rv_rating_score', 'DECIMAL(10,4)' ),
			'plays'    => array( '_rv_play_count', 'UNSIGNED' ),
			'trending' => array( Analytics::TREND, 'UNSIGNED' ),
			'year'   => array( '_rv_year', 'UNSIGNED' ),
		);

		if ( isset( $numeric[ $sort ] ) ) {
			$meta['rv_order'] = array(
				'key'     => $numeric[ $sort ][0],
				'type'    => $numeric[ $sort ][1],
				'compare' => 'EXISTS',
			);
			$args['orderby']  = array(
				'rv_order' => 'DESC',
				'date'     => 'DESC',
			);
		} elseif ( 'title' === $sort ) {
			$args['orderby'] = array( 'title' => 'ASC' );
		} elseif ( 'updated' === $sort ) {
			$args['orderby'] = array( 'modified' => 'DESC' );
		} else {
			$args['orderby'] = array( 'date' => 'DESC' );
		}

		if ( $meta ) {
			$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return $args;
	}

	/**
	 * زر «لعبة عشوائية»: /?rv_random=1
	 */
	public static function random() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['rv_random'] ) ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::GAME,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'rand',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		nocache_headers();
		wp_safe_redirect( $ids ? get_permalink( $ids[0] ) : get_post_type_archive_link( Post_Types::GAME ), 302 );
		exit;
	}

	/**
	 * صفحات الفلترة والفرز مكررة المحتوى؛ نطلب من محركات البحث عدم فهرستها.
	 *
	 * @param array $robots توجيهات robots.
	 */
	public static function robots( $robots ) {
		if ( ! self::is_library() ) {
			return $robots;
		}
		foreach ( array( 'q', 'sort', 'players', 'status' ) as $name ) {
			if ( '' !== self::param( $name ) ) {
				$robots['noindex'] = true;
				$robots['follow']  = true;
				break;
			}
		}
		if ( is_post_type_archive( Post_Types::GAME ) && ( '' !== self::param( 'system' ) || '' !== self::param( 'genre' ) ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}
}
