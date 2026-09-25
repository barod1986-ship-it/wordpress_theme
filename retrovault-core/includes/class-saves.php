<?php
/**
 * الحفظ السحابي للأعضاء.
 *
 * نوعان من الحفظ لكل لعبة:
 * 1. حالات المحاكي (Save State): زر «حفظ الحالة» في شريط المحاكي. تُحفظ آخر N حالات
 *    (سجل دوّار، الافتراضي 3) فلا يضيع التقدّم إن ضغط العضو الحفظ في لحظة سيئة.
 * 2. حفظ اللعبة الداخلي (SRAM): ما تحفظه اللعبة نفسها من قائمتها. يُزامَن تلقائياً
 *    بين الأجهزة (نسخة واحدة لكل لعبة).
 *
 * التخزين: uploads/rv-saves/u{id}-{مفتاح عشوائي}/{لعبة}-{رمز}.state|.shot|.srm
 * أسماء غير قابلة للتخمين + .htaccess يمنع الوصول المباشر؛ التنزيل عبر REST لصاحبه فقط.
 *
 *   GET    games/{id}/save                  كل حفظات اللعبة (الحالات + SRAM)
 *   POST   games/{id}/save                  حالة جديدة (state, screenshot, encoding, core)
 *   DELETE games/{id}/save[?slot=رمز]        حذف حالة واحدة أو كل الحالات
 *   GET    games/{id}/save/state|shot[?slot=رمز]
 *   GET    games/{id}/sram                  معلومات حفظ اللعبة الداخلي (hash, time)
 *   POST   games/{id}/sram                  رفع (sram, encoding, hash)
 *   DELETE games/{id}/sram
 *   GET    games/{id}/sram/file
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Saves {

	const META     = '_rv_saves';
	const KEY_META = '_rv_saves_key';
	const DIR      = 'rv-saves';
	const SHOT_MAX = 2 * MB_IN_BYTES;
	const SRAM_MAX = 4 * MB_IN_BYTES;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'delete_user', array( __CLASS__, 'delete_all' ) );
	}

	public static function enabled() {
		return (bool) Settings::get( 'cloud_saves' );
	}

	public static function sram_enabled() {
		return self::enabled() && (bool) Settings::get( 'sram_sync' );
	}

	/** عدد الحالات المحفوظة لكل لعبة. */
	public static function slots() {
		return max( 1, min( 10, (int) Settings::get( 'save_slots' ) ) );
	}

	/** الحد الأقصى لحجم الحالة بعد الضغط (لا يتجاوز حد الرفع في PHP). */
	public static function max_bytes() {
		return (int) min( wp_max_upload_size(), max( 1, (int) Settings::get( 'save_max_mb' ) ) * MB_IN_BYTES );
	}

	/**
	 * @param mixed $token رمز الحالة.
	 */
	public static function valid_token( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-z0-9]{16}$/', $token );
	}

	/* ---------------------------------------------------------------------
	 * التخزين
	 * ------------------------------------------------------------------ */

	private static function base_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * @param int  $user_id رقم العضو.
	 * @param bool $create  إنشاؤه إن لم يوجد.
	 */
	private static function user_dir( $user_id, $create = false ) {
		$key = (string) get_user_meta( $user_id, self::KEY_META, true );
		if ( '' === $key ) {
			if ( ! $create ) {
				return '';
			}
			$key = strtolower( wp_generate_password( 12, false ) );
			update_user_meta( $user_id, self::KEY_META, $key );
		}
		$dir = self::base_dir() . '/u' . (int) $user_id . '-' . $key;
		if ( $create && ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * @param int    $user_id رقم العضو.
	 * @param int    $game_id رقم اللعبة.
	 * @param string $token   رمز الملف.
	 * @param string $ext     state|shot|srm.
	 */
	private static function path( $user_id, $game_id, $token, $ext ) {
		$dir = self::user_dir( $user_id );
		if ( '' === $dir || ! self::valid_token( $token ) ) {
			return '';
		}
		return $dir . '/' . (int) $game_id . '-' . $token . '.' . $ext;
	}

	/**
	 * كل الحفظات بالشكل: [ game_id => [ 'states' => [الأحدث أولاً], 'sram' => entry|null ] ].
	 * يحوّل صيغة الإصدار 1.1 (حالة واحدة لكل لعبة) تلقائياً.
	 *
	 * @param int $user_id رقم العضو.
	 * @return array
	 */
	private static function entries( $user_id ) {
		$raw = get_user_meta( $user_id, self::META, true );
		$out = array();
		foreach ( (array) $raw as $game_id => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( isset( $value['token'] ) ) {
				$out[ (int) $game_id ] = array(
					'states' => array( $value ),
					'sram'   => null,
				);
				continue;
			}
			$out[ (int) $game_id ] = array(
				'states' => isset( $value['states'] ) ? array_values( array_filter( (array) $value['states'], 'is_array' ) ) : array(),
				'sram'   => ( isset( $value['sram'] ) && is_array( $value['sram'] ) ) ? $value['sram'] : null,
			);
		}
		return $out;
	}

	/** Serialize changes to the complete user save index across games and requests. */
	private static function with_lock( $user_id, $callback ) {
		global $wpdb;
		$key   = 'rv_saves_mutex_' . (int) $user_id;
		$owner = ( time() + 300 ) . ':' . wp_generate_uuid4();
		$held  = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $owner ) );
		if ( ! $held ) {
			$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
			if ( $old && (int) $old < time() ) {
				$held = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $owner, $key, $old ) );
			}
		}
		if ( ! $held ) {
			return new \WP_Error( 'rv_save_busy', __( 'يجري حفظ آخر لحسابك. حاول مرة أخرى بعد لحظة.', 'retrovault-core' ), array( 'status' => 429 ) );
		}
		try {
			wp_cache_delete( $user_id, 'user_meta' );
			return $callback();
		} finally {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $owner ) );
		}
	}

	private static function write_error() {
		return new \WP_Error( 'rv_save_write', __( 'تعذّر حفظ بيانات التقدّم. بقي الحفظ السابق كما هو.', 'retrovault-core' ), array( 'status' => 500 ) );
	}

	/**
	 * @param int   $user_id رقم العضو.
	 * @param array $all     كل الحفظات.
	 */
	private static function store( $user_id, $all ) {
		foreach ( $all as $game_id => $game ) {
			if ( empty( $game['states'] ) && empty( $game['sram'] ) ) {
				unset( $all[ $game_id ] );
			}
		}
		return get_user_meta( $user_id, self::META, true ) === $all || (bool) update_user_meta( $user_id, self::META, $all );
	}

	/**
	 * @param int $user_id رقم العضو.
	 * @param int $game_id رقم اللعبة.
	 * @return array{states:array,sram:array|null}
	 */
	private static function game( $user_id, $game_id ) {
		$all = self::entries( $user_id );
		return isset( $all[ (int) $game_id ] ) ? $all[ (int) $game_id ] : array(
			'states' => array(),
			'sram'   => null,
		);
	}

	/**
	 * @param string $from المصدر.
	 * @param string $to   الوجهة.
	 */
	private static function move( $from, $to ) {
		if ( '' === $to ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ok = is_uploaded_file( $from ) ? @move_uploaded_file( $from, $to ) : @copy( $from, $to );
		if ( $ok ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			@chmod( $to, 0640 );
		}
		return (bool) $ok;
	}

	/**
	 * @param int      $user_id رقم العضو.
	 * @param int      $game_id رقم اللعبة.
	 * @param array    $entry   بيانات الملف.
	 * @param string[] $exts    الامتدادات.
	 */
	private static function unlink_entry( $user_id, $game_id, $entry, $exts ) {
		foreach ( $exts as $ext ) {
			$file = self::path( $user_id, $game_id, isset( $entry['token'] ) ? $entry['token'] : '', $ext );
			if ( $file && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * إضافة حالة جديدة في أول السجل، وحذف ما يزيد عن عدد الخانات.
	 *
	 * @param int         $user_id   رقم العضو.
	 * @param int         $game_id   رقم اللعبة.
	 * @param string      $state_tmp مسار الحالة المرفوعة.
	 * @param string|null $shot_tmp  مسار اللقطة المرفوعة.
	 * @param string      $shot_mime نوع اللقطة.
	 * @param string      $encoding  gzip|raw.
	 * @param string      $core      النواة التي أنشأت الحالة.
	 * @return array|\WP_Error
	 */
	public static function add_state( $user_id, $game_id, $state_tmp, $shot_tmp, $shot_mime, $encoding, $core ) {
		return self::with_lock( $user_id, static function () use ( $user_id, $game_id, $state_tmp, $shot_tmp, $shot_mime, $encoding, $core ) {
			$game  = Games::get( $game_id );
			$entry = array(
				'token' => strtolower( wp_generate_password( 16, false ) ),
				'time'  => time(),
				'size'  => (int) filesize( $state_tmp ),
				'enc'   => 'gzip' === $encoding ? 'gzip' : 'raw',
				'shot'  => $shot_tmp ? $shot_mime : '',
				'core'  => $core,
				'ver'   => $game ? $game['version'] : '',
			);

			self::user_dir( $user_id, true );
			if ( ! self::move( $state_tmp, self::path( $user_id, $game_id, $entry['token'], 'state' ) ) ) {
				return new \WP_Error( 'rv_save_write', __( 'تعذّر حفظ الملف على الخادم.', 'retrovault-core' ), array( 'status' => 500 ) );
			}
			if ( $shot_tmp && ! self::move( $shot_tmp, self::path( $user_id, $game_id, $entry['token'], 'shot' ) ) ) {
				$entry['shot'] = '';
			}

			$all    = self::entries( $user_id );
			$record = isset( $all[ $game_id ] ) ? $all[ $game_id ] : array(
				'states' => array(),
				'sram'   => null,
			);
			array_unshift( $record['states'], $entry );
			$removed          = array_slice( $record['states'], self::slots() );
			$record['states'] = array_slice( $record['states'], 0, self::slots() );
			$all[ $game_id ]  = $record;
			if ( ! self::store( $user_id, $all ) ) {
				self::unlink_entry( $user_id, $game_id, $entry, array( 'state', 'shot' ) );
				return self::write_error();
			}

			foreach ( $removed as $old ) {
				self::unlink_entry( $user_id, $game_id, $old, array( 'state', 'shot' ) );
			}
			return self::describe_state( $game_id, $entry, $game );
		} );
	}

	/**
	 * حذف حالة واحدة (برمزها) أو كل الحالات.
	 *
	 * @param int    $user_id رقم العضو.
	 * @param int    $game_id رقم اللعبة.
	 * @param string $token   رمز الحالة، أو فارغ للكل.
	 */
	public static function delete_state( $user_id, $game_id, $token = '' ) {
		return self::with_lock( $user_id, static function () use ( $user_id, $game_id, $token ) {
			$all = self::entries( $user_id );
			if ( empty( $all[ $game_id ]['states'] ) ) {
				return false;
			}
			$keep = array();
			$removed = array();
			foreach ( $all[ $game_id ]['states'] as $entry ) {
				if ( '' === $token || $entry['token'] === $token ) {
					$removed[] = $entry;
				} else {
					$keep[] = $entry;
				}
			}
			$all[ $game_id ]['states'] = $keep;
			if ( ! self::store( $user_id, $all ) ) { return self::write_error(); }
			foreach ( $removed as $entry ) {
				self::unlink_entry( $user_id, $game_id, $entry, array( 'state', 'shot' ) );
			}
			return true;
		} );
	}

	/**
	 * @param int    $user_id  رقم العضو.
	 * @param int    $game_id  رقم اللعبة.
	 * @param string $tmp      مسار الملف المرفوع.
	 * @param string $encoding gzip|raw.
	 * @param string $hash     بصمة المحتوى الخام (يحسبها المتصفح لقرار المزامنة).
	 * @return array|\WP_Error
	 */
	public static function put_sram( $user_id, $game_id, $tmp, $encoding, $hash, $expected_hash = null ) {
		return self::with_lock( $user_id, static function () use ( $user_id, $game_id, $tmp, $encoding, $hash, $expected_hash ) {
			$all    = self::entries( $user_id );
			$record = isset( $all[ $game_id ] ) ? $all[ $game_id ] : array( 'states' => array(), 'sram' => null );
			$old    = $record['sram'];
			$current_hash = $old ? (string) $old['hash'] : '';
			if ( null !== $expected_hash && $current_hash !== $expected_hash && $current_hash !== $hash ) {
				return new \WP_Error( 'rv_sram_conflict', __( 'تغيّر حفظ هذه اللعبة على جهاز آخر. أعد فتح اللعبة للمزامنة.', 'retrovault-core' ), array( 'status' => 409 ) );
			}
			if ( $old && $current_hash === $hash ) {
				return self::describe_sram( $old );
			}
			$entry = array(
				'token' => strtolower( wp_generate_password( 16, false ) ),
				'time'  => time(),
				'size'  => (int) filesize( $tmp ),
				'enc'   => 'gzip' === $encoding ? 'gzip' : 'raw',
				'hash'  => $hash,
			);
			self::user_dir( $user_id, true );
			if ( ! self::move( $tmp, self::path( $user_id, $game_id, $entry['token'], 'srm' ) ) ) {
				return new \WP_Error( 'rv_save_write', __( 'تعذّر حفظ الملف على الخادم.', 'retrovault-core' ), array( 'status' => 500 ) );
			}

			$record['sram']  = $entry;
			$all[ $game_id ] = $record;
			if ( ! self::store( $user_id, $all ) ) {
				self::unlink_entry( $user_id, $game_id, $entry, array( 'srm' ) );
				return self::write_error();
			}
			if ( $old ) {
				self::unlink_entry( $user_id, $game_id, $old, array( 'srm' ) );
			}
			return self::describe_sram( $entry );
		} );
	}

	/**
	 * @param int $user_id رقم العضو.
	 * @param int $game_id رقم اللعبة.
	 */
	public static function delete_sram( $user_id, $game_id ) {
		return self::with_lock( $user_id, static function () use ( $user_id, $game_id ) {
			$all = self::entries( $user_id );
			if ( empty( $all[ $game_id ]['sram'] ) ) {
				return false;
			}
			$old = $all[ $game_id ]['sram'];
			$all[ $game_id ]['sram'] = null;
			if ( ! self::store( $user_id, $all ) ) { return self::write_error(); }
			self::unlink_entry( $user_id, $game_id, $old, array( 'srm' ) );
			return true;
		} );
	}

	/**
	 * عند حذف العضو: احذف كل ملفاته.
	 *
	 * @param int $user_id رقم العضو.
	 */
	public static function delete_all( $user_id ) {
		$dir = self::user_dir( $user_id );
		if ( '' !== $dir && is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				wp_delete_file( $file );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $dir );
		}
		delete_user_meta( $user_id, self::META );
	}

	/* ---------------------------------------------------------------------
	 * بيانات للعرض
	 * ------------------------------------------------------------------ */

	/**
	 * @param int        $game_id رقم اللعبة.
	 * @param array      $entry   بيانات الحالة.
	 * @param array|null $game    بيانات اللعبة.
	 * @return array
	 */
	private static function describe_state( $game_id, $entry, $game ) {
		$shot = '';
		if ( ! empty( $entry['shot'] ) ) {
			$shot = add_query_arg(
				array(
					'slot'     => $entry['token'],
					'_wpnonce' => wp_create_nonce( 'wp_rest' ),
				),
				rest_url( Rest::NS . '/games/' . (int) $game_id . '/save/shot' )
			);
		}
		return array(
			'game_id'       => (int) $game_id,
			'exists'        => true,
			'slot'          => (string) $entry['token'],
			'time'          => (int) $entry['time'],
			/* translators: %s: human time difference */
			'ago'           => sprintf( __( 'منذ %s', 'retrovault-core' ), human_time_diff( (int) $entry['time'], time() ) ),
			'size'          => (int) $entry['size'],
			'core'          => (string) $entry['core'],
			'version'       => (string) $entry['ver'],
			'outdated'      => $game && '' !== (string) $entry['ver'] && (string) $entry['ver'] !== $game['version'],
			'core_mismatch' => $game && '' !== (string) $entry['core'] && (string) $entry['core'] !== Games::resolved_core( $game ),
			'shot_url'      => $shot,
		);
	}

	/**
	 * @param array $entry بيانات SRAM.
	 * @return array
	 */
	private static function describe_sram( $entry ) {
		return array(
			'exists' => true,
			'hash'   => (string) $entry['hash'],
			'time'   => (int) $entry['time'],
			/* translators: %s: human time difference */
			'ago'    => sprintf( __( 'منذ %s', 'retrovault-core' ), human_time_diff( (int) $entry['time'], time() ) ),
			'size'   => (int) $entry['size'],
		);
	}

	/**
	 * أحدث حالة للعضو في لعبة، أو null (لزر «استكمل»).
	 *
	 * @param int $user_id رقم العضو.
	 * @param int $game_id رقم اللعبة.
	 * @return array|null
	 */
	public static function info( $user_id, $game_id ) {
		$record = self::game( $user_id, $game_id );
		return $record['states'] ? self::describe_state( $game_id, $record['states'][0], Games::get( $game_id ) ) : null;
	}

	/**
	 * كل حفظات اللعبة: [ 'states' => [...], 'sram' => ... ].
	 *
	 * @param int $user_id رقم العضو.
	 * @param int $game_id رقم اللعبة.
	 * @return array
	 */
	public static function summary( $user_id, $game_id ) {
		$record = self::game( $user_id, $game_id );
		$game   = Games::get( $game_id );
		$states = array();
		foreach ( $record['states'] as $entry ) {
			$states[] = self::describe_state( $game_id, $entry, $game );
		}
		return array(
			'states' => $states,
			'sram'   => $record['sram'] ? self::describe_sram( $record['sram'] ) : null,
		);
	}

	/**
	 * كل حفظ العضو مجمّعاً حسب اللعبة، الأحدث نشاطاً أولاً.
	 * كل عنصر يحمل حقول أحدث حالة (توافقاً مع 1.1) + states + sram.
	 *
	 * @param int $user_id رقم العضو.
	 * @return array[]
	 */
	public static function for_user( $user_id ) {
		$out = array();
		foreach ( array_keys( self::entries( $user_id ) ) as $game_id ) {
			if ( Post_Types::GAME !== get_post_type( $game_id ) || 'publish' !== get_post_status( $game_id ) ) {
				continue;
			}
			$sum    = self::summary( $user_id, $game_id );
			$latest = $sum['states'] ? $sum['states'][0] : array(
				'game_id'  => (int) $game_id,
				'exists'   => false,
				'slot'     => '',
				'time'     => $sum['sram'] ? $sum['sram']['time'] : 0,
				'ago'      => $sum['sram'] ? $sum['sram']['ago'] : '',
				'size'     => 0,
				'version'  => '',
				'outdated' => false,
				'shot_url' => '',
			);
			$latest['states']   = $sum['states'];
			$latest['sram']     = $sum['sram'];
			$latest['activity'] = max( (int) $latest['time'], $sum['sram'] ? $sum['sram']['time'] : 0 );
			$out[]              = $latest;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['activity'] <=> $a['activity'];
			}
		);
		return $out;
	}

	/**
	 * @param int    $user_id رقم العضو.
	 * @param int    $game_id رقم اللعبة.
	 * @param string $token   رمز الحالة أو فارغ للأحدث.
	 * @return array|null
	 */
	private static function find_state( $user_id, $game_id, $token ) {
		foreach ( self::game( $user_id, $game_id )['states'] as $entry ) {
			if ( '' === $token || $entry['token'] === $token ) {
				return $entry;
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------ */

	public static function routes() {
		$base = '/games/(?P<id>\d+)';
		$perm = array( __CLASS__, 'permission' );

		register_rest_route(
			Rest::NS,
			$base . '/save',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_summary' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_upload_state' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'rest_delete_state' ),
					'permission_callback' => $perm,
				),
			)
		);
		register_rest_route(
			Rest::NS,
			$base . '/save/(?P<kind>state|shot)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_state_file' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			Rest::NS,
			$base . '/sram',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_sram_info' ),
					'permission_callback' => array( __CLASS__, 'sram_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_upload_sram' ),
					'permission_callback' => array( __CLASS__, 'sram_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'rest_delete_sram' ),
					'permission_callback' => array( __CLASS__, 'sram_permission' ),
				),
			)
		);
		register_rest_route(
			Rest::NS,
			$base . '/sram/file',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_sram_file' ),
				'permission_callback' => array( __CLASS__, 'sram_permission' ),
			)
		);
	}

	public static function permission() {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'rv_saves_disabled', __( 'الحفظ السحابي غير مفعّل في هذا الموقع.', 'retrovault-core' ), array( 'status' => 403 ) );
		}
		return Rest::logged_in();
	}

	public static function sram_permission() {
		if ( ! self::sram_enabled() ) {
			return new \WP_Error( 'rv_sram_disabled', __( 'مزامنة حفظ اللعبة غير مفعّلة في هذا الموقع.', 'retrovault-core' ), array( 'status' => 403 ) );
		}
		return Rest::logged_in();
	}

	/**
	 * التحقق من ملف مرفوع.
	 *
	 * @param array|null $file بيانات $_FILES.
	 * @param int        $max  الحد الأقصى بالبايت.
	 * @param string     $enc  gzip|raw.
	 * @return true|\WP_Error
	 */
	private static function check_upload( $file, $max, $enc ) {
		if ( ! $file || ! empty( $file['error'] ) ) {
			$too_big = $file && in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return new \WP_Error(
				$too_big ? 'rv_save_too_big' : 'rv_save_missing',
				$too_big ? __( 'حجم الحفظ أكبر مما يسمح به الخادم.', 'retrovault-core' ) : __( 'لم يصل ملف الحفظ.', 'retrovault-core' ),
				array( 'status' => $too_big ? 413 : 400 )
			);
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > $max ) {
			return new \WP_Error( 'rv_save_too_big', __( 'حجم الحفظ أكبر من الحد المسموح في إعدادات المكتبة.', 'retrovault-core' ), array( 'status' => 413 ) );
		}
		if ( 'gzip' === $enc ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			if ( "\x1f\x8b" !== (string) file_get_contents( $file['tmp_name'], false, null, 0, 2 ) ) {
				return new \WP_Error( 'rv_save_invalid', __( 'ملف الحفظ غير صالح.', 'retrovault-core' ), array( 'status' => 400 ) );
			}
		}
		return true;
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function rest_summary( $request ) {
		$post = Rest::game( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$sum           = self::summary( get_current_user_id(), $post->ID );
		$sum['exists'] = ! empty( $sum['states'] );
		$sum['latest'] = $sum['states'] ? $sum['states'][0] : null;
		return rest_ensure_response( $sum );
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function rest_upload_state( $request ) {
		$post = Rest::game( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$user_id = get_current_user_id();

		$lock = 'rv_save_lock_' . $user_id;
		if ( get_transient( $lock ) ) {
			return new \WP_Error( 'rv_too_fast', __( 'انتظر ثوانٍ قليلة بين كل حفظ وآخر.', 'retrovault-core' ), array( 'status' => 429 ) );
		}
		set_transient( $lock, 1, 2 );

		$files = $request->get_file_params();
		$enc   = 'gzip' === $request->get_param( 'encoding' ) ? 'gzip' : 'raw';
		$check = self::check_upload( isset( $files['state'] ) ? $files['state'] : null, self::max_bytes(), $enc );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$game = Games::get( $post );
		$core = sanitize_key( (string) $request->get_param( 'core' ) );
		if ( ! $game['system'] || ! in_array( $core, $game['system']['cores'], true ) ) {
			$core = Games::resolved_core( $game );
		}

		$shot_tmp  = null;
		$shot_mime = '';
		if ( isset( $files['screenshot'] ) && empty( $files['screenshot']['error'] ) && (int) $files['screenshot']['size'] <= self::SHOT_MAX ) {
			$img = wp_getimagesize( $files['screenshot']['tmp_name'] );
			if ( $img && in_array( $img['mime'], array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) {
				$shot_tmp  = $files['screenshot']['tmp_name'];
				$shot_mime = $img['mime'];
			}
		}

		$result = self::add_state( $user_id, $post->ID, $files['state']['tmp_name'], $shot_tmp, $shot_mime, $enc, $core );
		if ( ! is_wp_error( $result ) ) {
			do_action( 'retrovault_cloud_saved', $post->ID, $user_id, $result );
		}
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function rest_delete_state( $request ) {
		$post = Rest::game( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$token = (string) $request->get_param( 'slot' );
		if ( '' !== $token && ! self::valid_token( $token ) ) {
			return new \WP_Error( 'rv_bad_slot', __( 'خانة حفظ غير صالحة.', 'retrovault-core' ), array( 'status' => 400 ) );
		}
		$deleted = self::delete_state( get_current_user_id(), $post->ID, $token );
		if ( is_wp_error( $deleted ) ) { return $deleted; }
		return self::rest_summary( $request );
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function rest_state_file( $request ) {
		$user_id = get_current_user_id();
		$game_id = (int) $request['id'];
		$token   = (string) $request->get_param( 'slot' );
		$token   = self::valid_token( $token ) ? $token : '';
		$entry   = self::find_state( $user_id, $game_id, $token );
		$kind    = 'shot' === $request['kind'] ? 'shot' : 'state';

		if ( ! $entry || ( 'shot' === $kind && empty( $entry['shot'] ) ) ) {
			return new \WP_Error( 'rv_no_save', __( 'لا يوجد حفظ سحابي لهذه اللعبة.', 'retrovault-core' ), array( 'status' => 404 ) );
		}
		return self::send_file(
			self::path( $user_id, $game_id, $entry['token'], $kind ),
			'shot' === $kind ? $entry['shot'] : 'application/octet-stream',
			$entry['enc']
		);
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function rest_sram_info( $request ) {
		$post = Rest::game( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$record = self::game( get_current_user_id(), $post->ID );
		return rest_ensure_response( $record['sram'] ? self::describe_sram( $record['sram'] ) : array( 'exists' => false ) );
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function rest_upload_sram( $request ) {
		$post = Rest::game( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$files = $request->get_file_params();
		$enc   = 'gzip' === $request->get_param( 'encoding' ) ? 'gzip' : 'raw';
		$check = self::check_upload( isset( $files['sram'] ) ? $files['sram'] : null, self::SRAM_MAX, $enc );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$base = $request->get_param( 'base' );
		if ( ! is_string( $base ) || ! preg_match( '/^[a-f0-9\\-]{0,40}$/', $base ) ) {
			return new \WP_Error( 'rv_sram_base_required', __( 'حدّث صفحة اللعبة قبل مزامنة الحفظ.', 'retrovault-core' ), array( 'status' => 428 ) );
		}
		$hash   = substr( preg_replace( '/[^a-f0-9\-]/', '', strtolower( (string) $request->get_param( 'hash' ) ) ), 0, 40 );
		$result = self::put_sram( get_current_user_id(), $post->ID, $files['sram']['tmp_name'], $enc, $hash, $base );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function rest_delete_sram( $request ) {
		$post = Rest::game( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$deleted = self::delete_sram( get_current_user_id(), $post->ID );
		if ( is_wp_error( $deleted ) ) { return $deleted; }
		return rest_ensure_response( array( 'exists' => false ) );
	}

	/**
	 * @param \WP_REST_Request $request الطلب.
	 */
	public static function rest_sram_file( $request ) {
		$user_id = get_current_user_id();
		$game_id = (int) $request['id'];
		$record  = self::game( $user_id, $game_id );
		if ( ! $record['sram'] ) {
			return new \WP_Error( 'rv_no_save', __( 'لا يوجد حفظ لهذه اللعبة في حسابك.', 'retrovault-core' ), array( 'status' => 404 ) );
		}
		return self::send_file( self::path( $user_id, $game_id, $record['sram']['token'], 'srm' ), 'application/octet-stream', $record['sram']['enc'], $record['sram']['hash'] );
	}

	/**
	 * إرسال ملف لصاحبه ثم الإنهاء.
	 *
	 * @param string $file المسار.
	 * @param string $type نوع المحتوى.
	 * @param string $enc  gzip|raw (يُرسل في X-RV-Encoding ويفك المتصفح الضغط بنفسه).
	 * @return \WP_Error عند غياب الملف.
	 */
	private static function send_file( $file, $type, $enc, $hash = '' ) {
		if ( '' === $file || ! is_readable( $file ) ) {
			return new \WP_Error( 'rv_no_save', __( 'ملف الحفظ غير موجود.', 'retrovault-core' ), array( 'status' => 404 ) );
		}
		if ( function_exists( 'ini_set' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
			@ini_set( 'zlib.output_compression', 'Off' );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-RV-Encoding: ' . $enc );
		if ( '' !== $hash ) { header( 'X-RV-Hash: ' . $hash ); }
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
