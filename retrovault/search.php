<?php
/**
 * نتائج بحث ووردبريس العام (?s=). بحث الألعاب نفسه يتم في صفحة المكتبة.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

get_header();

$rvt_games = array();
$rvt_posts = array();
while ( have_posts() ) {
	the_post();
	if ( 'rv_game' === get_post_type() ) {
		$rvt_games[] = get_post();
	} else {
		$rvt_posts[] = get_post();
	}
}
rewind_posts();
?>
<div class="shell page">
	<header class="page__head">
		<h1 class="page__title">
			<?php
			/* translators: %s: search query */
			echo esc_html( sprintf( __( 'نتائج البحث عن «%s»', 'retrovault' ), get_search_query() ) );
			?>
		</h1>
		<?php rvt_search_form( 'search-form--page' ); ?>
	</header>

	<?php if ( $rvt_games ) : ?>
		<h2 class="section__title"><?php esc_html_e( 'ألعاب', 'retrovault' ); ?></h2>
		<div class="cart-grid">
			<?php
			foreach ( $rvt_games as $rvt_post ) {
				get_template_part( 'template-parts/game-card', null, array( 'post' => $rvt_post ) );
			}
			?>
		</div>
	<?php endif; ?>

	<?php if ( $rvt_posts ) : ?>
		<h2 class="section__title"><?php esc_html_e( 'مقالات وصفحات', 'retrovault' ); ?></h2>
		<ul class="search-list">
			<?php foreach ( $rvt_posts as $rvt_post ) : ?>
				<li>
					<a href="<?php echo esc_url( get_permalink( $rvt_post ) ); ?>"><?php echo esc_html( get_the_title( $rvt_post ) ); ?></a>
					<p><?php echo esc_html( get_the_excerpt( $rvt_post ) ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! $rvt_games && ! $rvt_posts ) : ?>
		<div class="empty">
			<p class="empty__title"><?php esc_html_e( 'لا نتائج لهذا البحث.', 'retrovault' ); ?></p>
			<p><?php esc_html_e( 'جرّب كلمة أقصر أو تصفّح المكتبة حسب النظام.', 'retrovault' ); ?></p>
		</div>
	<?php endif; ?>

	<?php rvt_pagination(); ?>
</div>
<?php
get_footer();
