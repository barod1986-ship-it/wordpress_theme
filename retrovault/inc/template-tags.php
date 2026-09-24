<?php
/**
 * دوال العرض المشتركة في القالب.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * العدّ بالعربية: صفر / مفرد / مثنى / 3–10 / 11–99 (وما بعد المئة)
 * ---------------------------------------------------------------------- */

/**
 * @param string $type games|systems|plays|ratings|comments|stars.
 * @return string[] [zero, one, two, few, many]
 */
function rvt_count_forms( $type ) {
	switch ( $type ) {
		case 'systems':
			return array( __( 'لا توجد أنظمة', 'retrovault' ), __( 'نظام واحد', 'retrovault' ), __( 'نظامين', 'retrovault' ), __( '%s أنظمة', 'retrovault' ), __( '%s نظاماً', 'retrovault' ) );
		case 'plays':
			return array( __( 'لم تُلعب بعد', 'retrovault' ), __( 'مرة واحدة', 'retrovault' ), __( 'مرتين', 'retrovault' ), __( '%s مرات', 'retrovault' ), __( '%s مرة', 'retrovault' ) );
		case 'ratings':
			return array( __( 'لا تقييمات بعد', 'retrovault' ), __( 'تقييم واحد', 'retrovault' ), __( 'تقييمان', 'retrovault' ), __( '%s تقييمات', 'retrovault' ), __( '%s تقييماً', 'retrovault' ) );
		case 'stars':
			return array( __( 'بلا نجوم', 'retrovault' ), __( 'نجمة واحدة', 'retrovault' ), __( 'نجمتان', 'retrovault' ), __( '%s نجوم', 'retrovault' ), __( '%s نجمة', 'retrovault' ) );
		case 'comments':
			return array( __( 'لا تعليقات بعد', 'retrovault' ), __( 'تعليق واحد', 'retrovault' ), __( 'تعليقان', 'retrovault' ), __( '%s تعليقات', 'retrovault' ), __( '%s تعليقاً', 'retrovault' ) );
		default:
			return array( __( 'لا توجد ألعاب', 'retrovault' ), __( 'لعبة واحدة', 'retrovault' ), __( 'لعبتان', 'retrovault' ), __( '%s ألعاب', 'retrovault' ), __( '%s لعبة', 'retrovault' ) );
	}
}

/**
 * @param int    $n    العدد.
 * @param string $type نوع المعدود.
 */
function rvt_count( $n, $type ) {
	$forms = rvt_count_forms( $type );
	$n     = (int) $n;
	$mod   = $n % 100;
	if ( 0 === $n ) {
		$form = $forms[0];
	} elseif ( 1 === $n ) {
		$form = $forms[1];
	} elseif ( 2 === $n ) {
		$form = $forms[2];
	} elseif ( $mod >= 3 && $mod <= 10 ) {
		$form = $forms[3];
	} else {
		$form = $forms[4];
	}
	return sprintf( $form, number_format_i18n( $n ) );
}

/* -------------------------------------------------------------------------
 * الترويسة
 * ---------------------------------------------------------------------- */

function rvt_brand() {
	if ( has_custom_logo() ) {
		the_custom_logo();
		return;
	}
	printf(
		'<a class="brand" href="%1$s" rel="home"><span class="brand__led" aria-hidden="true"></span><span class="brand__name">%2$s</span></a>',
		esc_url( home_url( '/' ) ),
		esc_html( get_bloginfo( 'name' ) )
	);
}

function rvt_current_url() {
	global $wp;
	return home_url( ( $wp && $wp->request ) ? user_trailingslashit( $wp->request ) : '/' );
}

/**
 * نماذج GET تُسقط الاستعلام من رابط action؛ نحوّله لحقول مخفية (مهم مع الروابط غير الجميلة).
 *
 * @param string $url الرابط.
 * @return array{0:string,1:string} [الرابط بلا استعلام، حقول مخفية]
 */
