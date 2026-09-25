<?php
/**
 * سجل الأنظمة (Registry).
 *
 * كل نظام (NES، GBA، PS1...) له: اسم النظام في EmulatorJS، الأنوية المتاحة،
 * امتدادات الملفات المقبولة، نسبة أبعاد الشاشة، لون مميز، وتخطيط أزرار التحكم.
 * القيم مطابقة لـ EmulatorJS 4.2.x (أسماء الأنوية وأزرار لوحة المفاتيح الافتراضية).
 *
 * لإضافة نظام جديد من قالب أو إضافة أخرى استخدم الفلتر: retrovault_systems
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Systems {

	/**
	 * مفاتيح لوحة المفاتيح الافتراضية في EmulatorJS (رقم زر RetroPad => المفتاح).
	 * المصدر: defaultControllers في data/src/emulator.js.
	 */
	const KEYS = array(
		0  => 'X',
		1  => 'S',
		2  => 'V',
		3  => 'Enter',
		4  => '↑',
		5  => '↓',
		6  => '←',
		7  => '→',
		8  => 'Z',
		9  => 'A',
		10 => 'Q',
		11 => 'E',
		12 => 'Tab',
		13 => 'R',
		16 => 'H',
		17 => 'F',
		18 => 'G',
		19 => 'T',
		20 => 'L',
		21 => 'J',
		22 => 'K',
		23 => 'I',
		24 => '1',
		25 => '2',
	);

	/** @var array|null */
	private static $systems = null;

	/**
	 * كل الأنظمة المعروفة.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		if ( null === self::$systems ) {
			self::$systems = (array) apply_filters( 'retrovault_systems', self::defaults() );
		}
		return self::$systems;
	}

	/**
	 * @param string $key مفتاح النظام في السجل.
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return ( is_string( $key ) && isset( $all[ $key ] ) ) ? $all[ $key ] : null;
	}

	/**
	 * النظام الذي يدل عليه امتداد ملف اللعبة، أو '' إن كان الامتداد مشتركاً بين أنظمة (bin، cue، iso...)
	 * أو غير معروف. أول امتداد في قائمة النظام هو امتداده الأصلي: ‎.gb لـ Game Boy مع أن Game Boy Color
	 * يقبله أيضاً.
	 *
	 * @param string   $ext  امتداد الملف.
	 * @param string[] $keys الاختيار من هذه الأنظمة فقط (الموجودة في الموقع)؛ فارغة للكل.
	 * @return string مفتاح النظام في السجل.
	 */
	public static function for_extension( $ext, $keys = array() ) {
		$ext = strtolower( ltrim( (string) $ext, '.' ) );
		if ( '' === $ext ) {
			return '';
		}
		$any     = array();
		$primary = array();
		foreach ( self::all() as $key => $system ) {
			if ( $keys && ! in_array( $key, $keys, true ) ) {
				continue;
			}
			$exts = array_map( 'strtolower', (array) $system['ext'] );
			if ( in_array( $ext, $exts, true ) ) {
				$any[] = $key;
				if ( $exts[0] === $ext ) {
					$primary[] = $key;
				}
			}
		}
		if ( 1 === count( $any ) ) {
			return $any[0];
		}
		return 1 === count( $primary ) ? $primary[0] : '';
	}

	/**
	 * الأنظمة التي تُنشأ تلقائياً عند أول تفعيل (يمكن حذفها أو إضافة غيرها لاحقاً).
	 *
	 * @return string[]
	 */
	public static function seeded() {
		return array( 'nes', 'snes', 'gb', 'gbc', 'gba', 'n64', 'nds', 'psx', 'genesis', 'sms', 'gg', 'pce', 'a2600' );
	}

	/**
	 * خيارات لقائمة منسدلة في لوحة التحكم.
	 *
	 * @return array<string,string>
	 */
	public static function choices() {
		$out = array();
		foreach ( self::all() as $key => $system ) {
			$out[ $key ] = sprintf( '%s (%s)', $system['name'], $system['short'] );
		}
		return $out;
	}

	/**
	 * جدول التحكم الافتراضي لنظام: [ [ 'label' => 'A', 'keys' => ['Z'] ], ... ].
	 *
	 * @param string $key مفتاح النظام.
	 * @return array
	 */
	public static function controls( $key ) {
		$system = self::get( $key );
		$layout = self::layout( $system ? $system['pad'] : 'retropad' );
		$rows   = array();

		foreach ( $layout as $row ) {
			$keys = array();
			foreach ( (array) $row[1] as $id ) {
				if ( isset( self::KEYS[ $id ] ) ) {
					$keys[] = self::KEYS[ $id ];
				}
			}
			if ( $keys ) {
				$rows[] = array(
					'label' => $row[0],
					'keys'  => $keys,
				);
			}
		}
		return $rows;
	}

	/**
	 * تخطيط الأزرار لكل عائلة أجهزة (اسم الزر => أرقام أزرار RetroPad).
	 *
	 * @param string $pad اسم التخطيط.
	 * @return array
	 */
	private static function layout( $pad ) {
		$dpad   = array( __( 'الاتجاهات', 'retrovault-core' ), array( 4, 5, 6, 7 ) );
		$start  = array( 'Start', array( 3 ) );
		$select = array( 'Select', array( 2 ) );
		$save   = array( __( 'حفظ سريع / تحميل سريع', 'retrovault-core' ), array( 24, 25 ) );

		$layouts = array(
			'nes'      => array( $dpad, array( 'A', array( 8 ) ), array( 'B', array( 0 ) ), $start, $select ),
			'gba'      => array( $dpad, array( 'A', array( 8 ) ), array( 'B', array( 0 ) ), array( 'L', array( 10 ) ), array( 'R', array( 11 ) ), $start, $select ),
			'snes'     => array( $dpad, array( 'A', array( 8 ) ), array( 'B', array( 0 ) ), array( 'X', array( 9 ) ), array( 'Y', array( 1 ) ), array( 'L', array( 10 ) ), array( 'R', array( 11 ) ), $start, $select ),
			'n64'      => array(
				array( __( 'العصا التناظرية', 'retrovault-core' ), array( 19, 18, 17, 16 ) ),
				array( 'A', array( 0 ) ),
				array( 'B', array( 1 ) ),
				array( 'Z', array( 12 ) ),
				array( 'L', array( 10 ) ),
				array( 'R', array( 11 ) ),
				array( __( 'أزرار C', 'retrovault-core' ), array( 23, 22, 21, 20 ) ),
				array( 'D-Pad', array( 4, 5, 6, 7 ) ),
				$start,
			),
			'psx'      => array( $dpad, array( '✕', array( 0 ) ), array( '○', array( 8 ) ), array( '□', array( 1 ) ), array( '△', array( 9 ) ), array( 'L1', array( 10 ) ), array( 'R1', array( 11 ) ), array( 'L2', array( 12 ) ), array( 'R2', array( 13 ) ), $start, $select ),
			'md'       => array( $dpad, array( 'A', array( 1 ) ), array( 'B', array( 0 ) ), array( 'C', array( 8 ) ), array( 'X', array( 10 ) ), array( 'Y', array( 9 ) ), array( 'Z', array( 11 ) ), $start, array( 'Mode', array( 2 ) ) ),
			'sms'      => array( $dpad, array( '1 / Start', array( 0 ) ), array( '2', array( 8 ) ) ),
			'gg'       => array( $dpad, array( '1', array( 0 ) ), array( '2', array( 8 ) ), $start ),
			'pce'      => array( $dpad, array( 'I', array( 8 ) ), array( 'II', array( 0 ) ), array( 'Run', array( 3 ) ), $select ),
			'a2600'    => array( $dpad, array( 'Fire', array( 0 ) ), array( 'Reset', array( 3 ) ), $select ),
			'a7800'    => array( $dpad, array( '1', array( 0 ) ), array( '2', array( 8 ) ), array( 'Pause', array( 3 ) ), array( 'Reset', array( 9 ) ), $select ),
			'lynx'     => array( $dpad, array( 'A', array( 8 ) ), array( 'B', array( 0 ) ), array( 'Option 1', array( 10 ) ), array( 'Option 2', array( 11 ) ), $start ),
			'ngp'      => array( $dpad, array( 'A', array( 0 ) ), array( 'B', array( 8 ) ), array( 'Option', array( 3 ) ) ),
			'ws'       => array( array( 'X-Pad', array( 4, 5, 6, 7 ) ), array( 'Y-Pad', array( 13, 12, 10, 11 ) ), array( 'A', array( 8 ) ), array( 'B', array( 0 ) ), $start ),
			'vb'       => array( array( __( 'الاتجاهات اليسرى', 'retrovault-core' ), array( 4, 5, 6, 7 ) ), array( __( 'الاتجاهات اليمنى', 'retrovault-core' ), array( 19, 18, 17, 16 ) ), array( 'A', array( 8 ) ), array( 'B', array( 0 ) ), array( 'L', array( 10 ) ), array( 'R', array( 11 ) ), $start, $select ),
			'retropad' => array( $dpad, array( 'A', array( 8 ) ), array( 'B', array( 0 ) ), array( 'X', array( 9 ) ), array( 'Y', array( 1 ) ), array( 'L', array( 10 ) ), array( 'R', array( 11 ) ), $start, $select ),
		);
		$layouts['gb']  = $layouts['nes'];
		$layouts['nds'] = $layouts['snes'];

		$layout   = isset( $layouts[ $pad ] ) ? $layouts[ $pad ] : $layouts['retropad'];
		$layout[] = $save;

		return (array) apply_filters( 'retrovault_pad_layout', $layout, $pad );
	}

	/**
	 * التعريفات الافتراضية.
	 * ejs   = قيمة EJS_core، cores = الأنوية المتاحة (الأولى هي الافتراضية)،
	 * ratio = نسبة أبعاد الشاشة الأصلية (للإطار حول المشغّل).
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			'nes'     => array( 'name' => 'Nintendo Entertainment System', 'short' => 'NES', 'maker' => 'Nintendo', 'year' => 1983, 'ejs' => 'nes', 'cores' => array( 'fceumm', 'nestopia' ), 'ext' => array( 'nes', 'fds', 'unf', 'unif' ), 'ratio' => '4/3', 'color' => '#C8102E', 'pad' => 'nes' ),
			'snes'    => array( 'name' => 'Super Nintendo', 'short' => 'SNES', 'maker' => 'Nintendo', 'year' => 1990, 'ejs' => 'snes', 'cores' => array( 'snes9x' ), 'ext' => array( 'smc', 'sfc', 'fig', 'swc', 'bs', 'st' ), 'ratio' => '4/3', 'color' => '#5B4E9E', 'pad' => 'snes' ),
			'gb'      => array( 'name' => 'Game Boy', 'short' => 'GB', 'maker' => 'Nintendo', 'year' => 1989, 'ejs' => 'gb', 'cores' => array( 'gambatte' ), 'ext' => array( 'gb', 'dmg' ), 'ratio' => '10/9', 'color' => '#6F7F12', 'pad' => 'gb' ),
			'gbc'     => array( 'name' => 'Game Boy Color', 'short' => 'GBC', 'maker' => 'Nintendo', 'year' => 1998, 'ejs' => 'gb', 'cores' => array( 'gambatte' ), 'ext' => array( 'gbc', 'cgb', 'gb' ), 'ratio' => '10/9', 'color' => '#0F8F92', 'pad' => 'gb' ),
			'gba'     => array( 'name' => 'Game Boy Advance', 'short' => 'GBA', 'maker' => 'Nintendo', 'year' => 2001, 'ejs' => 'gba', 'cores' => array( 'mgba' ), 'ext' => array( 'gba', 'agb' ), 'ratio' => '3/2', 'color' => '#4B3FB0', 'pad' => 'gba' ),
			'n64'     => array( 'name' => 'Nintendo 64', 'short' => 'N64', 'maker' => 'Nintendo', 'year' => 1996, 'ejs' => 'n64', 'cores' => array( 'mupen64plus_next', 'parallel_n64' ), 'ext' => array( 'n64', 'z64', 'v64' ), 'ratio' => '4/3', 'color' => '#D9531E', 'pad' => 'n64' ),
			'nds'     => array( 'name' => 'Nintendo DS', 'short' => 'NDS', 'maker' => 'Nintendo', 'year' => 2004, 'ejs' => 'nds', 'cores' => array( 'melonds', 'desmume', 'desmume2015' ), 'ext' => array( 'nds' ), 'ratio' => '2/3', 'color' => '#2C6FB5', 'pad' => 'nds' ),
			'vb'      => array( 'name' => 'Virtual Boy', 'short' => 'VB', 'maker' => 'Nintendo', 'year' => 1995, 'ejs' => 'vb', 'cores' => array( 'beetle_vb' ), 'ext' => array( 'vb', 'vboy' ), 'ratio' => '12/7', 'color' => '#9B1B30', 'pad' => 'vb' ),
			'psx'     => array( 'name' => 'PlayStation', 'short' => 'PS1', 'maker' => 'Sony', 'year' => 1994, 'ejs' => 'psx', 'cores' => array( 'pcsx_rearmed', 'mednafen_psx_hw' ), 'ext' => array( 'cue', 'bin', 'img', 'iso', 'chd', 'pbp', 'm3u', 'ccd', 'exe' ), 'ratio' => '4/3', 'color' => '#5E6472', 'pad' => 'psx' ),
			'sms'     => array( 'name' => 'Master System', 'short' => 'SMS', 'maker' => 'Sega', 'year' => 1985, 'ejs' => 'segaMS', 'cores' => array( 'smsplus', 'genesis_plus_gx', 'picodrive' ), 'ext' => array( 'sms' ), 'ratio' => '4/3', 'color' => '#0E3F8A', 'pad' => 'sms' ),
			'genesis' => array( 'name' => 'Mega Drive / Genesis', 'short' => 'MD', 'maker' => 'Sega', 'year' => 1988, 'ejs' => 'segaMD', 'cores' => array( 'genesis_plus_gx', 'picodrive' ), 'ext' => array( 'md', 'gen', 'smd', 'bin', '68k', 'sgd' ), 'ratio' => '4/3', 'color' => '#26262E', 'pad' => 'md' ),
			'gg'      => array( 'name' => 'Game Gear', 'short' => 'GG', 'maker' => 'Sega', 'year' => 1990, 'ejs' => 'segaGG', 'cores' => array( 'genesis_plus_gx' ), 'ext' => array( 'gg' ), 'ratio' => '10/9', 'color' => '#1F7A7A', 'pad' => 'gg' ),
			'segacd'  => array( 'name' => 'Mega-CD / Sega CD', 'short' => 'MCD', 'maker' => 'Sega', 'year' => 1991, 'ejs' => 'segaCD', 'cores' => array( 'genesis_plus_gx', 'picodrive' ), 'ext' => array( 'cue', 'bin', 'iso', 'chd', 'm3u' ), 'ratio' => '4/3', 'color' => '#3B3F8F', 'pad' => 'md' ),
			'32x'     => array( 'name' => 'Sega 32X', 'short' => '32X', 'maker' => 'Sega', 'year' => 1994, 'ejs' => 'sega32x', 'cores' => array( 'picodrive' ), 'ext' => array( '32x' ), 'ratio' => '4/3', 'color' => '#A3242E', 'pad' => 'md' ),
			'pce'     => array( 'name' => 'PC Engine / TurboGrafx-16', 'short' => 'PCE', 'maker' => 'NEC', 'year' => 1987, 'ejs' => 'pce', 'cores' => array( 'mednafen_pce' ), 'ext' => array( 'pce', 'sgx', 'cue', 'ccd', 'chd' ), 'ratio' => '4/3', 'color' => '#A07A1F', 'pad' => 'pce' ),
			'ngp'     => array( 'name' => 'Neo Geo Pocket', 'short' => 'NGP', 'maker' => 'SNK', 'year' => 1998, 'ejs' => 'ngp', 'cores' => array( 'mednafen_ngp' ), 'ext' => array( 'ngp', 'ngc' ), 'ratio' => '20/19', 'color' => '#A8325E', 'pad' => 'ngp' ),
			'ws'      => array( 'name' => 'WonderSwan', 'short' => 'WS', 'maker' => 'Bandai', 'year' => 1999, 'ejs' => 'ws', 'cores' => array( 'mednafen_wswan' ), 'ext' => array( 'ws', 'wsc' ), 'ratio' => '14/9', 'color' => '#4C6B2E', 'pad' => 'ws' ),
			'lynx'    => array( 'name' => 'Atari Lynx', 'short' => 'LYNX', 'maker' => 'Atari', 'year' => 1989, 'ejs' => 'lynx', 'cores' => array( 'handy' ), 'ext' => array( 'lnx' ), 'ratio' => '80/51', 'color' => '#B84E17', 'pad' => 'lynx' ),
			'a2600'   => array( 'name' => 'Atari 2600', 'short' => '2600', 'maker' => 'Atari', 'year' => 1977, 'ejs' => 'atari2600', 'cores' => array( 'stella2014' ), 'ext' => array( 'a26', 'bin' ), 'ratio' => '4/3', 'color' => '#7A4A1E', 'pad' => 'a2600' ),
			'a7800'   => array( 'name' => 'Atari 7800', 'short' => '7800', 'maker' => 'Atari', 'year' => 1986, 'ejs' => 'atari7800', 'cores' => array( 'prosystem' ), 'ext' => array( 'a78', 'bin' ), 'ratio' => '4/3', 'color' => '#8A5A2B', 'pad' => 'a7800' ),
			'coleco'  => array( 'name' => 'ColecoVision', 'short' => 'CV', 'maker' => 'Coleco', 'year' => 1982, 'ejs' => 'coleco', 'cores' => array( 'gearcoleco' ), 'ext' => array( 'col', 'cv' ), 'ratio' => '4/3', 'color' => '#2E4A7D', 'pad' => 'retropad' ),
		);
	}
}
