<?php
/**
 * نموذج اللعبة: يجمع البيانات من الحقول والتصنيفات والإحصائيات في مصفوفة واحدة موحّدة.
 * القالب لا يقرأ الحقول مباشرة؛ يستخدم rv_get_game() التي تعيد هذه المصفوفة.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Games {

	/** @var array<int,array> */
	private static $cache = array();

	/** @var array<int,array> */
	private static $systems = array();

	/**
	 * @param int $post_id رقم لعبة، أو 0 لمسح الكل.
	 */
	public static function flush( $post_id = 0 ) {
		if ( $post_id ) {
			unset( self::$cache[ (int) $post_id ] );
		} else {
			self::$cache   = array();
			self::$systems = array();
		}
	}

	/**
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'released' => __( 'مكتملة', 'retrovault-core' ),
			'demo'     => __( 'ديمو', 'retrovault-core' ),
			'beta'     => __( 'بيتا', 'retrovault-core' ),
			'wip'      => __( 'قيد التطوير', 'retrovault-core' ),
		);
	}

	/**
	 * @param int $players الحد الأقصى للاعبين.
	 */
	public static function players_label( $players ) {
		$players = max( 1, (int) $players );
		if ( 1 === $players ) {
			return __( 'لاعب واحد', 'retrovault-core' );
		}
		if ( 2 === $players ) {
			return __( 'حتى لاعبَين', 'retrovault-core' );
		}
		/* translators: %s: number of players */
		return sprintf( __( 'حتى %s لاعبين', 'retrovault-core' ), number_format_i18n( $players ) );
	}

	/**
	 * @param int|\WP_Post|null $post اللعبة.
	 * @return array|null
	 */
	public static function get( $post = null ) {
		$post = get_post( $post );
		if ( ! $post || Post_Types::GAME !== $post->post_type ) {
			return null;
		}
		if ( isset( self::$cache[ $post->ID ] ) ) {
			return self::$cache[ $post->ID ];
		}

		$id     = $post->ID;
		$meta   = Game_Meta::values( $id );
		$system = self::system_for( $id );
		$rom    = self::rom( $id, $meta );

		$core = '';
		if ( $system ) {
			$core = ( $meta['core'] && in_array( $meta['core'], $system['cores'], true ) ) ? $meta['core'] : $system['ejs'];
		}
		$statuses = self::statuses();
		$status   = isset( $statuses[ $meta['status'] ] ) ? $meta['status'] : 'released';

		$game = array(
			'id'           => $id,
			'title'        => get_the_title( $post ),
			'url'          => get_permalink( $post ),
			'player_url'   => self::endpoint_url( $post, 'play' ),
			'download_url' => self::endpoint_url( $post, 'download' ),
			'system'       => $system,
			'genres'       => self::terms( $id, Post_Types::GENRE ),
			'core'         => $core,
			'rom'          => $rom,
			'playable'     => ( $system && '' !== $core && '' !== $rom['url'] ),
			'cover_id'     => (int) get_post_thumbnail_id( $post ),
			'banner_id'    => $meta['banner_id'],
			'screenshots'  => $meta['screenshots'],
			'trailer'      => $meta['trailer'],
			'year'         => $meta['year'],
			'version'      => $meta['version'],
			'players'      => max( 1, $meta['players'] ),
			'status'       => $status,
			'status_label' => $statuses[ $status ],
			'languages'    => $meta['languages'],
			'controls'     => $meta['controls'],
			'changelog'    => $meta['changelog'],
			'credits'      => $meta['credits'],
			'has_content'  => '' !== trim( (string) $post->post_content ),
			// المقتطف المكتوب فعلاً (لا المولَّد من الوصف).
			'lede'         => (string) $post->post_excerpt,
			'featured'     => $meta['featured'],
			'downloadable' => ( $meta['downloadable'] && Settings::get( 'downloads' ) && '' !== $rom['raw_url'] ),
			'plays'        => (int) get_post_meta( $id, '_rv_play_count', true ),
			'downloads'    => (int) get_post_meta( $id, '_rv_download_count', true ),
			'rating'       => Ratings::summary( $id ),
			'updated'      => (int) get_post_modified_time( 'U', true, $post ),
		);

		self::$cache[ $post->ID ] = apply_filters( 'retrovault_game', $game, $post );
		return self::$cache[ $post->ID ];
	}

	/**
	 * اسم النواة الفعلي (إذا كانت القيمة اسم نظام مثل nes فالنواة هي الأولى في قائمته).
	 *
	 * @param array $game بيانات اللعبة.
	 */
	public static function resolved_core( $game ) {
		if ( ! $game || ! $game['system'] ) {
			return '';
		}
		$cores = $game['system']['cores'];
		return in_array( $game['core'], $cores, true ) ? $game['core'] : ( $cores ? $cores[0] : $game['core'] );
	}

	/**
	 * رابط نقطة نهاية للعبة (/play/ أو /download/) مع بديل للروابط غير الجميلة والمسودات.
	 *
	 * @param \WP_Post $post     اللعبة.
	 * @param string   $endpoint play|download.
	 */
	public static function endpoint_url( $post, $endpoint ) {
		global $wp_rewrite;
		$link = get_permalink( $post );
		if ( $wp_rewrite && $wp_rewrite->using_permalinks() && 'publish' === get_post_status( $post ) ) {
			return user_trailingslashit( trailingslashit( $link ) . $endpoint );
		}
		return add_query_arg( 'rv_' . $endpoint, '1', $link );
	}

	/**
	 * @param int $post_id رقم اللعبة.
	 * @return array|null
	 */
	public static function system_for( $post_id ) {
		$terms = get_the_terms( $post_id, Post_Types::SYSTEM );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return null;
		}
		return self::system_data( $terms[0] );
	}

	/**
	 * بيانات نظام = بيانات التصنيف + تعريفه في السجل.
	 *
	 * @param \WP_Term $term تصنيف النظام.
	 * @return array
	 */
	public static function system_data( $term ) {
		if ( isset( self::$systems[ $term->term_id ] ) ) {
			return self::$systems[ $term->term_id ];
		}
		$key = (string) get_term_meta( $term->term_id, 'rv_system_key', true );
		$reg = Systems::get( $key );
		if ( ! $reg && Systems::get( $term->slug ) ) {
			$key = $term->slug;
			$reg = Systems::get( $key );
		}
		$color = (string) get_term_meta( $term->term_id, 'rv_color', true );
		$link  = get_term_link( $term );

		self::$systems[ $term->term_id ] = array(
			'term_id' => (int) $term->term_id,
			'slug'    => $term->slug,
			'name'    => $term->name,
			'key'     => $reg ? $key : '',
			'short'   => $reg ? $reg['short'] : $term->name,
			'full'    => $reg ? $reg['name'] : $term->name,
			'maker'   => $reg ? $reg['maker'] : '',
			'year'    => $reg ? (int) $reg['year'] : 0,
			'ejs'     => $reg ? $reg['ejs'] : '',
			'cores'   => $reg ? $reg['cores'] : array(),
			'ext'     => $reg ? $reg['ext'] : array(),
			'ratio'   => $reg ? $reg['ratio'] : '4/3',
			'color'   => $color ? $color : ( $reg ? $reg['color'] : '#555560' ),
			'bios'    => (string) get_term_meta( $term->term_id, 'rv_bios_url', true ),
			'count'   => (int) $term->count,
			'link'    => is_wp_error( $link ) ? '' : $link,
		);
		return self::$systems[ $term->term_id ];
	}

	/**
	 * كل الأنظمة المستخدمة (أو كلها) مرتبة حسب سنة الجهاز.
	 *
	 * @param bool $hide_empty إخفاء الأنظمة بلا ألعاب.
	 * @return array[]
	 */
	public static function systems( $hide_empty = true ) {
		$terms = get_terms(
			array(
				'taxonomy'   => Post_Types::SYSTEM,
				'hide_empty' => $hide_empty,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array_map( array( __CLASS__, 'system_data' ), $terms );
		usort(
			$out,
			static function ( $a, $b ) {
				return ( $a['year'] ? $a['year'] : 9999 ) <=> ( $b['year'] ? $b['year'] : 9999 );
			}
		);
		return $out;
	}

	/**
	 * @param int    $post_id  رقم اللعبة.
	 * @param string $taxonomy التصنيف.
	 * @return array[]
	 */
	public static function terms( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$link  = get_term_link( $term );
			$out[] = array(
				'term_id' => (int) $term->term_id,
				'name'    => $term->name,
				'slug'    => $term->slug,
				'link'    => is_wp_error( $link ) ? '' : $link,
			);
		}
		return $out;
	}

	/**
	 * معلومات ملف اللعبة. url يحمل ?v= لكسر التخزين المؤقت في المتصفح عند تحديث اللعبة
	 * (EmulatorJS يحفظ الألعاب في IndexedDB حسب اسم الملف).
	 *
	 * @param int   $post_id رقم اللعبة.
	 * @param array $meta    الحقول.
	 * @return array
	 */
	public static function rom( $post_id, $meta ) {
		$raw       = '';
		$file      = '';
		$size      = 0;
		$stamp     = '';
		$protected = false;

		if ( $meta['rom_id'] ) {
			$raw       = (string) wp_get_attachment_url( $meta['rom_id'] );
			$path      = get_attached_file( $meta['rom_id'] );
			$protected = $path && Roms::is_protected_path( $path ) && is_file( $path ) && is_readable( $path );
			if ( ! $protected ) {
				$raw = ''; // An attachment that failed protection must never expose a public fallback.
			}
			// الاسم الأصلي، لا الاسم العشوائي للملف المحمي.
			$file = $path ? Roms::name( $meta['rom_id'], $path ) : '';
			$md   = wp_get_attachment_metadata( $meta['rom_id'] );
			if ( is_array( $md ) && ! empty( $md['filesize'] ) ) {
				$size = (int) $md['filesize'];
			} elseif ( $path && file_exists( $path ) ) {
				$size = (int) filesize( $path );
			}
			$stamp = (string) get_post_modified_time( 'U', true, $meta['rom_id'] );
		}
		if ( ! $meta['rom_id'] && $meta['rom_url'] ) {
			$raw  = $meta['rom_url'];
			$file = wp_basename( (string) wp_parse_url( $raw, PHP_URL_PATH ) );
		}

		$url = '';
		$ver = '';
		if ( '' !== $raw ) {
			$ver = substr( md5( $raw . '|' . $size . '|' . $stamp . '|' . $meta['version'] ), 0, 10 );
			$url = add_query_arg( 'v', $ver, $raw );
		}

		return array(
			'id'        => (int) $meta['rom_id'],
			'url'       => $url,
			'raw_url'   => $raw,
			'file'      => $file,
			'ext'       => strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ),
			'size'      => $size,
			'ver'       => $ver,
			// محمي = في المجلد المغلق، فيصل للمشغّل برابط مؤقت فقط (Roms).
			'protected' => $protected,
		);
	}

	/**
	 * هل الصورة بدقة جهاز أصلية (لقطة بكسل) فتُعرض بدون تنعيم؟
	 *
	 * @param int $attachment_id رقم الصورة.
	 */
	public static function is_pixel_image( $attachment_id ) {
		$md = wp_get_attachment_metadata( $attachment_id );
		return is_array( $md ) && ! empty( $md['width'] ) && (int) $md['width'] <= 640;
	}

	/**
	 * صورة خلفية المشغّل: العريضة ← أول لقطة ← الغلاف.
	 *
	 * @param array $game بيانات اللعبة.
	 * @return array{url:string,pixel:bool}
	 */
	public static function poster( $game ) {
		if ( $game['banner_id'] ) {
			$url = wp_get_attachment_image_url( $game['banner_id'], 'large' );
			if ( $url ) {
				return array( 'url' => $url, 'pixel' => false );
			}
		}
		if ( $game['screenshots'] ) {
			$first = $game['screenshots'][0];
			$url   = wp_get_attachment_image_url( $first, 'full' );
			if ( $url ) {
				return array( 'url' => $url, 'pixel' => self::is_pixel_image( $first ) );
			}
		}
		if ( $game['cover_id'] ) {
			$url = wp_get_attachment_image_url( $game['cover_id'], 'large' );
			if ( $url ) {
				return array( 'url' => $url, 'pixel' => false );
			}
		}
		return array( 'url' => '', 'pixel' => false );
	}

	/**
	 * استعلام ألعاب للرفوف والأقسام.
	 *
	 * @param array $args sort, number, system, genre, featured, rated_only, played_only, exclude.
	 * @return \WP_Query
	 */
	public static function query( $args = array() ) {
		$a = wp_parse_args(
			$args,
			array(
				'sort'        => 'newest',
				'number'      => 8,
				'system'      => '',
				'genre'       => '',
				'featured'    => false,
				'rated_only'  => false,
				'played_only' => false,
				'trending'    => false,
				'exclude'     => array(),
			)
		);

		$meta = array();
		if ( $a['featured'] ) {
			$meta[] = array(
				'key'   => '_rv_featured',
				'value' => '1',
			);
		}
		if ( $a['rated_only'] ) {
			$meta[] = array(
				'key'     => '_rv_rating_count',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
		}
		if ( $a['trending'] ) {
			$meta[] = array(
				'key'     => Analytics::TREND,
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
		}
		if ( $a['played_only'] ) {
			$meta[] = array(
				'key'     => '_rv_play_count',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
		}

		$query = array_merge(
			array(
				'post_type'           => Post_Types::GAME,
				'post_status'         => 'publish',
				'posts_per_page'      => (int) $a['number'],
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'post__not_in'        => array_map( 'absint', (array) $a['exclude'] ),
			),
			Query::sort_args( $a['sort'], $meta )
		);

		$tax = array();
		foreach ( array( 'system' => Post_Types::SYSTEM, 'genre' => Post_Types::GENRE ) as $arg => $taxonomy ) {
			if ( ! empty( $a[ $arg ] ) ) {
				$tax[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => (array) $a[ $arg ],
				);
			}
		}
		if ( $tax ) {
			$query['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		return new \WP_Query( apply_filters( 'retrovault_query_args', $query, $a ) );
	}

	/**
	 * ألعاب مشابهة (نفس النظام أو النوع).
	 *
	 * @param int|\WP_Post|null $post  اللعبة.
	 * @param int               $count العدد.
	 * @return \WP_Query
	 */
	public static function related( $post = null, $count = 6 ) {
		$game = self::get( $post );
		$args = array(
			'post_type'           => Post_Types::GAME,
			'post_status'         => 'publish',
			'posts_per_page'      => $count,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'orderby'             => 'rand',
			'post__not_in'        => $game ? array( $game['id'] ) : array(),
		);
		if ( $game ) {
			$tax = array( 'relation' => 'OR' );
			if ( $game['system'] ) {
				$tax[] = array(
					'taxonomy' => Post_Types::SYSTEM,
					'field'    => 'term_id',
					'terms'    => array( $game['system']['term_id'] ),
				);
			}
			if ( $game['genres'] ) {
				$tax[] = array(
					'taxonomy' => Post_Types::GENRE,
					'field'    => 'term_id',
					'terms'    => wp_list_pluck( $game['genres'], 'term_id' ),
				);
			}
			if ( count( $tax ) > 1 ) {
				$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			}
		}
		return new \WP_Query( $args );
	}
}
