<?php
/**
 * رف ألعاب أفقي في الرئيسية.
 *
 * @package RetroVault
 *
 * @var array $args title, query (WP_Query), link, link_text, id.
 */

defined( 'ABSPATH' ) || exit;

$rvt_query = isset( $args['query'] ) ? $args['query'] : null;
if ( ! $rvt_query instanceof WP_Query || ! $rvt_query->have_posts() ) {
	return;
}
$rvt_id = isset( $args['id'] ) ? sanitize_html_class( $args['id'] ) : 'shelf-' . wp_rand( 100, 999 );
?>
<section class="shelf" aria-labelledby="<?php echo esc_attr( $rvt_id ); ?>">
	<div class="shell">
		<div class="shelf__head">
			<h2 class="shelf__title" id="<?php echo esc_attr( $rvt_id ); ?>"><?php echo esc_html( $args['title'] ); ?></h2>
			<?php if ( ! empty( $args['link'] ) ) : ?>
				<a class="shelf__more" href="<?php echo esc_url( $args['link'] ); ?>"><?php echo esc_html( $args['link_text'] ); ?></a>
			<?php endif; ?>
		</div>
		<div class="shelf__track">
			<?php
			foreach ( $rvt_query->posts as $rvt_post ) {
				get_template_part( 'template-parts/game-card', null, array( 'post' => $rvt_post ) );
			}
			?>
		</div>
	</div>
</section>
