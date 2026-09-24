<?php
/**
 * محركات البحث والمشاركة:
 * - بيانات منظمة schema.org/VideoGame (مع aggregateRating عند وجود تقييمات).
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

		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( self::has_seo_plugin() ) {
			return;
		}
		$tags = array(
			'description'    => $description,
			'og:type'        => 'website',
			'og:title'       => $game['title'],
			'og:description' => $description,
			'og:url'         => $game['url'],
			'og:site_name'   => get_bloginfo( 'name' ),
			'og:image'       => $image,
			'twitter:card'   => $image ? 'summary_large_image' : 'summary',
		);
		foreach ( $tags as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$attr = ( 0 === strpos( $key, 'og:' ) ) ? 'property' : 'name';
			printf( '<meta %1$s="%2$s" content="%3$s">' . "\n", esc_attr( $attr ), esc_attr( $key ), esc_attr( $value ) );
		}
	}
}
