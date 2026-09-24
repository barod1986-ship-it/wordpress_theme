<?php
/**
 * صفحة ثابتة.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<?php if ( rvt_is_account_page() ) : ?>
		<div <?php post_class( 'shell page page--account' ); ?>>
			<?php the_content(); ?>
		</div>
		<?php continue; ?>
	<?php endif; ?>
	<article id="post-<?php the_ID(); ?>" <?php post_class( 'shell page' ); ?>>
		<header class="page__head">
			<h1 class="page__title"><?php the_title(); ?></h1>
		</header>
		<div class="entry"><?php the_content(); ?></div>
		<?php
		wp_link_pages();
		if ( comments_open() || get_comments_number() ) {
			comments_template();
		}
		?>
	</article>
	<?php
endwhile;

get_footer();
