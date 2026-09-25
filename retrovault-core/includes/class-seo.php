<?php
/**
 * محركات البحث والمشاركة:
 * - بيانات منظمة schema.org/VideoGame (مع aggregateRating عند وجود تقييمات).
 * - تدوينات يوميات التطوير: BlogPosting مع الألعاب التي تتحدث عنها.
 * - وسوم Open Graph ووصف الصفحة، فقط إن لم تكن هناك إضافة SEO تقوم بذلك.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Seo {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 5 );
	}

	public static function has_seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}

	public static function head() {
		if ( is_singular( 'post' ) ) {
			self::post_head();
			return;
		}
		if ( ! is_singular( Post_Types::GAME ) ) {
			return;
		}
		$game = Games::get( get_queried_object_id() );
		if ( ! $game ) {
			return;
		}
		$post        = get_post( $game['id'] );
		$description = wp_strip_all_tags( has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( strip_shortcodes( $post->post_content ), 30, '…' ) );
		$image       = $game['cover_id'] ? wp_get_attachment_image_url( $game['cover_id'], 'large' ) : '';

		$schema = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'VideoGame',
			'name'                => $game['title'],
			'url'                 => $game['url'],
			'description'         => $description,
			'applicationCategory' => 'Game',
			'operatingSystem'     => 'Web browser',
			'datePublished'       => get_the_date( 'c', $post ),
			'dateModified'        => get_the_modified_date( 'c', $post ),
			'author'              => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
			),
			'offers'              => array(
				'@type'         => 'Offer',
				'price'         => '0',
				'priceCurrency' => 'USD',
				'availability'  => 'https://schema.org/InStock',
			),
			'playMode'            => $game['players'] > 1 ? 'MultiPlayer' : 'SinglePlayer',
			'numberOfPlayers'     => array(
				'@type'    => 'QuantitativeValue',
				'minValue' => 1,
				'maxValue' => $game['players'],
			),
		);
		if ( $image ) {
			$schema['image'] = $image;
		}
		if ( $game['system'] ) {
			$schema['gamePlatform'] = $game['system']['full'];
		}
		if ( $game['genres'] ) {
			$schema['genre'] = wp_list_pluck( $game['genres'], 'name' );
		}
		if ( $game['version'] ) {
			$schema['softwareVersion'] = $game['version'];
		}
		if ( $game['rating']['count'] > 0 ) {
			$schema['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => round( $game['rating']['average'], 1 ),
				'ratingCount' => $game['rating']['count'],
				'bestRating'  => 5,
				'worstRating' => 1,
			);
		}

		self::json_ld( $schema );

		if ( self::has_seo_plugin() ) {
			return;
		}
		self::meta_tags(
			array(
				'description'    => $description,
				'og:type'        => 'website',
				'og:title'       => $game['title'],
				'og:description' => $description,
				'og:url'         => $game['url'],
				'og:site_name'   => get_bloginfo( 'name' ),
				'og:image'       => $image,
				'twitter:card'   => $image ? 'summary_large_image' : 'summary',
			)
		);
	}

	/**
	 * تدوينة من يوميات التطوير: صورتها (أو غلاف أول لعبة فيها) ووصفها عند مشاركتها. إضافات SEO
	 * تتولى المقالات كاملة، فلا شيء هنا معها.
	 */
	private static function post_head() {
		if ( self::has_seo_plugin() ) {
			return;
		}
		$post        = get_post( get_queried_object_id() );
		$description = wp_strip_all_tags( get_the_excerpt( $post ) );
		$image       = has_post_thumbnail( $post ) ? (string) get_the_post_thumbnail_url( $post, 'large' ) : '';
		$about       = array();
		foreach ( Devlog::games_for_post( $post->ID ) as $game_id ) {
			$game = Games::get( $game_id );
			if ( ! $game ) {
				continue;
			}
			$about[] = array(
				'@type' => 'VideoGame',
				'name'  => $game['title'],
				'url'   => $game['url'],
			);
			if ( '' === $image && $game['cover_id'] ) {
				$image = (string) wp_get_attachment_image_url( $game['cover_id'], 'large' );
			}
		}
		$url    = (string) get_permalink( $post );
		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'BlogPosting',
			'headline'         => get_the_title( $post ),
			'url'              => $url,
			'mainEntityOfPage' => $url,
			'description'      => $description,
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
			),
		);
		if ( $image ) {
			$schema['image'] = $image;
		}
		if ( $about ) {
			$schema['about'] = $about;
		}
		self::json_ld( $schema );
		self::meta_tags(
			array(
				'description'            => $description,
				'og:type'                => 'article',
				'og:title'               => get_the_title( $post ),
				'og:description'         => $description,
				'og:url'                 => $url,
				'og:site_name'           => get_bloginfo( 'name' ),
				'og:image'               => $image,
				'article:published_time' => get_the_date( 'c', $post ),
				'twitter:card'           => $image ? 'summary_large_image' : 'summary',
			)
		);
	}

	/**
	 * @param array $schema البيانات المنظمة.
	 */
	private static function json_ld( $schema ) {
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param array<string,string> $tags الوسوم (og: و article: خاصيات، والباقي أسماء).
	 */
	private static function meta_tags( $tags ) {
		foreach ( $tags as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$attr = ( 0 === strpos( $key, 'og:' ) || 0 === strpos( $key, 'article:' ) ) ? 'property' : 'name';
			printf( '<meta %1$s="%2$s" content="%3$s">' . "\n", esc_attr( $attr ), esc_attr( $key ), esc_attr( $value ) );
		}
	}
}
