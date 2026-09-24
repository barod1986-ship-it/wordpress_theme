<?php
/**
 * منافذ الأنظمة: لكل جهاز «منفذ خرطوشة» بلونه.
 *
 * @package RetroVault
 *
 * @var array $args current (slug النظام الحالي)، compact (للمكتبة).
 */

defined( 'ABSPATH' ) || exit;

$rvt_systems = rv_get_systems( true );
if ( ! $rvt_systems ) {
	return;
}
$rvt_current = isset( $args['current'] ) ? (string) $args['current'] : '';
$rvt_compact = ! empty( $args['compact'] );
?>
<nav class="slots<?php echo $rvt_compact ? ' slots--compact' : ''; ?>" aria-label="<?php esc_attr_e( 'تصفّح حسب النظام', 'retrovault' ); ?>">
	<?php if ( $rvt_compact ) : ?>
		<a class="slot slot--all" href="<?php echo esc_url( rvt_library_url() ); ?>" <?php echo '' === $rvt_current ? 'aria-current="page"' : ''; ?>>
			<span class="slot__short"><?php esc_html_e( 'الكل', 'retrovault' ); ?></span>
		</a>
	<?php endif; ?>
	<?php foreach ( $rvt_systems as $rvt_system ) : ?>
		<a class="slot" href="<?php echo esc_url( $rvt_system['link'] ); ?>" style="--sys:<?php echo esc_attr( $rvt_system['color'] ); ?>" <?php echo $rvt_current === $rvt_system['slug'] ? 'aria-current="page"' : ''; ?>>
			<span class="slot__short"><?php echo esc_html( $rvt_system['short'] ); ?></span>
			<?php if ( ! $rvt_compact ) : ?>
				<span class="slot__name"><?php echo esc_html( $rvt_system['name'] ); ?></span>
				<span class="slot__count"><?php echo esc_html( rvt_count( $rvt_system['count'], 'games' ) ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>
