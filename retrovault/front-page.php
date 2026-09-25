<?php
/**
 * الصفحة الرئيسية.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( ! rvt_has_core() ) {
	get_template_part( 'template-parts/notice-core' );
	get_footer();
	return;
}

$rvt_totals  = rvt_totals_sentence();
$rvt_library = rvt_library_url();
?>
<section class="hero">
	<div class="shell hero__inner">
		<div class="hero__intro">
			<h1 class="hero__title"><?php echo esc_html( rvt_mod( 'rvt_hero_title' ) ); ?></h1>
			<p class="hero__text"><?php echo esc_html( rvt_mod( 'rvt_hero_text' ) ); ?></p>
			<div class="hero__actions">
				<a class="btn btn--a" href="<?php echo esc_url( $rvt_library ); ?>"><?php esc_html_e( 'تصفّح المكتبة', 'retrovault' ); ?></a>
				<a class="btn btn--pill" href="<?php echo esc_url( rv_random_url() ); ?>" rel="nofollow"><?php echo rvt_icon( 'dice' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'لعبة عشوائية', 'retrovault' ); ?></a>
			</div>
			<?php if ( $rvt_totals ) : ?>
				<p class="hero__note"><?php echo esc_html( $rvt_totals ); ?></p>
			<?php endif; ?>
		</div>
		<div class="hero__tv">
			<?php get_template_part( 'template-parts/multicart', null, array( 'games' => rvt_menu_games( 8 ) ) ); ?>
		</div>
	</div>
</section>

<?php if ( rv_get_systems( true ) ) : ?>
	<section class="section" aria-labelledby="home-systems">
		<div class="shell">
			<h2 class="section__title" id="home-systems"><?php esc_html_e( 'الأنظمة', 'retrovault' ); ?></h2>
			<?php get_template_part( 'template-parts/system-slots' ); ?>
		</div>
	</section>
<?php endif; ?>

<?php
$rvt_new = rv_query_games( array( 'number' => 6 ) );
get_template_part(
	'template-parts/shelf',
	null,
	array(
		'id'        => 'shelf-new',
		'title'     => __( 'وصلت حديثاً', 'retrovault' ),
		'query'     => $rvt_new,
		'link'      => $rvt_library,
		'link_text' => __( 'كل الألعاب', 'retrovault' ),
	)
);

if ( rvt_mod( 'rvt_show_rated' ) ) {
	get_template_part(
		'template-parts/shelf',
		null,
		array(
			'id'        => 'shelf-rated',
			'title'     => __( 'الأعلى تقييماً', 'retrovault' ),
			'query'     => rv_query_games(
				array(
					'sort'       => 'rating',
					'number'     => 6,
					'rated_only' => true,
				)
			),
			'link'      => add_query_arg( 'sort', 'rating', $rvt_library ),
			'link_text' => __( 'الترتيب الكامل', 'retrovault' ),
		)
	);
}

if ( rvt_mod( 'rvt_show_played' ) ) {
	// «رائجة هذا الأسبوع» (آخر 7 أيام)، وإن لم يُلعب شيء هذا الأسبوع فـ«الأكثر لعباً» على الإطلاق.
	$rvt_trending = rv_query_games(
		array(
			'sort'     => 'trending',
			'number'   => 6,
			'trending' => true,
		)
	);
	get_template_part(
		'template-parts/shelf',
		null,
		$rvt_trending->have_posts()
			? array(
				'id'        => 'shelf-trending',
				'title'     => __( 'رائجة هذا الأسبوع', 'retrovault' ),
				'query'     => $rvt_trending,
				'link'      => add_query_arg( 'sort', 'trending', $rvt_library ),
				'link_text' => __( 'الترتيب الكامل', 'retrovault' ),
			)
			: array(
				'id'        => 'shelf-played',
				'title'     => __( 'الأكثر لعباً', 'retrovault' ),
				'query'     => rv_query_games(
					array(
						'sort'        => 'plays',
						'number'      => 6,
						'played_only' => true,
					)
				),
				'link'      => add_query_arg( 'sort', 'plays', $rvt_library ),
				'link_text' => __( 'الترتيب الكامل', 'retrovault' ),
			)
	);
}

if ( rvt_mod( 'rvt_show_updated' ) ) {
	// ألعاب عُدّلت بعد نشرها (لا مجرد جديدة)، وليست في «وصلت حديثاً» حتى لا يتكرر الرف نفسه.
	get_template_part(
		'template-parts/shelf',
		null,
		array(
			'id'        => 'shelf-updated',
			'title'     => __( 'حُدّثت مؤخراً', 'retrovault' ),
			'query'     => rv_query_games(
				array(
					'sort'         => 'updated',
					'number'       => 6,
					'updated_only' => true,
					'exclude'      => wp_list_pluck( $rvt_new->posts, 'ID' ),
				)
			),
			'link'      => add_query_arg( 'sort', 'updated', $rvt_library ),
			'link_text' => __( 'كل التحديثات', 'retrovault' ),
		)
	);
}
?>

<?php
if ( rvt_mod( 'rvt_show_devlog' ) ) :
	$rvt_log = rv_devlog_latest( 3 );
	if ( $rvt_log->have_posts() ) :
		?>
		<section class="section" aria-labelledby="home-devlog">
			<div class="shell">
				<div class="shelf__head">
					<h2 class="section__title" id="home-devlog"><?php esc_html_e( 'من يوميات التطوير', 'retrovault' ); ?></h2>
					<?php if ( rv_devlog_url() ) : ?>
						<a class="shelf__more" href="<?php echo esc_url( rv_devlog_url() ); ?>"><?php esc_html_e( 'كل اليوميات', 'retrovault' ); ?></a>
					<?php endif; ?>
				</div>
				<div class="log-grid">
					<?php
					foreach ( $rvt_log->posts as $rvt_post ) {
						rvt_log_card( $rvt_post );
					}
					?>
				</div>
			</div>
		</section>
		<?php
	endif;
endif;
?>

<?php if ( ! is_user_logged_in() && get_option( 'users_can_register' ) ) : ?>
	<section class="section">
		<div class="shell">
			<div class="join">
				<div>
					<h2 class="join__title"><?php esc_html_e( 'قيّم الألعاب وشارك رأيك', 'retrovault' ); ?></h2>
					<p>
						<?php
						echo esc_html(
							rvt_has_core() && rv_cloud_saves_enabled()
								? __( 'الحساب مجاني ويُنشأ في دقيقة: قيّم الألعاب وعلّق عليها، واحفظ تقدّمك لتكمل من أي جهاز، وتابع جديد ألعابك المفضلة.', 'retrovault' )
								: __( 'الحساب مجاني ويُنشأ في دقيقة: قيّم الألعاب بالنجوم وعلّق عليها، وتابع جديد ألعابك المفضلة.', 'retrovault' )
						);
						?>
					</p>
				</div>
				<div class="join__actions">
					<a class="btn btn--a" href="<?php echo esc_url( rvt_register_url() ); ?>"><?php esc_html_e( 'أنشئ حساباً', 'retrovault' ); ?></a>
					<a class="join__login" href="<?php echo esc_url( wp_login_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'لدي حساب', 'retrovault' ); ?></a>
				</div>
			</div>
		</div>
	</section>
<?php endif; ?>

<?php
get_footer();