function rvt_form_action( $url ) {
	$query  = (string) wp_parse_url( $url, PHP_URL_QUERY );
	$base   = strtok( $url, '?' );
	$hidden = '';
	if ( '' !== $query ) {
		wp_parse_str( $query, $vars );
		foreach ( $vars as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$hidden .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $key ), esc_attr( $value ) );
			}
		}
	}
	return array( $base, $hidden );
}

/** رابط المكتبة (أو الرئيسية إن غابت الإضافة). */
function rvt_library_url() {
	$link = rvt_has_core() ? get_post_type_archive_link( 'rv_game' ) : '';
	return $link ? $link : home_url( '/' );
}

/**
 * نموذج البحث: يبحث في الألعاب عبر صفحة المكتبة.
 *
 * @param string $class كلاس إضافي.
 * @param string $label اسم النموذج لقارئ الشاشة (يلزم اسم مختلف لكل نموذج بحث في الصفحة نفسها).
 */
function rvt_search_form( $class = '', $label = '' ) {
	static $n = 0;
	++$n;
	$core = rvt_has_core();
	$name = $core ? 'q' : 's';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$value = $core ? ( isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '' ) : get_search_query();
	list( $action, $hidden ) = rvt_form_action( rvt_library_url() );
	?>
	<form role="search" aria-label="<?php echo esc_attr( '' !== $label ? $label : __( 'ابحث عن لعبة', 'retrovault' ) ); ?>" method="get" class="search-form <?php echo esc_attr( $class ); ?>" action="<?php echo esc_url( $action ); ?>">
		<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- مُهرَّب في rvt_form_action. ?>
		<label class="screen-reader-text" for="search-<?php echo (int) $n; ?>"><?php esc_html_e( 'ابحث عن لعبة', 'retrovault' ); ?></label>
		<input type="search" id="search-<?php echo (int) $n; ?>" class="input search-form__input" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php esc_attr_e( 'ابحث عن لعبة', 'retrovault' ); ?>">
		<button type="submit" class="search-form__btn"><?php echo rvt_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="screen-reader-text"><?php esc_html_e( 'بحث', 'retrovault' ); ?></span></button>
	</form>
	<?php
}

function rvt_account_menu() {
	if ( ! is_user_logged_in() ) {
		echo '<div class="account account--guest">';
		printf( '<a class="account__login" href="%s">%s</a>', esc_url( wp_login_url( rvt_current_url() ) ), esc_html__( 'دخول', 'retrovault' ) );
		if ( get_option( 'users_can_register' ) ) {
			printf( '<a class="btn btn--pill btn--sm" href="%s">%s</a>', esc_url( wp_registration_url() ), esc_html__( 'حساب جديد', 'retrovault' ) );
		}
		echo '</div>';
		return;
	}
	$user   = wp_get_current_user();
	$unseen = ( rvt_has_core() && function_exists( 'rv_unseen_notices_count' ) && ! rvt_is_account_page() ) ? rv_unseen_notices_count( $user->ID ) : 0;
	?>
	<details class="account" data-dropdown>
		<summary class="account__toggle">
			<?php echo get_avatar( $user->ID, 32, '', '' ); ?>
			<span class="account__name"><?php echo esc_html( $user->display_name ); ?></span>
			<?php if ( $unseen ) : ?>
				<span class="account__badge">
					<?php echo esc_html( number_format_i18n( $unseen ) ); ?>
					<span class="screen-reader-text"><?php esc_html_e( 'تحديثات جديدة في ألعابك', 'retrovault' ); ?></span>
				</span>
			<?php endif; ?>
		</summary>
		<div class="account__menu">
			<?php if ( rvt_has_core() && rv_account_url() ) : ?>
				<a href="<?php echo esc_url( rv_account_url() . ( $unseen ? '#updates' : '' ) ); ?>">
					<?php esc_html_e( 'حسابي', 'retrovault' ); ?>
					<?php if ( $unseen ) : ?>
						<span class="account__count">
							<?php
							/* translators: %s: count */
							echo esc_html( sprintf( __( '%s جديد', 'retrovault' ), number_format_i18n( $unseen ) ) );
							?>
						</span>
					<?php endif; ?>
				</a>
			<?php endif; ?>
			<?php if ( current_user_can( 'edit_posts' ) ) : ?>
				<a href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'لوحة التحكم', 'retrovault' ); ?></a>
				<?php if ( rvt_has_core() ) : ?>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=rv_game' ) ); ?>"><?php esc_html_e( 'إضافة لعبة', 'retrovault' ); ?></a>
				<?php endif; ?>
			<?php endif; ?>
			<a href="<?php echo esc_url( get_edit_profile_url( $user->ID ) ); ?>"><?php esc_html_e( 'ملفي الشخصي', 'retrovault' ); ?></a>
			<a href="<?php echo esc_url( wp_logout_url( rvt_current_url() ) ); ?>"><?php esc_html_e( 'تسجيل الخروج', 'retrovault' ); ?></a>
		</div>
	</details>
	<?php
}

