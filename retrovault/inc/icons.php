<?php
/**
 * أيقونات بكسل: كل أيقونة خريطة نصية (# = بكسل) تتحول إلى SVG حاد الحواف.
 * لا ملفات صور ولا مكتبات أيقونات، وكلها تأخذ لون النص (currentColor).
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return array<string,string[]>
 */
function rvt_icon_maps() {
	return array(
		'play'       => array( '##.....', '####...', '######.', '#######', '######.', '####...', '##.....' ),
		'star'       => array( '....#....', '....#....', '...###...', '#########', '.#######.', '..#####..', '..##.##..', '.##...##.', '.#.....#.' ),
		'search'     => array( '.#####...', '##...##..', '#.....#..', '#.....#..', '#.....#..', '##...##..', '.#####...', '.....###.', '......###' ),
		'user'       => array( '..###..', '.#####.', '.#####.', '..###..', '.......', '.#####.', '#######', '#######' ),
		'menu'       => array( '########', '........', '........', '########', '........', '........', '########' ),
		'close'      => array( '##...##', '###.###', '.#####.', '..###..', '.#####.', '###.###', '##...##' ),
		'download'   => array( '...###...', '...###...', '...###...', '.#######.', '..#####..', '...###...', '....#....', '.........', '#########', '#########' ),
		'share'      => array( '....#....', '...###...', '..#####..', '.#######.', '...###...', '...###...', '#..###..#', '#.......#', '#########', '#########' ),
		'fullscreen' => array( '###..###', '#......#', '#......#', '........', '........', '#......#', '#......#', '###..###' ),
		'theater'    => array( '##########', '#........#', '#........#', '#........#', '##########', '..........', '...####...' ),
		'reload'     => array( '..####.#', '.#....##', '#...####', '#.......', '#.......', '#......#', '.#....#.', '..####..' ),
		'window'     => array( '#########', '#########', '#.......#', '#.......#', '#.......#', '#.......#', '#.......#', '#########' ),
		'next'       => array( '#....', '##...', '.##..', '..##.', '...##', '..##.', '.##..', '##...', '#....' ),
		'prev'       => array( '....#', '...##', '..##.', '.##..', '##...', '.##..', '..##.', '...##', '....#' ),
		'dice'       => array( '#########', '#.......#', '#.#...#.#', '#.......#', '#...#...#', '#.......#', '#.#...#.#', '#.......#', '#########' ),
		'pad'        => array( '..#########..', '.###########.', '###.#####.###', '##...###.#.##', '###.#####.###', '.###########.', '.####...####.' ),
		'chat'       => array( '#########', '#.......#', '#.......#', '#.......#', '#########', '.##......', '.#.......' ),
		'check'      => array( '........#', '.......##', '#.....##.', '##...##..', '.##.##...', '..###....', '...#.....' ),
		'heart'      => array( '.##...##.', '####.####', '#########', '#########', '.#######.', '..#####..', '...###...', '....#....' ),
		'cloud'      => array( '....###....', '..#######..', '.#########.', '###########', '###########', '.#########.' ),
		'trash'      => array( '..###..', '#######', '.......', '.#####.', '.#.#.#.', '.#.#.#.', '.#.#.#.', '.#####.' ),
		'filter'     => array( '#########', '.#######.', '..#####..', '...###...', '...###...', '...###...', '...###...' ),
	);
}

/**
 * مستطيلات أيقونة (كل سطر متصل من # يصبح مستطيلاً واحداً).
 *
 * @param string[] $rows خريطة الأيقونة.
 * @return string
 */
function rvt_icon_rects( $rows ) {
	$rects = '';
	foreach ( $rows as $y => $row ) {
		$len = strlen( $row );
		$x   = 0;
		while ( $x < $len ) {
			if ( '#' !== $row[ $x ] ) {
				++$x;
				continue;
			}
			$start = $x;
			while ( $x < $len && '#' === $row[ $x ] ) {
				++$x;
			}
			$rects .= '<rect x="' . $start . '" y="' . $y . '" width="' . ( $x - $start ) . '" height="1"/>';
		}
	}
	return $rects;
}

/**
 * ملف الأيقونات (sprite): يُطبع مرة واحدة أعلى الصفحة، وكل أيقونة بعدها مجرد <use>.
 */
function rvt_icon_sprite() {
	$out = '<svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden">';
	foreach ( rvt_icon_maps() as $name => $rows ) {
		$out .= sprintf(
			'<symbol id="rvt-i-%1$s" viewBox="0 0 %2$d %3$d" shape-rendering="crispEdges">%4$s</symbol>',
			esc_attr( $name ),
			strlen( $rows[0] ),
			count( $rows ),
			rvt_icon_rects( $rows )
		);
	}
	return $out . '</svg>';
}
add_action(
	'wp_body_open',
	static function () {
		echo rvt_icon_sprite(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- خرائط ثابتة داخل القالب.
	}
);

/**
 * @param string $name  اسم الأيقونة.
 * @param string $class كلاس إضافي (مثل rvt-icon--flip للانعكاس في RTL).
 * @return string SVG
 */
function rvt_icon( $name, $class = '' ) {
	$maps = rvt_icon_maps();
	if ( ! isset( $maps[ $name ] ) ) {
		return '';
	}
	$w = strlen( $maps[ $name ][0] );
	$h = count( $maps[ $name ] );
	return sprintf(
		'<svg class="rvt-icon rvt-icon--%1$s%2$s" viewBox="0 0 %3$d %4$d" width="%5$sem" height="1em" aria-hidden="true"><use href="#rvt-i-%1$s"/></svg>',
		esc_attr( $name ),
		$class ? ' ' . esc_attr( $class ) : '',
		$w,
		$h,
		round( $w / $h, 3 )
	);
}

/**
 * أيقونات أزرار المشغّل (تطلبها الإضافة عبر فلتر).
 */
add_filter(
	'retrovault_icon',
	static function ( $html, $name ) {
		$map = array(
			'play'       => 'play',
			'fullscreen' => 'fullscreen',
			'theater'    => 'theater',
			'reload'     => 'reload',
			'external'   => 'window',
			'cloud'      => 'cloud',
		);
		return isset( $map[ $name ] ) ? rvt_icon( $map[ $name ] ) : $html;
	},
	10,
	2
);
