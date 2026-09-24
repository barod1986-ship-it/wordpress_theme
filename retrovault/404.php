<?php
/**
 * الصفحة غير موجودة: شاشة «انتهت اللعبة» مع عدّاد «متابعة؟».
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="shell gameover">
	<div class="tv">
		<div class="tv__screen gameover__screen">
			<p class="gameover__title"><?php esc_html_e( 'الصفحة غير موجودة', 'retrovault' ); ?></p>
			<p class="gameover__continue">
				<?php esc_html_e( 'متابعة؟', 'retrovault' ); ?>
				<span class="gameover__count" data-countdown data-end="<?php esc_attr_e( 'انتهت اللعبة', 'retrovault' ); ?>">9</span>
			</p>
		</div>
		<div class="tv__foot" aria-hidden="true"><span class="tv__led"></span><span class="tv__brand">404</span><span class="tv__knobs"><i></i><i></i></span></div>
	</div>
	<div class="gameover__actions">
		<p><?php esc_html_e( 'الرابط الذي فتحته غير موجود، ربما تغيّر اسم اللعبة أو حُذفت الصفحة.', 'retrovault' ); ?></p>
		<div class="hero__actions">
			<a class="btn btn--a" href="<?php echo esc_url( rvt_library_url() ); ?>"><?php esc_html_e( 'تصفّح المكتبة', 'retrovault' ); ?></a>
			<a class="btn btn--pill" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'الصفحة الرئيسية', 'retrovault' ); ?></a>
		</div>
		<?php rvt_search_form( 'search-form--page' ); ?>
	</div>
</div>
<?php
get_footer();