/** القائمة الافتراضية إن لم تُنشأ قائمة من «مظهر ← القوائم». */
function rvt_menu_fallback() {
	$items = array();
	if ( rvt_has_core() ) {
		$items[] = array( rvt_library_url(), __( 'المكتبة', 'retrovault' ), is_post_type_archive( 'rv_game' ) || is_tax( array( 'rv_system', 'rv_genre' ) ) || is_singular( 'rv_game' ) );
		$items[] = array( rv_random_url(), __( 'لعبة عشوائية', 'retrovault' ), false );
	}
	$blog = (int) get_option( 'page_for_posts' );
	if ( $blog ) {
		$items[] = array( get_permalink( $blog ), get_the_title( $blog ), is_home() );
	}
	echo '<ul class="menu">';
	foreach ( $items as $item ) {
		printf(
			'<li class="menu-item%1$s"><a href="%2$s"%3$s>%4$s</a></li>',
			$item[2] ? ' current-menu-item' : '',
			esc_url( $item[0] ),
			$item[2] ? ' aria-current="page"' : '',
			esc_html( $item[1] )
		);
	}
	echo '</ul>';
}

/* -------------------------------------------------------------------------
 * عناصر الألعاب
 * ---------------------------------------------------------------------- */

/**
 * نجوم بكسل مع تعبئة جزئية (مثلاً 4.3 من 5).
 *
 * @param float $average المتوسط.
 * @param array $args    size (sm|md|lg)، live (يتحدث بعد التقييم).
 */
function rvt_stars( $average, $args = array() ) {
	$args  = wp_parse_args(
		$args,
		array(
			'size' => 'md',
			'live' => false,
		)
	);
	$avg   = max( 0, min( 5, (float) $average ) );
	$label = $avg > 0
		/* translators: %s: average rating */
		? sprintf( __( 'التقييم %s من 5', 'retrovault' ), number_format_i18n( $avg, 1 ) )
		: __( 'لا تقييمات بعد', 'retrovault' );
	// صف النجوم الخمس في SVG واحد (خمس نسخ من رمز النجمة) بدل خمس صور منفصلة.
	$row = '<svg class="stars__svg" viewBox="0 0 53 9" aria-hidden="true">';
	for ( $i = 0; $i < 5; $i++ ) {
		$row .= '<use href="#rvt-i-star" x="' . ( $i * 11 ) . '" width="9" height="9"/>';
	}
	$row .= '</svg>';

	return sprintf(
		'<span class="stars stars--%1$s" style="--fill:%2$s%%" role="img" aria-label="%3$s"%4$s><span class="stars__bg" aria-hidden="true">%5$s</span><span class="stars__fg" aria-hidden="true">%5$s</span></span>',
		esc_attr( $args['size'] ),
		esc_attr( round( $avg / 5 * 100, 1 ) ),
		esc_attr( $label ),
		$args['live'] ? ' data-rating-stars' : '',
		$row
	);
}

