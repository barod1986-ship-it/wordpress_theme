<?php
/**
 * محتوى تجريبي لاختبار التشغيل (smoke.sh): أربع ألعاب على أنظمة مختلفة بأغلفة ولقطات،
 * ملف NES صغير (حلقة لا نهائية)، تدوينة مرتبطة بلعبتين، وعضو باسم member.
 *
 * التشغيل: wp eval-file seed.php
 *
 * @package RetroVault
 */

wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * صورة PNG بلون واحد.
 *
 * @param int    $w     العرض.
 * @param int    $h     الارتفاع.
 * @param int[]  $rgb   اللون.
 * @param string $label اسم الملف.
 */
function rv_seed_png( $w, $h, $rgb, $label ) {
	$im = imagecreatetruecolor( $w, $h );
	imagefilledrectangle( $im, 0, 0, $w, $h, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
	$path = get_temp_dir() . sanitize_file_name( $label ) . '.png';
	imagepng( $im, $path );
	imagedestroy( $im );
	return $path;
}

/**
 * @param string $path   الملف.
 * @param int    $parent اللعبة.
 */
function rv_seed_attach( $path, $parent ) {
	$id = media_handle_sideload(
		array(
			'name'     => basename( $path ),
			'tmp_name' => $path,
		),
		$parent
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( 'Upload failed for ' . basename( $path ) . ': ' . $id->get_error_message() );
	}
	return $id;
}

/** ملف NES بقالب iNES: 16KB برنامج (قفزة إلى نفسها) + 8KB رسوم. */
function rv_seed_nes() {
	$prg  = str_repeat( "\xEA", 16384 );
	$prg  = substr_replace( $prg, "\x4C\x00\xC0", 0, 3 );
	$prg  = substr_replace( $prg, "\x00\xC0\x00\xC0\x00\xC0", 0x3FFA, 6 );
	$path = get_temp_dir() . 'smoke-test.nes';
	file_put_contents( $path, "NES\x1A\x01\x01\x02\x00" . str_repeat( "\0", 8 ) . $prg . str_repeat( "\0", 8192 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	return $path;
}

/**
 * @param string $taxonomy التصنيف.
 * @param string $slug     الرابط.
 */
function rv_seed_term( $taxonomy, $slug ) {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term ) {
		WP_CLI::error( "Missing term {$taxonomy}/{$slug} (was the plugin activated?)" );
	}
	return (int) $term->term_id;
}

$games = array(
	// العنوان، النظام، الأنواع، اللون، مميزة، تنزيل، الإصدار، الملف.
	array( 'Pixel Quest', 'nes', array( 'platformer', 'adventure' ), array( 200, 40, 40 ), true, true, '1.0.0', 'nes' ),
	array( 'Star Racer', 'gba', array( 'racing' ), array( 40, 80, 200 ), false, false, '0.9', 'url' ),
	array( 'Dungeon Tales', 'snes', array( 'rpg' ), array( 40, 160, 60 ), false, true, '2.1', '' ),
	array( 'Void Shooter', 'ps1', array( 'shooter', 'arcade' ), array( 120, 40, 160 ), false, false, '', '' ),
);
$ids   = array();
foreach ( $games as $i => $g ) {
	list( $title, $system, $genres, $rgb, $featured, $download, $version, $rom ) = $g;
	$id = wp_insert_post(
		array(
			'post_type'    => 'rv_game',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => "<p>وصف كامل للعبة {$title}.</p>",
			'post_excerpt' => "مقتطف قصير عن {$title}.",
			'post_date'    => gmdate( 'Y-m-d H:i:s', time() - ( 10 - $i ) * DAY_IN_SECONDS ),
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	wp_set_object_terms( $id, array( rv_seed_term( 'rv_system', $system ) ), 'rv_system' );
	wp_set_object_terms(
		$id,
		array_map(
			static function ( $slug ) {
				return rv_seed_term( 'rv_genre', $slug );
			},
			$genres
		),
		'rv_genre'
	);
	set_post_thumbnail( $id, rv_seed_attach( rv_seed_png( 600, 800, $rgb, "{$title} cover" ), $id ) );
	update_post_meta( $id, '_rv_screenshots', array( rv_seed_attach( rv_seed_png( 256, 240, $rgb, "{$title} shot" ), $id ) ) );
	if ( 'nes' === $rom ) {
		update_post_meta( $id, '_rv_rom_id', rv_seed_attach( rv_seed_nes(), $id ) );
	} elseif ( 'url' === $rom ) {
		update_post_meta( $id, '_rv_rom_url', 'https://example.com/roms/star-racer.gba' );
	}
	update_post_meta( $id, '_rv_featured', $featured ? '1' : '0' );
	update_post_meta( $id, '_rv_downloadable', $download ? '1' : '0' );
	update_post_meta( $id, '_rv_version', $version );
	update_post_meta( $id, '_rv_year', 2020 + $i );
	update_post_meta( $id, '_rv_players', $i + 1 );
	update_post_meta( $id, '_rv_status', array( 'released', 'demo', 'beta', 'wip' )[ $i ] );
	update_post_meta( $id, '_rv_changelog', "- إضافة مرحلة جديدة\n- إصلاح أخطاء" );
	\RetroVault\Game_Meta::ensure_defaults( $id );
	$ids[] = $id;
}

$post = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'devlog-first-update',
		'post_content' => '<p>ملاحظات التطوير.</p>',
	)
);
add_post_meta( $post, '_rv_game', $ids[0] );
add_post_meta( $post, '_rv_game', $ids[2] );

if ( ! get_user_by( 'login', 'member' ) ) {
	wp_insert_user(
		array(
			'user_login'   => 'member',
			'user_pass'    => 'member',
			'user_email'   => 'member@example.com',
			'role'         => 'subscriber',
			'display_name' => 'عضو',
		)
	);
}

WP_CLI::success( 'Seeded games ' . implode( ',', $ids ) . ' and post ' . $post );
