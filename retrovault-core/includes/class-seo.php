<?php
/**
 * محركات البحث والمشاركة:
 * - بيانات منظمة: VideoGame لكل لعبة (مع aggregateRating عند وجود تقييمات)، وBlogPosting لتدوينات
 *   يوميات التطوير، وWebSite للرئيسية، وBreadcrumbList بمسار التنقل الظاهر في الصفحة.
 * - وصف الصفحة ووسوم المشاركة (Open Graph) في الرئيسية والمكتبة والأنظمة والأنواع واليوميات والألعاب
 *   والتدوينات، ورابط canonical لصفحات المكتبة واليوميات (ووردبريس يطبعه للصفحات المفردة فقط).
 * - خريطة الموقع بلا صفحة «حسابي» وبلا صفحات الكُتّاب، ولا فهرسة لصفحات المكتبة الفارغة وأرشيف التواريخ.
 * ما في <head> لا يُطبع إن كانت هناك إضافة SEO تتولاه، عدا بيانات اللعبة المنظمة (لا تكتبها تلك الإضافات).
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Seo {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'head' ), 5 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'sitemap_provider' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_posts' ), 10, 2 );
	}

	public static function has_seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}

	public static function head() {
		if ( is_singular( Post_Types::GAME ) ) {
			self::game_head();
		} elseif ( self::has_seo_plugin() ) {
			return;
		} elseif ( is_singular( 'post' ) ) {
			self::post_head();
		} elseif ( is_front_page() ) {
			self::front_head();
		} elseif ( Query::is_library() ) {
			self::library_head();
		} elseif ( is_home() ) {
			self::devlog_head();
		}
	}

	private static function game_head() {
		$game = Games::get( get_queried_object_id() );
		if ( ! $game ) {
			return;
		}
		$post        = get_post( $game['id'] );
		$description = wp_strip_all_tags( has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( strip_shortcodes( $post->post_content ), 30, '…' ) );
		$cover       = $game['cover_id'] ? (string) wp_get_attachment_image_url( $game['cover_id'], 'large' ) : '';
		$banner      = $game['banner_id'] ? (string) wp_get_attachment_image_url( $game['banner_id'], 'large' ) : '';

		$schema = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'VideoGame',
			'name'                => self::text( $game['title'] ),
			'url'                 => $game['url'],
			'description'         => self::text( $description ),
			// القيمة التي تقبلها Google لنتائج التطبيقات والألعاب («Game» وحدها لا تُقبل).
			'applicationCategory' => 'GameApplication',
			'operatingSystem'     => 'Web browser',
			'isAccessibleForFree' => true,
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
		if ( $cover ) {
			$schema['image'] = $cover;
		}
		$shots = array();
		foreach ( array_slice( (array) $game['screenshots'], 0, 4 ) as $id ) {
			$url = wp_get_attachment_image_url( $id, 'large' );
			if ( $url ) {
				$shots[] = $url;
			}
		}
		if ( $shots ) {
			$schema['screenshot'] = $shots;
		}
		if ( $game['system'] ) {
			$schema['gamePlatform'] = $game['system']['full'];
		}
		if ( $game['genres'] ) {
			$schema['genre'] = array_map( array( __CLASS__, 'text' ), wp_list_pluck( $game['genres'], 'name' ) );
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
		$crumbs = array( self::crumb_home(), self::crumb_library() );
		if ( $game['system'] && $game['system']['link'] ) {
			$crumbs[] = array( $game['system']['name'], $game['system']['link'] );
		}
		$crumbs[] = array( $game['title'], $game['url'] );
		self::breadcrumbs( $crumbs );
		// صورة المشاركة: البانر العريض إن وُجد ببطاقة كبيرة، وإلا الغلاف الطولي ببطاقة صغيرة كي لا يُقص نصفه.
		self::meta_tags(
			array(
				'description'    => $description,
				'og:type'        => 'website',
				'og:title'       => $game['title'],
				'og:description' => $description,
				'og:url'         => $game['url'],
				'og:site_name'   => get_bloginfo( 'name' ),
				'og:locale'      => self::locale(),
				'og:image'       => $banner ? $banner : $cover,
				'og:image:alt'   => ( $banner || $cover ) ? $game['title'] : '',
				'twitter:card'   => $banner ? 'summary_large_image' : 'summary',
			)
		);
	}

	/**
	 * تدوينة من يوميات التطوير: صورتها (أو غلاف أول لعبة فيها) ووصفها عند مشاركتها.
	 */
	private static function post_head() {
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
				'name'  => self::text( $game['title'] ),
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
			'headline'         => self::text( get_the_title( $post ) ),
			'url'              => $url,
			'mainEntityOfPage' => $url,
			'description'      => self::text( $description ),
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

		$crumbs = array( self::crumb_home() );
		$devlog = Devlog::url();
		if ( $devlog ) {
			$crumbs[] = array( get_the_title( (int) get_option( 'page_for_posts' ) ), $devlog );
		}
		$crumbs[] = array( get_the_title( $post ), $url );
		self::breadcrumbs( $crumbs );

		self::meta_tags(
			array(
				'description'            => $description,
				'og:type'                => 'article',
				'og:title'               => get_the_title( $post ),
				'og:description'         => $description,
				'og:url'                 => $url,
				'og:site_name'           => get_bloginfo( 'name' ),
				'og:locale'              => self::locale(),
				'og:image'               => $image,
				'og:image:alt'           => $image ? get_the_title( $post ) : '',
				'article:published_time' => get_the_date( 'c', $post ),
				'twitter:card'           => $image ? 'summary_large_image' : 'summary',
			)
		);
	}

	/**
	 * الرئيسية: اسم الموقع لمحركات البحث (WebSite)، ووصفها وصورة الموقع عند مشاركتها.
	 */
	private static function front_head() {
		$name = get_bloginfo( 'name' );
		// الرئيسية صفحة ثابتة عادة، وووردبريس يطبع رابطها الأساسي؛ حين تعرض آخر المقالات لا يطبعه.
		$url         = is_singular() ? home_url( '/' ) : self::canonical( home_url( '/' ) );
		$description = self::description( 'front', get_bloginfo( 'description' ) );
		self::json_ld(
			array(
				'@context'   => 'https://schema.org',
				'@type'      => 'WebSite',
				'name'       => self::text( $name ),
				'url'        => home_url( '/' ),
				'inLanguage' => get_bloginfo( 'language' ),
			)
		);
		self::meta_tags(
			array(
				'description'    => $description,
				'og:type'        => 'website',
				'og:title'       => $name,
				'og:description' => $description,
				'og:url'         => $url,
				'og:site_name'   => $name,
				'og:locale'      => self::locale(),
				'og:image'       => self::site_image(),
				'og:image:alt'   => $name,
				'twitter:card'   => 'summary',
			)
		);
	}

	/**
	 * المكتبة وصفحات الأنظمة والأنواع. صفحات الفلترة والبحث غير مفهرسة (Query::robots)، فلا رابط أساسي لها
	 * ولا og:url (مشاركتها تبقي فلاترها).
	 */
	private static function library_head() {
		global $wp_query;
		$term = is_tax() ? get_queried_object() : null;
		$term = $term instanceof \WP_Term ? $term : null;
		$base = $term ? get_term_link( $term ) : get_post_type_archive_link( Post_Types::GAME );
		if ( ! is_string( $base ) || '' === $base ) {
			return;
		}
		$site     = get_bloginfo( 'name' );
		$filtered = Query::is_filtered();
		$url      = $filtered ? '' : self::canonical( $base );
		if ( $term ) {
			$default     = '' !== trim( $term->description ) ? $term->description
				/* translators: 1: system or genre, 2: site name */
				: sprintf( __( 'ألعاب %1$s على %2$s تعمل مباشرة في المتصفح، بلا تحميل ولا تثبيت.', 'retrovault-core' ), $term->name, $site );
			$description = self::description( 'term', $default, $term );
		} else {
			/* translators: %s: site name */
			$description = self::description( 'library', sprintf( __( 'ألعاب رترو على %s تعمل مباشرة في المتصفح، بلا تحميل ولا تثبيت.', 'retrovault-core' ), $site ) );
		}
		// صورة المشاركة: غلاف أول لعبة في الصفحة، وإلا أيقونة الموقع.
		$image = '';
		$alt   = $site;
		if ( ! empty( $wp_query->posts ) ) {
			$first = Games::get( $wp_query->posts[0] );
			if ( $first && $first['cover_id'] ) {
				$image = (string) wp_get_attachment_image_url( $first['cover_id'], 'large' );
				$alt   = $first['title'];
			}
		}

		$crumbs = array( self::crumb_home(), self::crumb_library() );
		if ( $term ) {
			$crumbs[] = array( $term->name, $base );
		}
		self::breadcrumbs( $crumbs );
		self::meta_tags(
			array(
				'description'    => $description,
				'og:type'        => 'website',
				'og:title'       => wp_get_document_title(),
				'og:description' => $description,
				'og:url'         => $url,
				'og:site_name'   => $site,
				'og:locale'      => self::locale(),
				'og:image'       => '' !== $image ? $image : self::site_image(),
				'og:image:alt'   => '' !== $image ? $alt : $site,
				'twitter:card'   => 'summary',
			)
		);
	}

	/** صفحة يوميات التطوير (صفحة المقالات). */
	private static function devlog_head() {
		$page = (int) get_option( 'page_for_posts' );
		$base = Devlog::url();
		if ( '' === $base ) {
			return;
		}
		$url         = self::canonical( $base );
		$excerpt     = trim( (string) get_post_field( 'post_excerpt', $page ) );
		$description = self::description(
			'devlog',
			'' !== $excerpt ? $excerpt
				/* translators: %s: site name */
				: sprintf( __( 'يوميات تطوير ألعاب %s: ما الجديد في كل إصدار وكيف صُنع.', 'retrovault-core' ), get_bloginfo( 'name' ) )
		);
		self::breadcrumbs( array( self::crumb_home(), array( get_the_title( $page ), $base ) ) );
		self::meta_tags(
			array(
				'description'    => $description,
				'og:type'        => 'website',
				'og:title'       => wp_get_document_title(),
				'og:description' => $description,
				'og:url'         => $url,
				'og:site_name'   => get_bloginfo( 'name' ),
				'og:locale'      => self::locale(),
				'og:image'       => self::site_image(),
				'og:image:alt'   => get_bloginfo( 'name' ),
				'twitter:card'   => 'summary',
			)
		);
	}

	/**
	 * @param array $robots توجيهات robots.
	 */
	public static function robots( $robots ) {
		global $wp_query;
		// نظام أو نوع لم تُضف له لعبة بعد: صفحة فارغة. لا يظهر في القوائم ولا في خريطة الموقع.
		$empty = Query::is_library() && $wp_query instanceof \WP_Query && 0 === (int) $wp_query->found_posts;
		// أرشيف الأشهر والسنوات يكرر تدوينات اليوميات.
		if ( $empty || ( is_date() && ! self::has_seo_plugin() ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/**
	 * صفحات الكُتّاب تُحوَّل إلى اليوميات (Guard)، فلا مكان لها في خريطة الموقع، وهي تكشف أسماء الدخول.
	 *
	 * @param \WP_Sitemaps_Provider|false $provider المزوّد.
	 * @param string                      $name     اسمه.
	 */
	public static function sitemap_provider( $provider, $name ) {
		return ( 'users' === $name && Guard::hides_usernames() ) ? false : $provider;
	}

	/**
	 * صفحة «حسابي» خاصة بالأعضاء، والزائر يُحوَّل منها إلى الدخول.
	 *
	 * @param array  $args      وسائط الاستعلام.
	 * @param string $post_type نوع المحتوى.
	 */
	public static function sitemap_posts( $args, $post_type ) {
		$account = Account::page_id();
		if ( 'page' === $post_type && $account ) {
			$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), array( $account ) );
		}
		return $args;
	}

	/**
	 * @param string       $context front|library|term|devlog.
	 * @param string       $default الوصف الافتراضي.
	 * @param \WP_Term|null $term   التصنيف في صفحات الأنظمة والأنواع.
	 */
	private static function description( $context, $default, $term = null ) {
		/**
		 * وصف الصفحة لمحركات البحث والمشاركة. القالب يضع هنا نص الترحيب في الرئيسية مثلاً.
		 *
		 * @param string        $description الوصف.
		 * @param string        $context     front|library|term|devlog.
		 * @param \WP_Term|null $term        التصنيف في صفحات الأنظمة والأنواع.
		 */
		$text = (string) apply_filters( 'retrovault_meta_description', (string) $default, $context, $term );
		return wp_html_excerpt( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) ), 200, '…' );
	}

	/**
	 * رابط الصفحة الأساسي بلا معاملات، مع رقم الصفحة في النتائج المقسّمة.
	 *
	 * @param string $base رابط الصفحة الأولى.
	 * @return string الرابط المطبوع.
	 */
	private static function canonical( $base ) {
		global $wp_rewrite;
		$paged = (int) get_query_var( 'paged' );
		$url   = $base;
		if ( $paged > 1 ) {
			$url = $wp_rewrite->using_permalinks()
				? user_trailingslashit( trailingslashit( $base ) . $wp_rewrite->pagination_base . '/' . $paged, 'paged' )
				: add_query_arg( 'paged', $paged, $base );
		}
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $url ) );
		return $url;
	}

	/**
	 * مسار التنقل كما يظهر في الصفحة (الرئيسية ← المكتبة ← النظام ← اللعبة).
	 *
	 * @param array<int,array{0:string,1:string}> $items الاسم والرابط.
	 */
	private static function breadcrumbs( $items ) {
		$list = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => self::text( $item[0] ),
				'item'     => $item[1],
			);
		}
		self::json_ld(
			array(
				'@context'        => 'https://schema.org',
				'@type'           => 'BreadcrumbList',
				'itemListElement' => $list,
			)
		);
	}

	private static function crumb_home() {
		return array( __( 'الرئيسية', 'retrovault-core' ), home_url( '/' ) );
	}

	private static function crumb_library() {
		return array( __( 'المكتبة', 'retrovault-core' ), (string) get_post_type_archive_link( Post_Types::GAME ) );
	}

	/** صورة الموقع للمشاركة: أيقونة الموقع، وإلا أيقونة تطبيق الويب الافتراضية. */
	private static function site_image() {
		return has_site_icon() ? (string) get_site_icon_url( 512 ) : RETROVAULT_URL . 'assets/icon-512.png';
	}

	/** og:locale يطلب لغة ومنطقة (ar_AR)، وووردبريس العربي لغته «ar» وحدها. */
	private static function locale() {
		$locale = get_locale();
		if ( 'ar' === $locale ) {
			return 'ar_AR';
		}
		return false !== strpos( $locale, '_' ) ? $locale : '';
	}

	/**
	 * نص صافٍ للبيانات المنظمة: عناوين ووردبريس فيها كيانات HTML (&#8211;) لا تُفك داخل JSON.
	 *
	 * @param string $text النص.
	 */
	private static function text( $text ) {
		return html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
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
