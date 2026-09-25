<?php
/**
 * تذييل الصفحة.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

$rvt_systems = rvt_has_core() ? rv_get_systems( true ) : array();
?>
</main>

<footer class="site-footer">
	<div class="shell">
		<div class="site-footer__grid">
			<div class="site-footer__about">
				<?php rvt_brand(); ?>
				<p><?php echo esc_html( rvt_mod( 'rvt_footer_text' ) ); ?></p>
				<?php if ( rvt_has_core() && function_exists( 'rv_pwa_enabled' ) && rv_pwa_enabled() ) : ?>
					<button type="button" class="btn btn--pill btn--sm site-footer__install" data-rv-install hidden>
						<?php echo rvt_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php esc_html_e( 'ثبّت الموقع كتطبيق', 'retrovault' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<?php if ( $rvt_systems ) : ?>
				<nav class="site-footer__col" aria-labelledby="footer-systems">
					<h2 id="footer-systems"><?php esc_html_e( 'الأنظمة', 'retrovault' ); ?></h2>
					<ul<?php echo count( $rvt_systems ) > 5 ? ' class="site-footer__list--cols"' : ''; ?>>
						<?php foreach ( $rvt_systems as $rvt_system ) : ?>
							<li><a href="<?php echo esc_url( $rvt_system['link'] ); ?>"><?php echo esc_html( $rvt_system['name'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>

			<nav class="site-footer__col" aria-labelledby="footer-links">
				<h2 id="footer-links"><?php esc_html_e( 'روابط', 'retrovault' ); ?></h2>
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'footer',
						'container'      => false,
						'depth'          => 1,
						'fallback_cb'    => 'rvt_menu_fallback',
					)
				);
				?>
			</nav>
		</div>

		<div class="site-footer__base">
			<p>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
			<p>
				<?php
				printf(
					/* translators: %s: EmulatorJS link */
					esc_html__( 'تعمل الألعاب داخل المتصفح عبر %s المفتوح المصدر.', 'retrovault' ),
					'<a href="https://emulatorjs.org" rel="noopener">EmulatorJS</a>'
				);
				?>
			</p>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
