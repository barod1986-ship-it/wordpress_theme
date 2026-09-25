<?php
/**
 * بطاقة لعبة على شكل خرطوشة.
 *
 * @package RetroVault
 *
 * @var array $args post (اختياري)، heading (h2|h3)، eager (بطاقات أعلى الصفحة: الغلاف بلا تحميل مؤجل).
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
$rvt_rating  = $rvt_game['rating']['count'] ? number_format_i18n( $rvt_game['rating']['average'], 1 ) : '';
?>
<article class="cart" style="--sys:<?php echo esc_attr( $rvt_sys ? $rvt_sys['color'] : '#5a5864' ); ?>">
	<a class="cart__link" href="<?php echo esc_url( $rvt_game['url'] ); ?>">
		<div class="cart__shell">
			<div class="cart__label">
				<?php
				if ( $rvt_game['cover_id'] ) {
					echo rvt_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						$rvt_game['cover_id'],
						'rvt-cover',
						array(
							'class'   => 'cart__art',
							'alt'     => '',
							'loading' => empty( $args['eager'] ) ? 'lazy' : false,
						),
						rvt_card_sizes()
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
				<?php if ( $rvt_genre ) : ?>
					<span class="cart__genre"><?php echo esc_html( $rvt_genre ); ?></span>
				<?php endif; ?>
				<?php if ( $rvt_rating ) : ?>
					<span class="cart__score" role="img" aria-label="<?php /* translators: %s: average rating */ echo esc_attr( sprintf( __( 'التقييم %s من 5', 'retrovault' ), $rvt_rating ) ); ?>">
						<?php echo rvt_icon( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span aria-hidden="true"><?php echo esc_html( $rvt_rating ); ?></span>
					</span>
				<?php endif; ?>
			</p>
		</div>
	</a>
</article>
