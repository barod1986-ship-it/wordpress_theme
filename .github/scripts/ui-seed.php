<?php
/** Fixtures and measurements for the disposable WordPress interface checks only. */
wp_set_current_user( 1 );
update_option( 'blogname', 'ريتروفولت' );
update_option( 'blogdescription', 'مكتبة ألعاب الرترو' );
update_option( 'WPLANG', 'ar' );
$settings = wp_parse_args( get_option( \RetroVault\Settings::OPTION, array() ), \RetroVault\Settings::defaults() );
$settings['pwa'] = 0;
$settings['per_page'] = 12;
update_option( \RetroVault\Settings::OPTION, $settings );
$original = get_page_by_path( 'pixel-quest', OBJECT, 'rv_game' );
$cover = get_post_thumbnail_id( $original );
$rom = get_post_meta( $original->ID, '_rv_rom_id', true );
$titles = array( 'مغامرة الفارس: رحلة عبر العوالم السبعة', 'سباق النجوم — Star Racer DX', 'حكايات القلعة القديمة', 'رحلة إلى الكوكب المجهول' );
$systems = array( 'nes', 'gba', 'snes', 'ps1' );
$covers = array();
foreach ( array( 'pixel-quest', 'star-racer', 'dungeon-tales', 'void-shooter' ) as $slug ) {
	$p = get_page_by_path( $slug, OBJECT, 'rv_game' );
	$covers[] = get_post_thumbnail_id( $p );
}
for ( $i = 0; $i < 28; $i++ ) {
	$id = wp_insert_post( array( 'post_type' => 'rv_game', 'post_status' => 'publish', 'post_name' => 'ui-game-' . $i, 'post_title' => $titles[ $i % 4 ] . ' ' . ( $i + 1 ), 'post_excerpt' => 'اكتشف عالماً من المغامرات والتحديات، بمراحل متنوعة وأسلوب لعب كلاسيكي.', 'post_content' => '<p>لعبة تجريبية لمراجعة عرض المكتبة.</p>', 'post_date' => gmdate( 'Y-m-d H:i:s', time() - $i * HOUR_IN_SECONDS ) ) );
	wp_set_object_terms( $id, $systems[ $i % 4 ], 'rv_system' );
	wp_set_object_terms( $id, $i % 2 ? 'adventure' : 'platformer', 'rv_genre' );
	if ( $i % 7 ) { set_post_thumbnail( $id, $covers[ $i % 4 ] ); }
	update_post_meta( $id, '_rv_rom_id', $rom );
	update_post_meta( $id, '_rv_players', $i % 2 ? 2 : 1 );
	update_post_meta( $id, '_rv_year', 1990 + $i );
	update_post_meta( $id, '_rv_version', '1.0' );
	update_post_meta( $id, '_rv_featured', $i < 7 ? 1 : 0 );
	update_post_meta( $id, '_rv_status', $i % 3 ? 'released' : 'beta' );
	\RetroVault\Game_Meta::ensure_defaults( $id );
	if ( $i % 3 ) { \RetroVault\Ratings::set( $id, 1, 3 + $i % 3 ); }
}
wp_update_post( array( 'ID' => $original->ID, 'post_title' => 'مغامرة البكسل — Pixel Quest', 'post_excerpt' => 'اقفز بين المنصات، واكتشف الأسرار، واستمتع بمغامرة كلاسيكية تعمل مباشرة في متصفحك.', 'post_content' => '<h3>عن اللعبة</h3><p>رحلة قصيرة عبر عالم من المنصات والأسرار. اجمع القطع وتجاوز العقبات لتصل إلى القلعة.</p><p>تبدأ اللعبة بسهولة وتزداد التحديات تدريجياً. يمكنك اللعب بلوحة المفاتيح أو وحدة التحكم.</p>' ) );
update_post_meta( $original->ID, '_rv_controls', '<p>الأسهم للحركة، Z للقفز، X للهجوم، وEnter لبدء اللعب.</p>' );
update_post_meta( $original->ID, '_rv_languages', 'العربية، English' );
update_post_meta( $original->ID, '_rv_credits', '<p>فريق تطوير اللعبة ومجتمع ريتروفولت.</p>' );
$mu = WPMU_PLUGIN_DIR;
wp_mkdir_p( $mu );
file_put_contents( $mu . '/rv-ui-metrics.php', <<<'PHP'
<?php
add_action( 'init', static function () { global $wp_locale; $wp_locale->text_direction = 'rtl'; } );
add_action( 'wp_footer', static function () {
    echo '<script type="application/json" id="rv-ui-metrics">' . wp_json_encode( array( 'queries' => get_num_queries(), 'memory' => memory_get_peak_usage( true ), 'server_ms' => (float) timer_stop( 0, 4 ) * 1000 ) ) . '</script>';
}, 999 );
PHP
);
WP_CLI::success( 'Interface fixtures ready.' );
