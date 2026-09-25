<?php
/**
 * مقالة مفردة.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class( 'shell page' ); ?>>
		<?php rvt_breadcrumbs(); ?>
		<header class="page__head">
			<h1 class="page__title"><?php the_title(); ?></h1>
			<div class="post-meta">
				<p class="page__meta"><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time></p>
				<button type="button" class="btn btn--ghost btn--sm" data-share data-title="<?php echo esc_attr( wp_strip_all_tags( get_the_title() ) ); ?>" data-url="<?php echo esc_url( get_permalink() ); ?>" data-copied="<?php esc_attr_e( 'نُسخ رابط التدوينة.', 'retrovault' ); ?>">
					<?php echo rvt_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'مشاركة', 'retrovault' ); ?>
				</button>
			</div>
		</header>
		<?php if ( has_post_thumbnail() ) : ?>
			<figure class="page__media"><?php the_post_thumbnail( 'large' ); ?></figure>
		<?php endif; ?>
		<div class="entry"><?php the_content(); ?></div>
		<?php
		$rvt_games = rvt_has_core() ? rv_post_games( get_the_ID() ) : array();
		if ( $rvt_games ) :
			?>
			<section class="post-games" aria-labelledby="post-games-title">
				<h2 class="section__title" id="post-games-title"><?php esc_html_e( 'الألعاب في هذه التدوينة', 'retrovault' ); ?></h2>
				<div class="cart-grid">
					<?php
					foreach ( $rvt_games as $rvt_game_id ) {
						get_template_part( 'template-parts/game-card', null, array( 'post' => $rvt_game_id ) );
					}
					?>
				</div>
			</section>
			<?php
		endif;
		wp_link_pages();
		the_post_navigation(
			array(
				'prev_text'          => '<span class="post-nav__label">' . esc_html__( 'تدوينة أقدم', 'retrovault' ) . '</span> <span class="post-nav__title">%title</span>',
				'next_text'          => '<span class="post-nav__label">' . esc_html__( 'تدوينة أحدث', 'retrovault' ) . '</span> <span class="post-nav__title">%title</span>',
				'screen_reader_text' => __( 'تدوينات أخرى', 'retrovault' ),
				'aria_label'         => __( 'تدوينات أخرى', 'retrovault' ),
			)
		);
		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}
		?>
	</article>
	<?php
endwhile;

get_footer();
