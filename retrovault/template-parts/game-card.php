<?php
/**
 * بطاقة لعبة على شكل خرطوشة.
 *
 * @package RetroVault
 *
 * @var array $args post (اختياري)، heading (h2|h3).
 */

defined( 'ABSPATH' ) || exit;

$rvt_post = isset( $args['post'] ) ? get_post( $args['post'] ) : get_post();
$rvt_game = $rvt_post ? rv_get_game( $rvt_post ) : null;
if ( ! $rvt_game ) {
	return;
}
$rvt_sys     = $rvt_game['system'];
$rvt_heading = ( isset( $args['heading'] ) && 'h2' === $args['heading'] ) ? 'h2' : 'h3';
$rvt_genre   = $rvt_game['genres'] ? $rvt_game['genres'][0]['name'] : '';
?>
<article class="cart" style="--sys:<?php echo esc_attr( $rvt_sys ? $rvt_sys['color'] : '#5a5864' ); ?>">
	<a class="cart__link" href="<?php echo esc_url( $rvt_game['url'] ); ?>">
		<div class="cart__shell">
			<div class="cart__label">
				<?php
				if ( $rvt_game['cover_id'] ) {
					echo wp_get_attachment_image(
						$rvt_game['cover_id'],
						'rvt-cover',
						false,
						array(
							'class'   => 'cart__art',
							'alt'     => '',
							'loading' => 'lazy',
							'sizes'   => '(max-width: 600px) 44vw, 200px',
						)
					);
				} else {
					echo '<div class="cart__art cart__art--blank" aria-hidden="true"><span>' . esc_html( $rvt_sys ? $rvt_sys['short'] : '?' ) . '</span></div>';
				}
				?>
				<?php if ( $rvt_sys ) : ?>
					<span class="cart__sys"><?php echo esc_html( $rvt_sys['short'] ); ?></span>
				<?php endif; ?>
				<?php if ( 'released' !== $rvt_game['status'] ) : ?>
					<span class="cart__flag"><?php echo esc_html( $rvt_game['status_label'] ); ?></span>
				<?php endif; ?>
			</div>
			<<?php echo esc_html( $rvt_heading ); ?> class="cart__title"><?php echo esc_html( $rvt_game['title'] ); ?></<?php echo esc_html( $rvt_heading ); ?>>
			<p class="cart__meta">
				<?php if ( $rvt_game['rating']['count'] ) : ?>
					<?php echo rvt_stars( $rvt_game['rating']['average'], array( 'size' => 'sm' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="cart__avg"><?php echo esc_html( number_format_i18n( $rvt_game['rating']['average'], 1 ) ); ?></span>
				<?php elseif ( $rvt_genre ) : ?>
					<span><?php echo esc_html( $rvt_genre ); ?></span>
				<?php endif; ?>
			</p>
		</div>
	</a>
</article>
