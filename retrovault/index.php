<?php
/**
 * القالب الاحتياطي: المدونة وأرشيف المقالات (مثلاً يوميات التطوير).
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="shell page">
	<header class="page__head">
		<h1 class="page__title">
			<?php
			if ( is_home() && ! is_front_page() ) {
				single_post_title();
			} elseif ( is_archive() ) {
				echo wp_kses_post( get_the_archive_title() );
			} else {
				esc_html_e( 'يوميات التطوير', 'retrovault' );
			}
			?>
		</h1>
		<?php if ( is_home() ) : ?>
			<p class="page__meta"><?php esc_html_e( 'ملاحظات وتحديثات عن الألعاب أثناء تطويرها.', 'retrovault' ); ?></p>
		<?php endif; ?>
	</header>

	<?php if ( have_posts() ) : ?>
		<ol class="timeline">
			<?php
			while ( have_posts() ) :
				the_post();
				$rvt_dot = rvt_post_color( get_the_ID() );
				?>
				<li class="timeline__item"<?php echo $rvt_dot ? ' style="--dot:' . esc_attr( $rvt_dot ) . '"' : ''; ?>>
					<?php
					rvt_log_card(
						get_post(),
						array(
							'heading' => 'h2',
							'image'   => true,
						)
					);
					?>
				</li>
			<?php endwhile; ?>
		</ol>
		<?php rvt_pagination(); ?>
	<?php else : ?>
		<div class="empty"><p class="empty__title"><?php esc_html_e( 'لا توجد مقالات بعد.', 'retrovault' ); ?></p></div>
	<?php endif; ?>
</div>
<?php
get_footer();
