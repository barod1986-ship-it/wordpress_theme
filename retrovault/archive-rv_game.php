<?php
/**
 * مكتبة الألعاب: /games/ — وتُستخدم أيضاً لـ /system/{x}/ و /genre/{x}/.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

get_header();

$rvt_filters = rv_current_filters();
$rvt_head    = rvt_library_heading();
$rvt_system  = $rvt_head['system'];
?>
<div class="shell library">
	<header class="library__head"<?php echo $rvt_system ? ' style="--sys:' . esc_attr( $rvt_system['color'] ) . '"' : ''; ?>>
		<?php rvt_breadcrumbs(); ?>
		<h1 class="library__title">
			<?php if ( $rvt_system ) : ?>
				<span class="library__sys"><?php echo esc_html( $rvt_system['short'] ); ?></span>
			<?php endif; ?>
			<?php echo esc_html( $rvt_head['title'] ); ?>
		</h1>
		<?php if ( $rvt_head['desc'] ) : ?>
			<p class="library__desc"><?php echo esc_html( wp_strip_all_tags( $rvt_head['desc'] ) ); ?></p>
		<?php endif; ?>
	</header>

	<?php
	get_template_part(
		'template-parts/system-slots',
		null,
		array(
			'current' => $rvt_filters['system'],
			'compact' => true,
		)
	);
	get_template_part( 'template-parts/filters', null, array( 'filters' => $rvt_filters ) );
	get_template_part( 'template-parts/results' );
	?>
</div>
<?php
get_footer();
