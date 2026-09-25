<?php
/**
 * RetroVault — إعداد القالب.
 *
 * القالب مسؤول عن الشكل فقط. البيانات والمشغّل والتقييمات في إضافة RetroVault Core.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

define( 'RVT_VERSION', '1.11.0' );

require_once get_template_directory() . '/inc/icons.php';
require_once get_template_directory() . '/inc/customizer.php';
require_once get_template_directory() . '/inc/template-tags.php';

/** هل إضافة النواة مفعّلة؟ */
function rvt_has_core() {
	return function_exists( 'rv_get_game' );
}

if ( ! isset( $content_width ) ) {
	$content_width = 760;
}

add_action( 'after_setup_theme', 'rvt_setup' );
function rvt_setup() {
	load_theme_textdomain( 'retrovault', get_template_directory() . '/languages' );

	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 64,
			'width'       => 240,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);
	// يخبر الإضافة أن القالب يتولى تنسيق المشغّل بالكامل.
	add_theme_support( 'retrovault' );

	register_nav_menus(
		array(
			'primary' => __( 'القائمة الرئيسية', 'retrovault' ),
			'footer'  => __( 'قائمة التذييل', 'retrovault' ),
		)
	);

	add_image_size( 'rvt-cover', 360, 480, true );
	add_image_size( 'rvt-cover-lg', 600, 800, true );
}

