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
			<p class="page__meta"><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time></p>
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
				'prev_text' => '<span class="screen-reader-text">' . esc_html__( 'المقالة السابقة:', 'retrovault' ) . '</span> %title',
				'next_text' => '<span class="screen-reader-text">' . esc_html__( 'المقالة التالية:', 'retrovault' ) . '</span> %title',
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
