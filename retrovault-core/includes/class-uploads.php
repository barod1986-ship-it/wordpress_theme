<?php
/**
 * رفع ملفات الألعاب إلى مكتبة الوسائط.
 *
 * ووردبريس يرفض امتدادات مثل .gba و.nes افتراضياً، وحتى لو أضفناها فإن فحص المحتوى
 * (finfo) يعطي أنواعاً مثل application/x-gba-rom فيرفضها. هنا نسمح بها — لمن يملك
 * صلاحية إدارة الموقع فقط.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Uploads {

	public static function init() {
		add_filter( 'upload_mimes', array( __CLASS__, 'mimes' ) );
		add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'check' ), 10, 4 );
	}

	/**
	 * كل الامتدادات المقبولة (من سجل الأنظمة + الأرشيفات).
	 *
	 * @return string[]
	 */
	public static function extensions() {
		$ext = array( 'zip', '7z' );
		foreach ( Systems::all() as $system ) {
			$ext = array_merge( $ext, (array) $system['ext'] );
		}
		$ext = array_map( 'strtolower', $ext );
		return array_values( array_unique( array_diff( $ext, array( 'exe', 'php', 'phtml', 'js', 'html', 'htm', 'svg' ) ) ) );
	}

	/** رفع ملفات الألعاب مقصور على المدير (أو من يملك manage_options). */
	public static function allowed() {
		return (bool) apply_filters( 'retrovault_can_upload_roms', current_user_can( 'manage_options' ) );
	}

	/**
	 * @param string $ext الامتداد.
	 */
	private static function mime_for( $ext ) {
		if ( 'zip' === $ext ) {
			return 'application/zip';
		}
		if ( '7z' === $ext ) {
			return 'application/x-7z-compressed';
		}
		return 'application/octet-stream';
	}

	/**
	 * @param array $mimes الأنواع المسموحة.
	 */
	public static function mimes( $mimes ) {
		if ( ! self::allowed() ) {
			return $mimes;
		}
		$known = array();
		foreach ( array_keys( $mimes ) as $pattern ) {
			foreach ( explode( '|', $pattern ) as $e ) {
				$known[ $e ] = true;
			}
		}
		foreach ( self::extensions() as $ext ) {
			if ( ! isset( $known[ $ext ] ) ) {
				$mimes[ $ext ] = self::mime_for( $ext );
			}
		}
		return $mimes;
	}

	/**
	 * @param array  $data     ext, type, proper_filename.
	 * @param string $file     المسار المؤقت.
	 * @param string $filename اسم الملف.
	 * @param array  $mimes    الأنواع.
	 */
	public static function check( $data, $file, $filename, $mimes ) {
		if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
			return $data;
		}
		if ( ! self::allowed() ) {
			return $data;
		}
		$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		if ( $ext && in_array( $ext, self::extensions(), true ) ) {
			$data['ext']  = $ext;
			$data['type'] = self::mime_for( $ext );
		}
		return $data;
	}
}
