<?php
/**
 * تظهر عندما تكون إضافة RetroVault Core غير مفعّلة.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="shell page">
	<div class="empty">
		<p class="empty__title"><?php esc_html_e( 'المكتبة غير جاهزة بعد.', 'retrovault' ); ?></p>
		<?php if ( current_user_can( 'activate_plugins' ) ) : ?>
			<p><?php esc_html_e( 'فعّل إضافة «RetroVault Core» ليظهر محتوى المكتبة والمشغّل.', 'retrovault' ); ?></p>
			<a class="btn btn--a" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'صفحة الإضافات', 'retrovault' ); ?></a>
		<?php endif; ?>
	</div>
</div>