add_action( 'wp_enqueue_scripts', 'rvt_assets' );
function rvt_assets() {
	global $wp_locale;

	wp_enqueue_style( 'rvt-fonts', rvt_fonts_url(), array(), rvt_asset_version( 'assets/fonts/fonts.css' ) );
	wp_enqueue_style( 'rvt-main', get_theme_file_uri( 'assets/css/main.css' ), array( 'rvt-fonts' ), rvt_asset_version( 'assets/css/main.css' ) );

	wp_enqueue_script(
		'rvt-main',
		get_theme_file_uri( 'assets/js/main.js' ),
		array(),
		rvt_asset_version( 'assets/js/main.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	wp_localize_script(
		'rvt-main',
		'RVT',
		array(
			'rest'     => esc_url_raw( rest_url( 'retrovault/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'decimal'  => isset( $wp_locale->number_format['decimal_point'] ) ? $wp_locale->number_format['decimal_point'] : '.',
			'cookies'  => COOKIEPATH,
			'i18n'     => array(
				'saving'      => __( 'جارٍ الحفظ…', 'retrovault' ),
				'saved'       => __( 'حُفظ تقييمك.', 'retrovault' ),
				'removed'     => __( 'حُذف تقييمك.', 'retrovault' ),
				'error'       => __( 'تعذّر الحفظ. تحقق من اتصالك وحاول مرة أخرى.', 'retrovault' ),
				'copied'      => __( 'نُسخ رابط اللعبة.', 'retrovault' ),
				'loadFail'    => __( 'تعذّر تحميل النتائج.', 'retrovault' ),
				'gameOver'    => __( 'انتهت اللعبة', 'retrovault' ),
				'ratingForms' => rvt_count_forms( 'ratings' ),
				'favOn'       => __( 'في مفضلتك', 'retrovault' ),
				'favOff'      => __( 'أضف للمفضلة', 'retrovault' ),
				'favAdded'    => __( 'أُضيفت إلى مفضلتك، وستصلك تحديثاتها.', 'retrovault' ),
				'notifyOn'    => __( 'ستصلك رسائل التحديثات.', 'retrovault' ),
				'notifyOff'   => __( 'أُوقفت رسائل التحديثات.', 'retrovault' ),
				'favRemoved'  => __( 'أُزيلت من مفضلتك.', 'retrovault' ),
				'delConfirm'  => __( 'حذف كل حفظات الحالة لهذه اللعبة من حسابك؟ حفظ اللعبة الداخلي لا يُحذف.', 'retrovault' ),
				'delSlot'     => __( 'حذف هذا الحفظ من حسابك؟ لا يمكن التراجع عن ذلك.', 'retrovault' ),
				'delDone'     => __( 'حُذف الحفظ.', 'retrovault' ),
				'posting'     => __( 'جارٍ النشر…', 'retrovault' ),
				'posted'      => __( 'نُشر تعليقك.', 'retrovault' ),
				'pending'     => __( 'وصل تعليقك، ويظهر للجميع بعد المراجعة.', 'retrovault' ),
				'welcome'     => __( 'أهلاً بك! أُنشئ حسابك وأنت الآن مسجّل الدخول.', 'retrovault' ),
			),
		)
	);

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}

/** الخطوط مستضافة مع القالب: Handjet (بكسل، للشاشات فقط) + IBM Plex Sans Arabic (كل ما عداها). */
function rvt_fonts_url() {
	return get_theme_file_uri( 'assets/fonts/fonts.css' );
}

/**
 * @param string $path مسار الملف داخل القالب.
 */
function rvt_asset_version( $path ) {
	$file = get_theme_file_path( $path );
	return file_exists( $file ) ? RVT_VERSION . '.' . filemtime( $file ) : RVT_VERSION;
}

/*
 * خطوط أعلى كل صفحة تُطلب مع رأس الصفحة بدل انتظار ملفات CSS، فتصل قبل أول رسم غالباً. بدونها تظهر
 * الصفحة لحظةً بخط الجهاز (أعرض) ثم تنكمش عند وصول الخط فتتحرك القائمة والأزرار ويُعاد لفّ النص.
 * كل أوزان النص الثلاثة بالعربية واللاتينية (اللاتينية فيها الأرقام والرموز، ومنها يُحسب عرض ch في
 * max-width)، وHandjet لنصوص الشاشات. بعد أوراق الأنماط (الأولوية 9) لتُطلب CSS أولاً.
 */
add_action(
	'wp_head',
	static function () {
		$fonts = array( 'handjet-arabic', 'handjet-latin' );
		foreach ( array( 'arabic', 'latin' ) as $subset ) {
			foreach ( array( 400, 500, 700 ) as $weight ) {
				$fonts[] = "ibm-plex-sans-arabic-{$subset}-{$weight}";
			}
		}
		foreach ( $fonts as $font ) {
			printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( get_theme_file_uri( 'assets/fonts/' . $font . '.woff2' ) ) );
		}
	},
	9
);

/*
 * كلاس js على <html> قبل أول رسم: ما يطويه السكربت (فلاتر المكتبة على الجوال) يظهر مطوياً من البداية
 * بدل أن يُطوى أمام الزائر فتقفز الصفحة. بدون JavaScript يبقى كل شيء ظاهراً ويعمل كنموذج عادي.
 */
add_action(
	'wp_head',
	static function () {
		wp_print_inline_script_tag( "document.documentElement.classList.add('js');" );
	},
	1
);

add_filter(
	'body_class',
	static function ( $classes ) {
		$classes[] = rvt_has_core() ? 'rvt-core' : 'rvt-no-core';
		return $classes;
	}
);

add_filter( 'excerpt_length', static function () { return 26; } );
add_filter( 'excerpt_more', static function () { return '…'; } );

/* تنبيه عند غياب الإضافة */
add_action(
	'admin_notices',
	static function () {
		if ( rvt_has_core() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'قالب RetroVault يحتاج إضافة «RetroVault Core» لعرض الألعاب والمشغّل والتقييمات. ارفعها وفعّلها من صفحة الإضافات.', 'retrovault' ) . '</p></div>';
	}
);

/* صفحة تسجيل الدخول والتسجيل بنفس هوية الموقع */
add_action(
	'login_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'rvt-fonts', rvt_fonts_url(), array(), rvt_asset_version( 'assets/fonts/fonts.css' ) );
		wp_enqueue_style( 'rvt-login', get_theme_file_uri( 'assets/css/login.css' ), array( 'rvt-fonts' ), rvt_asset_version( 'assets/css/login.css' ) );
	}
);
add_filter( 'login_headerurl', static function () { return home_url( '/' ); } );
add_filter( 'login_headertext', static function () { return get_bloginfo( 'name' ); } );

/*
 * نشر التعليق بدون إعادة تحميل (main.js): رقم التعليق الجديد يُضاف لعنوان العودة، لأن fetch لا يرى
 * ما بعد # فيه. الإرسال العادي لا يتأثر.
 */
add_filter(
	'comment_post_redirect',
	static function ( $location, $comment ) {
		if ( ! empty( $_POST['rvt_ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- علامة فقط؛ ووردبريس تحقق من التعليق ونشره قبل هذا.
			$location = add_query_arg( 'rvt_new', (int) $comment->comment_ID, $location );
		}
		return $location;
	},
	99,
	2
);

/**
 * زر «رد» في المحتوى الذي تعليقاته للأعضاء فقط يقود الزائر لتسجيل الدخول.
 */
add_filter(
	'comment_reply_link',
	static function ( $link, $args, $comment, $post ) {
		$members_only = function_exists( 'rv_members_only_comments' ) ? rv_members_only_comments( $post ) : ( $post && 'rv_game' === $post->post_type );
		if ( ! is_user_logged_in() && $members_only ) {
			return sprintf(
				'<a rel="nofollow" class="comment-reply-login" href="%s">%s</a>',
				esc_url( wp_login_url( get_permalink( $post ) . '#comments' ) ),
				esc_html__( 'سجّل دخولك للرد', 'retrovault' )
			);
		}
		return $link;
	},
	10,
	4
);