/**
 * صورة مرفق بقيمة sizes تناسب مكانها. تُضاف sizes فقط مع srcset (وحدها خطأ في HTML).
 *
 * @param int    $id    رقم الصورة.
 * @param string $size  الحجم المسجّل.
 * @param array  $attr  سمات الوسم.
 * @param string $sizes قيمة sizes.
 * @return string
 */
function rvt_image( $id, $size, $attr, $sizes ) {
	if ( wp_get_attachment_image_srcset( $id, $size ) ) {
		$attr['sizes'] = $sizes;
	}
	return wp_get_attachment_image( $id, $size, false, $attr );
}

/**
 * @param array|null $system بيانات النظام.
 * @param bool       $link   رابط لصفحة النظام.
 */
function rvt_system_chip( $system, $link = true ) {
	if ( ! $system ) {
		return '';
	}
	$attrs = sprintf( 'class="chip chip--sys" style="--sys:%1$s" title="%2$s"', esc_attr( $system['color'] ), esc_attr( $system['full'] ) );
	if ( $link && $system['link'] ) {
		return sprintf( '<a %1$s href="%2$s">%3$s</a>', $attrs, esc_url( $system['link'] ), esc_html( $system['short'] ) );
	}
	return sprintf( '<span %1$s>%2$s</span>', $attrs, esc_html( $system['short'] ) );
}

/**
 * @param array $game بيانات اللعبة.
 */
function rvt_status_chip( $game ) {
	return sprintf( '<span class="chip chip--status chip--%1$s">%2$s</span>', esc_attr( $game['status'] ), esc_html( $game['status_label'] ) );
}

/**
 * عدد التقييمات لكل درجة، من 5 إلى 1.
 *
 * @param int $game_id رقم اللعبة.
 * @return array<int,int>
 */
function rvt_rating_counts( $game_id ) {
	$counts = array_fill_keys( array( 5, 4, 3, 2, 1 ), 0 );
	foreach ( rv_game_ratings( $game_id ) as $rating ) {
		if ( isset( $counts[ $rating ] ) ) {
			++$counts[ $rating ];
		}
	}
	return $counts;
}

/**
 * صندوق التقييم: المتوسط وتوزيع التقييمات للجميع، والنجوم التفاعلية للأعضاء.
 *
 * @param array $game بيانات اللعبة.
 */
