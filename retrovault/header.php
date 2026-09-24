<?php
/**
 * رأس الصفحة.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link screen-reader-text" href="#main"><?php esc_html_e( 'تخطَّ إلى المحتوى', 'retrovault' ); ?></a>

<header class="site-header">
	<div class="shell site-header__inner">
		<?php rvt_brand(); ?>

		<button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="site-panel">
			<?php echo rvt_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span><?php esc_html_e( 'القائمة', 'retrovault' ); ?></span>
		</button>

		<div class="site-header__panel" id="site-panel">
			<nav class="site-nav" aria-label="<?php esc_attr_e( 'القائمة الرئيسية', 'retrovault' ); ?>">
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'primary',
						'container'      => false,
						'depth'          => 1,
						'fallback_cb'    => 'rvt_menu_fallback',
					)
				);
				?>
			</nav>
			<?php rvt_search_form( 'header-search' ); ?>
			<?php rvt_account_menu(); ?>
		</div>
	</div>
</header>

<main id="main" class="site-main">
