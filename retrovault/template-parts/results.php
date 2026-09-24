<?php
/**
 * نتائج المكتبة (يستبدلها السكربت عند تغيير الفلاتر).
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

global $wp_query;
$rvt_total = (int) $wp_query->found_posts;
$rvt_f     = rv_current_filters();
$rvt_all   = wp_count_posts( 'rv_game' );
$rvt_empty = empty( $rvt_all->publish );
?>
<div id="rv-results" class="results" aria-live="polite">
	<?php if ( have_posts() ) : ?>
		<p class="results__count">
			<?php
			echo esc_html( rvt_count( $rvt_total, 'games' ) );
			if ( '' !== $rvt_f['q'] ) {
				/* translators: %s: search term */
				echo ' ' . esc_html( sprintf( __( 'تطابق «%s»', 'retrovault' ), $rvt_f['q'] ) );
			}
			?>
		</p>
		<div class="cart-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/game-card', null, array( 'heading' => 'h2' ) );
			endwhile;
			?>
		</div>
		<?php rvt_pagination(); ?>
	<?php elseif ( $rvt_empty ) : ?>
		<div class="empty">
			<p class="empty__title"><?php esc_html_e( 'المكتبة فارغة حالياً.', 'retrovault' ); ?></p>
			<?php if ( current_user_can( 'edit_posts' ) ) : ?>
				<a class="btn btn--a" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=rv_game' ) ); ?>"><?php esc_html_e( 'أضف أول لعبة', 'retrovault' ); ?></a>
			<?php else : ?>
				<p><?php esc_html_e( 'تُضاف الألعاب قريباً، عُد لاحقاً.', 'retrovault' ); ?></p>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="empty">
			<p class="empty__title"><?php esc_html_e( 'لا توجد ألعاب تطابق هذه الفلاتر.', 'retrovault' ); ?></p>
			<p><?php esc_html_e( 'جرّب نظاماً أو نوعاً آخر، أو ابحث بكلمة أقصر.', 'retrovault' ); ?></p>
			<a class="btn btn--pill" href="<?php echo esc_url( rvt_library_url() ); ?>"><?php esc_html_e( 'إزالة الفلاتر', 'retrovault' ); ?></a>
		</div>
	<?php endif; ?>
</div>