function rvt_rating_box( $game ) {
	$r      = $game['rating'];
	$user   = is_user_logged_in() ? rv_user_rating( $game['id'] ) : 0;
	$counts = rvt_rating_counts( $game['id'] );
	$total  = array_sum( $counts );
	?>
	<div class="rate" data-rating-box data-game="<?php echo esc_attr( $game['id'] ); ?>" data-user="<?php echo (int) $user; ?>">
		<h2 class="panel__title"><?php esc_html_e( 'التقييم', 'retrovault' ); ?></h2>
		<div class="rate__summary">
			<p class="rate__score">
				<strong class="rate__avg" data-rating-avg><?php echo $r['count'] ? esc_html( number_format_i18n( $r['average'], 1 ) ) : '—'; ?></strong>
				<?php echo rvt_stars( $r['average'], array( 'live' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="rate__count" data-rating-count><?php echo esc_html( rvt_count( $r['count'], 'ratings' ) ); ?></span>
			</p>
			<ul class="rate__bars" aria-label="<?php esc_attr_e( 'توزيع التقييمات', 'retrovault' ); ?>"<?php echo $total ? '' : ' hidden'; ?>>
				<?php foreach ( $counts as $stars => $n ) : ?>
					<li class="rate__bar" data-rating-bar="<?php echo (int) $stars; ?>" data-n="<?php echo (int) $n; ?>" style="--share:<?php echo esc_attr( sprintf( '%.1F', $total ? $n / $total * 100 : 0 ) ); ?>%">
						<span class="rate__bar-label">
							<span aria-hidden="true"><?php echo esc_html( number_format_i18n( $stars ) ); ?></span>
							<?php echo rvt_icon( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="screen-reader-text"><?php echo esc_html( rvt_count( $stars, 'stars' ) ); ?>:</span>
						</span>
						<span class="rate__bar-track" aria-hidden="true"></span>
						<span class="rate__bar-n" data-rating-n><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php if ( is_user_logged_in() ) : ?>
			<fieldset class="rate__input">
				<legend><?php esc_html_e( 'تقييمك', 'retrovault' ); ?></legend>
				<div class="star-input">
					<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
						<input type="radio" id="rate-<?php echo (int) $i; ?>" name="rv-rating" value="<?php echo (int) $i; ?>" <?php checked( $user, $i ); ?>>
						<label for="rate-<?php echo (int) $i; ?>">
							<?php echo rvt_icon( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="screen-reader-text">
								<?php
								/* translators: %s: stars */
								echo esc_html( sprintf( __( '%s من 5', 'retrovault' ), number_format_i18n( $i ) ) );
								?>
							</span>
						</label>
					<?php endfor; ?>
				</div>
				<div class="rate__foot">
					<p class="rate__msg" data-rating-msg role="status" aria-live="polite"></p>
					<button type="button" class="rate__clear" data-rating-clear <?php echo $user ? '' : 'hidden'; ?>><?php esc_html_e( 'حذف تقييمي', 'retrovault' ); ?></button>
				</div>
			</fieldset>
		<?php else : ?>
			<p class="rate__guest">
				<?php esc_html_e( 'التقييم للأعضاء المسجّلين.', 'retrovault' ); ?>
				<a href="<?php echo esc_url( wp_login_url( get_permalink( $game['id'] ) . '#rate' ) ); ?>"><?php esc_html_e( 'سجّل دخولك', 'retrovault' ); ?></a>
				<?php if ( get_option( 'users_can_register' ) ) : ?>
					<?php esc_html_e( 'أو', 'retrovault' ); ?>
					<a href="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'أنشئ حساباً مجانياً', 'retrovault' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/** الألعاب المعروضة في قائمة «الملتي كارت»: المميزة أولاً ثم الأحدث. */
function rvt_menu_games( $count = 8 ) {
	$games = rv_query_games(
		array(
			'featured' => true,
			'number'   => $count,
		)
	)->posts;
	if ( count( $games ) < $count ) {
		$more  = rv_query_games(
			array(
				'number'  => $count - count( $games ),
				'exclude' => wp_list_pluck( $games, 'ID' ),
			)
		)->posts;
		$games = array_merge( $games, $more );
	}
	return $games;
}

/** جملة الإجماليات تحت أزرار الواجهة. */
function rvt_totals_sentence() {
	$t = rv_totals();
	if ( ! $t['games'] ) {
		return '';
	}
	/* translators: 1: games count, 2: systems count */
	$text = sprintf( __( '%1$s على %2$s', 'retrovault' ), rvt_count( $t['games'], 'games' ), rvt_count( $t['systems'], 'systems' ) );
	if ( $t['plays'] ) {
		/* translators: %s: plays count */
		$text .= sprintf( __( '، لُعبت %s حتى الآن', 'retrovault' ), rvt_count( $t['plays'], 'plays' ) );
	}
	return $text . '.';
}

/**
 * عنوان صفحة المكتبة ووصفها حسب السياق (الكل / نظام / نوع).
 *
 * @return array{title:string,desc:string,system:array|null}
 */
function rvt_library_heading() {
	$title  = __( 'مكتبة الألعاب', 'retrovault' );
	$desc   = '';
	$system = null;
	$term   = get_queried_object();

	if ( $term instanceof WP_Term && in_array( $term->taxonomy, array( 'rv_system', 'rv_genre' ), true ) ) {
		if ( 'rv_system' === $term->taxonomy ) {
			$system = rv_get_system( $term );
			$title  = $term->name;
			$desc   = $term->description;
			if ( ! $desc && $system['maker'] && $system['year'] ) {
				/* translators: 1: maker, 2: year */
				$desc = sprintf( __( 'جهاز من %1$s صدر عام %2$s.', 'retrovault' ), $system['maker'], $system['year'] );
			}
		} else {
			/* translators: %s: genre */
			$title = sprintf( __( 'ألعاب %s', 'retrovault' ), $term->name );
			$desc  = $term->description;
		}
	}
	return array(
		'title'  => $title,
		'desc'   => $desc,
		'system' => $system,
	);
}

function rvt_breadcrumbs() {
	$items = array( array( __( 'الرئيسية', 'retrovault' ), home_url( '/' ) ) );

	if ( is_singular( 'rv_game' ) ) {
		$items[] = array( __( 'المكتبة', 'retrovault' ), rvt_library_url() );
		$game    = rv_get_game();
		if ( $game && $game['system'] ) {
			$items[] = array( $game['system']['name'], $game['system']['link'] );
		}
		$items[] = array( get_the_title(), '' );
	} elseif ( is_tax( array( 'rv_system', 'rv_genre' ) ) ) {
		$items[] = array( __( 'المكتبة', 'retrovault' ), rvt_library_url() );
		$items[] = array( single_term_title( '', false ), '' );
	} elseif ( is_post_type_archive( 'rv_game' ) ) {
		$items[] = array( __( 'المكتبة', 'retrovault' ), '' );
	} elseif ( is_singular( 'post' ) && rvt_has_core() && rv_devlog_url() ) {
		$items[] = array( get_the_title( (int) get_option( 'page_for_posts' ) ), rv_devlog_url() );
		$items[] = array( get_the_title(), '' );
	} else {
		return;
	}

	echo '<nav class="crumbs" aria-label="' . esc_attr__( 'مسار التنقل', 'retrovault' ) . '"><ol>';
	$last = count( $items ) - 1;
	foreach ( $items as $i => $item ) {
		if ( $i === $last || '' === $item[1] ) {
			echo '<li><span aria-current="page">' . esc_html( $item[0] ) . '</span></li>';
		} else {
			echo '<li><a href="' . esc_url( $item[1] ) . '">' . esc_html( $item[0] ) . '</a></li>';
		}
	}
	echo '</ol></nav>';
}

function rvt_pagination() {
	$links = paginate_links(
		array(
			'mid_size'  => 1,
			'prev_text' => rvt_icon( 'prev', 'rvt-icon--flip' ) . '<span class="screen-reader-text">' . esc_html__( 'الصفحة السابقة', 'retrovault' ) . '</span>',
			'next_text' => rvt_icon( 'next', 'rvt-icon--flip' ) . '<span class="screen-reader-text">' . esc_html__( 'الصفحة التالية', 'retrovault' ) . '</span>',
		)
	);
	if ( $links ) {
		echo '<nav class="pagination" aria-label="' . esc_attr__( 'صفحات النتائج', 'retrovault' ) . '">' . $links . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/* -------------------------------------------------------------------------
 * التعليقات
 * ---------------------------------------------------------------------- */

/**
 * @param WP_Comment $comment التعليق.
 * @param array      $args    الإعدادات.
 * @param int        $depth   العمق.
 */
function rvt_comment( $comment, $args, $depth ) {
	$is_game = rvt_has_core() && 'rv_game' === get_post_type( $comment->comment_post_ID );
	$ratings = $is_game ? rv_game_ratings( (int) $comment->comment_post_ID ) : array();
	$rating  = ( $comment->user_id && isset( $ratings[ (int) $comment->user_id ] ) ) ? $ratings[ (int) $comment->user_id ] : 0;
	$is_dev  = $is_game && rv_is_developer_comment( $comment );
	?>
	<li id="comment-<?php comment_ID(); ?>" <?php comment_class( empty( $args['has_children'] ) ? '' : 'parent', $comment ); ?>>
		<article class="comment-body">
			<div class="comment-avatar"><?php echo get_avatar( $comment, 48, '', '' ); ?></div>
			<div class="comment-main">
				<header class="comment-meta">
					<strong class="comment-author"><?php comment_author(); ?></strong>
					<?php if ( $is_dev ) : ?>
						<span class="badge-dev"><?php esc_html_e( 'المطوّر', 'retrovault' ); ?></span>
					<?php endif; ?>
					<?php
					if ( $rating ) {
						echo rvt_stars( $rating, array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
					<a class="comment-date" href="<?php echo esc_url( get_comment_link( $comment ) ); ?>">
						<time datetime="<?php echo esc_attr( get_comment_date( 'c', $comment ) ); ?>">
							<?php
							/* translators: %s: human time diff */
							echo esc_html( sprintf( __( 'منذ %s', 'retrovault' ), human_time_diff( (int) get_comment_date( 'U', $comment ), time() ) ) );
							?>
						</time>
					</a>
				</header>
				<?php if ( '0' === $comment->comment_approved ) : ?>
					<p class="comment-awaiting"><?php esc_html_e( 'تعليقك بانتظار المراجعة.', 'retrovault' ); ?></p>
				<?php endif; ?>
				<div class="comment-content"><?php comment_text(); ?></div>
				<?php
				comment_reply_link(
					array_merge(
						$args,
						array(
							'depth'     => $depth,
							'max_depth' => $args['max_depth'],
							'before'    => '<div class="comment-reply">',
							'after'     => '</div>',
						)
					)
				);
				?>
			</div>
		</article>
	<?php
}

/* -------------------------------------------------------------------------
 * ميزات الأعضاء
 * ---------------------------------------------------------------------- */

/** هل نحن في صفحة «حسابي»؟ */
function rvt_is_account_page() {
	return rvt_has_core() && function_exists( 'rv_is_account_page' ) && rv_is_account_page();
}

/**
 * زر المفضلة (للزائر رابط لتسجيل الدخول).
 *
 * @param array $game بيانات اللعبة.
 */
function rvt_favorite_button( $game ) {
	if ( ! is_user_logged_in() ) {
		printf(
			'<a class="btn btn--ghost btn--sm fav" href="%1$s">%2$s%3$s</a>',
			esc_url( wp_login_url( get_permalink( $game['id'] ) ) ),
			rvt_icon( 'heart' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'أضف للمفضلة', 'retrovault' )
		);
		return;
	}
	$on = rv_is_favorite( $game['id'] );
	printf(
		'<button type="button" class="btn btn--ghost btn--sm fav" data-favorite data-game="%1$d" aria-pressed="%2$s">%3$s<span data-fav-label>%4$s</span></button>',
		(int) $game['id'],
		$on ? 'true' : 'false',
		rvt_icon( 'heart' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html( $on ? __( 'في مفضلتك', 'retrovault' ) : __( 'أضف للمفضلة', 'retrovault' ) )
	);
}

/**
 * سطر الإرشاد تحت المشغّل: للزائر دعوة للتسجيل ليُحفظ تقدّمه في حسابه.
 *
 * @param array $game بيانات اللعبة.
 */
function rvt_stage_tip( $game ) {
	$base = __( 'بعد بدء اللعبة انقر داخل الشاشة لتفعيل لوحة المفاتيح.', 'retrovault' );
	if ( ! rv_cloud_saves_enabled() ) {
		echo esc_html( $base . ' ' . __( 'الحفظ يتم من قائمة المحاكي ويُخزَّن في متصفحك على هذا الجهاز.', 'retrovault' ) );
		return;
	}
	if ( is_user_logged_in() ) {
		$tip = rv_sram_sync_enabled()
			? __( 'زر «حفظ الحالة» في شريط المحاكي يحفظ تقدّمك في حسابك، وحفظ اللعبة نفسها يُزامَن تلقائياً، فتكمل من أي جهاز.', 'retrovault' )
			: __( 'زر «حفظ الحالة» في شريط المحاكي يحفظ تقدّمك في حسابك، فتكمل من أي جهاز.', 'retrovault' );
		echo esc_html( $base . ' ' . $tip );
		return;
	}
	echo esc_html( $base . ' ' . __( 'الحفظ الآن في متصفحك فقط؛', 'retrovault' ) ) . ' ';
	printf(
		'<a href="%1$s">%2$s</a>',
		esc_url( wp_login_url( get_permalink( $game['id'] ) ) ),
		esc_html__( 'سجّل دخولك ليُحفظ تقدّمك في حسابك وتكمل من أي جهاز.', 'retrovault' )
	);
}

/**
 * محتوى صفحة «حسابي» (تستدعيه الإضافة عبر الخطاف retrovault_account).
 */
function rvt_account_screen() {
	get_template_part( 'template-parts/account' );
}
add_action( 'retrovault_account', 'rvt_account_screen' );

/* -------------------------------------------------------------------------
 * يوميات التطوير
 * ---------------------------------------------------------------------- */

/**
 * شارات الألعاب المرتبطة بتدوينة.
 *
 * @param int $post_id رقم التدوينة.
 * @return string
 */
function rvt_post_game_chips( $post_id ) {
	if ( ! rvt_has_core() ) {
		return '';
	}
	$out = '';
	foreach ( rv_post_games( $post_id ) as $game_id ) {
		$game = rv_get_game( $game_id );
		if ( ! $game ) {
			continue;
		}
		$out .= sprintf(
			'<a class="game-chip" href="%1$s" style="--sys:%2$s"><span class="game-chip__sys">%3$s</span>%4$s</a>',
			esc_url( $game['url'] ),
			esc_attr( $game['system'] ? $game['system']['color'] : '#5a5864' ),
			esc_html( $game['system'] ? $game['system']['short'] : '' ),
			esc_html( $game['title'] )
		);
	}
	return $out ? '<p class="game-chips">' . $out . '</p>' : '';
}

/**
 * لون نقطة التدوينة في الخط الزمني = لون نظام أول لعبة مرتبطة.
 *
 * @param int $post_id رقم التدوينة.
 */
function rvt_post_color( $post_id ) {
	if ( rvt_has_core() ) {
		foreach ( rv_post_games( $post_id ) as $game_id ) {
			$game = rv_get_game( $game_id );
			if ( $game && $game['system'] ) {
				return $game['system']['color'];
			}
		}
	}
	return '';
}

/**
 * بطاقة تدوينة.
 *
 * @param WP_Post|int $post التدوينة.
 * @param array       $args heading, excerpt, chips, image.
 */
function rvt_log_card( $post, $args = array() ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return;
	}
	$args    = wp_parse_args(
		$args,
		array(
			'heading' => 'h3',
			'excerpt' => true,
			'chips'   => true,
			'image'   => false,
		)
	);
	$heading = in_array( $args['heading'], array( 'h2', 'h3' ), true ) ? $args['heading'] : 'h3';
	?>
	<article class="log-card">
		<?php if ( $args['image'] && has_post_thumbnail( $post ) ) : ?>
			<a class="log-card__media" href="<?php echo esc_url( get_permalink( $post ) ); ?>" tabindex="-1" aria-hidden="true"><?php echo get_the_post_thumbnail( $post, 'medium_large' ); ?></a>
		<?php endif; ?>
		<div class="log-card__body">
			<p class="log-card__date"><time datetime="<?php echo esc_attr( get_the_date( 'c', $post ) ); ?>"><?php echo esc_html( get_the_date( '', $post ) ); ?></time></p>
			<<?php echo esc_html( $heading ); ?> class="log-card__title"><a href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></<?php echo esc_html( $heading ); ?>>
			<?php if ( $args['excerpt'] ) : ?>
				<p class="log-card__excerpt"><?php echo esc_html( get_the_excerpt( $post ) ); ?></p>
			<?php endif; ?>
			<?php
			if ( $args['chips'] ) {
				echo rvt_post_game_chips( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
	</article>
	<?php
}
