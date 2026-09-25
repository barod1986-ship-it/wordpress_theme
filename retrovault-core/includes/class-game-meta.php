<?php
/**
 * حقول بيانات اللعبة (الملف، النواة، السنة، الإصدار، اللقطات...).
 *
 * مصدر واحد للحقيقة: الدالة fields() تعرّف كل حقل ونوعه وقيمته الافتراضية،
 * ومنها يُبنى التسجيل والحفظ والقراءة.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Game_Meta {

	const PREFIX = '_rv_';

	/** حقول إحصائية تُملأ تلقائياً ولا تُحرَّر يدوياً. */
	const STATS = array(
		'_rv_play_count'     => 0,
		'_rv_download_count' => 0,
		'_rv_rating_avg'     => 0,
		'_rv_rating_count'   => 0,
		'_rv_rating_score'   => 0,
		'_rv_trend_score'    => 0,
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 6 );
		add_action( 'add_meta_boxes_' . Post_Types::GAME, array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . Post_Types::GAME, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'save_post_' . Post_Types::GAME, array( __CLASS__, 'ensure_defaults' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'default_hidden_meta_boxes', array( __CLASS__, 'show_excerpt' ), 10, 2 );
		add_filter( 'rest_pre_insert_' . Post_Types::GAME, array( __CLASS__, 'validate_rest' ), 10, 2 );
	}

	/**
	 * تعريف الحقول.
	 *
	 * @return array<string,array>
	 */
	public static function fields() {
		return array(
			'rom_id'       => array( 'type' => 'integer', 'default' => 0 ),
			'rom_url'      => array( 'type' => 'string', 'default' => '', 'sanitize' => 'url' ),
			'core'         => array( 'type' => 'string', 'default' => '' ),
			'year'         => array( 'type' => 'integer', 'default' => 0 ),
			'version'      => array( 'type' => 'string', 'default' => '' ),
			'players'      => array( 'type' => 'integer', 'default' => 1 ),
			'status'       => array( 'type' => 'string', 'default' => 'released' ),
			'languages'    => array( 'type' => 'string', 'default' => '' ),
			'banner_id'    => array( 'type' => 'integer', 'default' => 0 ),
			'screenshots'  => array( 'type' => 'array', 'default' => array() ),
			'trailer'      => array( 'type' => 'string', 'default' => '', 'sanitize' => 'url' ),
			'controls'     => array( 'type' => 'string', 'default' => '', 'sanitize' => 'html' ),
			'changelog'    => array( 'type' => 'string', 'default' => '', 'sanitize' => 'html' ),
			'credits'      => array( 'type' => 'string', 'default' => '', 'sanitize' => 'html' ),
			'featured'     => array( 'type' => 'boolean', 'default' => false ),
			'downloadable' => array( 'type' => 'boolean', 'default' => false ),
		);
	}

	public static function register() {
		$auth = static function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};

		foreach ( self::fields() as $name => $def ) {
			$args = array(
				'type'          => $def['type'],
				'single'        => true,
				'default'       => $def['default'],
				'auth_callback' => $auth,
				'show_in_rest'  => true,
				'sanitize_callback' => static function ( $value ) use ( $name, $def ) {
					return self::sanitize( $name, $value, $def, null );
				},
			);
			if ( 'array' === $def['type'] ) {
				$args['show_in_rest'] = array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				);
			}
			register_post_meta( Post_Types::GAME, self::PREFIX . $name, $args );
		}

		foreach ( self::STATS as $key => $default ) {
			register_post_meta(
				Post_Types::GAME,
				$key,
				array(
					'type'          => ( false !== strpos( $key, '_avg' ) || false !== strpos( $key, '_score' ) ) ? 'number' : 'integer',
					'single'        => true,
					'default'       => $default,
					'auth_callback' => '__return_false',
					'show_in_rest'  => true,
				)
			);
		}
	}

	/**
	 * قراءة كل الحقول بعد تحويل أنواعها.
	 *
	 * @param int $post_id رقم اللعبة.
	 * @return array
	 */
	public static function values( $post_id ) {
		$out = array();
		foreach ( self::fields() as $name => $def ) {
			$raw = get_post_meta( $post_id, self::PREFIX . $name, true );
			switch ( $def['type'] ) {
				case 'integer':
					$out[ $name ] = (int) $raw;
					break;
				case 'boolean':
					$out[ $name ] = ! empty( $raw );
					break;
				case 'array':
					$out[ $name ] = is_array( $raw ) ? array_values( array_filter( array_map( 'absint', $raw ) ) ) : array();
					break;
				default:
					$out[ $name ] = is_string( $raw ) ? $raw : '';
			}
		}
		return $out;
	}

	/**
	 * كل لعبة يجب أن تملك كل الحقول في قاعدة البيانات، لأن الفرز (الأعلى تقييماً،
	 * الأكثر لعباً...) يعتمد على وجودها. يعمل حتى لو أُنشئت اللعبة عبر REST أو استيراد.
	 *
	 * @param int $post_id رقم اللعبة.
	 */
	public static function ensure_defaults( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$defaults = self::STATS;
		foreach ( self::fields() as $name => $def ) {
			$value = $def['default'];
			if ( 'boolean' === $def['type'] ) {
				$value = '0';
			}
			$defaults[ self::PREFIX . $name ] = $value;
		}
		foreach ( $defaults as $key => $value ) {
			if ( ! metadata_exists( 'post', $post_id, $key ) ) {
				add_post_meta( $post_id, $key, $value, true );
			}
		}
	}

	/**
	 * @param int      $post_id رقم اللعبة.
	 * @param \WP_Post $post    اللعبة.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['rv_game_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rv_game_nonce'] ) ), 'rv_game_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- تُعقَّم كل قيمة أدناه حسب نوعها.
		$input       = ( isset( $_POST['rv'] ) && is_array( $_POST['rv'] ) ) ? wp_unslash( $_POST['rv'] ) : array();
		$system      = Games::system_for( $post_id );
		$old_version = (string) get_post_meta( $post_id, self::PREFIX . 'version', true );
		$rom_error   = null;

		foreach ( self::fields() as $name => $def ) {
			$raw = isset( $input[ $name ] ) ? $input[ $name ] : null;
			if ( 'rom_id' === $name ) {
				$valid = Roms::validate_attachment( absint( $raw ), true );
				if ( is_wp_error( $valid ) ) {
					$rom_error = $valid;
					continue; // لا تستبدل الملف الحالي بمرفق غير صالح أو غير مسموح.
				}
			}
			update_post_meta( $post_id, self::PREFIX . $name, self::sanitize( $name, $raw, $def, $system ) );
		}

		Games::flush( $post_id );
		self::check( $post_id );
		if ( $rom_error ) {
			$key      = 'rv_notices_' . get_current_user_id();
			$messages = (array) get_transient( $key );
			$messages[] = array( 'error', $rom_error->get_error_message() );
			set_transient( $key, array_filter( $messages ), MINUTE_IN_SECONDS );
		}

		$new_version = (string) get_post_meta( $post_id, self::PREFIX . 'version', true );
		if ( '' !== $new_version && $new_version !== $old_version ) {
			Notifier::game_version_changed( $post_id, $new_version, ! empty( $input['notify'] ) );
		}
	}

	/**
	 * @param string     $name   اسم الحقل.
	 * @param mixed      $raw    القيمة الخام.
	 * @param array      $def    تعريف الحقل.
	 * @param array|null $system بيانات النظام.
	 * @return mixed
	 */
	private static function sanitize( $name, $raw, $def, $system ) {
		if ( 'integer' === $def['type'] ) {
			$value = absint( is_scalar( $raw ) ? $raw : 0 );
			if ( 'players' === $name ) {
				$value = max( 1, min( 8, $value ? $value : 1 ) );
			}
			if ( 'year' === $name && $value && ( $value < 1970 || $value > 2100 ) ) {
				$value = 0;
			}
			return $value;
		}
		if ( 'boolean' === $def['type'] ) {
			return empty( $raw ) ? '0' : '1';
		}
		if ( 'array' === $def['type'] ) {
			$ids = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
			return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		}

		$raw  = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		$kind = isset( $def['sanitize'] ) ? $def['sanitize'] : 'text';
		if ( 'url' === $kind ) {
			return esc_url_raw( $raw );
		}
		if ( 'html' === $kind ) {
			return wp_kses_post( $raw );
		}

		$value = sanitize_text_field( $raw );
		if ( 'status' === $name && ! array_key_exists( $value, Games::statuses() ) ) {
			$value = 'released';
		}
		if ( 'core' === $name ) {
			$cores = $system ? $system['cores'] : array();
			if ( ! $system ) {
				foreach ( Systems::all() as $entry ) {
					$cores = array_merge( $cores, $entry['cores'] );
				}
			}
			$value = in_array( $value, $cores, true ) ? $value : '';
		}
		return $value;
	}

	/** Validate the attachment before REST creates/updates any game data. */
	public static function validate_rest( $post, $request ) {
		$meta = $request->get_param( 'meta' );
		$key  = self::PREFIX . 'rom_id';
		if ( is_array( $meta ) && array_key_exists( $key, $meta ) ) {
			$valid = Roms::validate_attachment( absint( $meta[ $key ] ), true );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}
		return $post;
	}

	/**
	 * فحص ما بعد الحفظ: تنبيه عند نقص النظام أو الملف أو عدم تطابق الامتداد.
	 *
	 * @param int $post_id رقم اللعبة.
	 */
	private static function check( $post_id ) {
		$game = Games::get( $post_id );
		if ( ! $game ) {
			return;
		}
		$messages = array();

		if ( ! $game['system'] ) {
			$messages[] = array( 'warning', __( 'اختر نظاماً للعبة من صندوق «الأنظمة» حتى يعرف الموقع أي محاكٍ يستخدم.', 'retrovault-core' ) );
		} elseif ( '' === $game['system']['ejs'] ) {
			$messages[] = array( 'error', __( 'النظام المختار غير مربوط بمحاكٍ. افتح الألعاب ← الأنظمة وحدّد «المحاكي المستخدم» لهذا النظام.', 'retrovault-core' ) );
		}

		if ( '' === $game['rom']['url'] ) {
			$messages[] = $game['rom']['id'] ? array( 'error', __( 'ملف اللعبة مفقود أو تعذّرت حمايته. أوقفنا تشغيله وتنزيله؛ تحقّق من الملف وصلاحيات مجلد الرفع ثم احفظ اللعبة مجدداً.', 'retrovault-core' ) ) : array( 'warning', __( 'لم يُحدَّد ملف اللعبة بعد؛ سيظهر المشغّل برسالة «غير متاح» إلى أن ترفعه.', 'retrovault-core' ) );
		} elseif ( $game['system'] && $game['rom']['ext'] ) {
			$allowed = array_merge( $game['system']['ext'], array( 'zip', '7z' ) );
			if ( ! in_array( $game['rom']['ext'], $allowed, true ) ) {
				$messages[] = array(
					'error',
					sprintf(
						/* translators: 1: extension, 2: system name, 3: expected extensions */
						__( 'امتداد الملف ‎.%1$s غير معتاد لنظام %2$s. الامتدادات المتوقعة: %3$s', 'retrovault-core' ),
						$game['rom']['ext'],
						$game['system']['name'],
						'.' . implode( ' .', $allowed )
					),
				);
			}
		}

		if ( $messages ) {
			set_transient( 'rv_notices_' . get_current_user_id(), $messages, MINUTE_IN_SECONDS );
		}
	}

	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Post_Types::GAME !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}
		$key      = 'rv_notices_' . get_current_user_id();
		$messages = get_transient( $key );
		if ( ! $messages ) {
			return;
		}
		delete_transient( $key );
		foreach ( (array) $messages as $message ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $message[0] ), esc_html( $message[1] ) );
		}
	}

	/**
	 * ووردبريس يخفي صندوق «المقتطف» افتراضياً؛ هنا نظهره لأنه وصف البطاقة.
	 *
	 * @param string[]   $hidden الصناديق المخفية.
	 * @param \WP_Screen $screen الشاشة.
	 */
	public static function show_excerpt( $hidden, $screen ) {
		if ( $screen && Post_Types::GAME === $screen->post_type ) {
			$hidden = array_diff( $hidden, array( 'postexcerpt' ) );
		}
		return $hidden;
	}

	public static function boxes() {
		add_meta_box( 'rv_game_data', __( 'بيانات اللعبة', 'retrovault-core' ), array( __CLASS__, 'render' ), Post_Types::GAME, 'normal', 'high' );
		add_meta_box( 'rv_game_side', __( 'العرض والإحصائيات', 'retrovault-core' ), array( __CLASS__, 'render_side' ), Post_Types::GAME, 'side', 'default' );
	}

	/**
	 * @param \WP_Post $post اللعبة.
	 */
	public static function render( $post ) {
		wp_nonce_field( 'rv_game_save', 'rv_game_nonce' );
		$v      = self::values( $post->ID );
		$system = Games::system_for( $post->ID );
		$rom    = $v['rom_id'] ? Games::rom( $post->ID, $v ) : null;
		$banner = $v['banner_id'] ? wp_get_attachment_image_url( $v['banner_id'], 'medium' ) : '';
		?>
		<div class="rv-meta">
			<section class="rv-meta__section rv-meta__section--wide">
				<h3><?php esc_html_e( 'ملف اللعبة', 'retrovault-core' ); ?></h3>
				<div class="rv-media rv-media--file" data-rv-media data-type="">
					<input type="hidden" name="rv[rom_id]" value="<?php echo $v['rom_id'] ? esc_attr( $v['rom_id'] ) : ''; ?>">
					<div class="rv-media__preview" data-rv-preview>
						<?php
						if ( $rom && $rom['file'] ) {
							echo '<code>' . esc_html( $rom['file'] ) . '</code>';
							if ( $rom['size'] ) {
								echo ' <span>' . esc_html( size_format( $rom['size'] ) ) . '</span>';
							}
							echo ' <span class="rv-rom-lock' . ( $rom['protected'] ? '' : ' is-off' ) . '">' . esc_html(
								$rom['protected']
									? __( '— محمي: لا يُفتح إلا داخل المشغّل', 'retrovault-core' )
									: __( '— غير محمي بعد: يُحمى عند حفظ اللعبة', 'retrovault-core' )
							) . '</span>';
						}
						?>
					</div>
					<button type="button" class="button button-primary" data-rv-pick data-title="<?php esc_attr_e( 'اختر ملف اللعبة', 'retrovault-core' ); ?>" data-button="<?php esc_attr_e( 'استخدام هذا الملف', 'retrovault-core' ); ?>"><?php esc_html_e( 'رفع / اختيار ملف', 'retrovault-core' ); ?></button>
					<button type="button" class="button-link rv-media__clear" data-rv-clear <?php echo $v['rom_id'] ? '' : 'hidden'; ?>><?php esc_html_e( 'إزالة', 'retrovault-core' ); ?></button>
				</div>
				<p class="description">
					<?php esc_html_e( 'الامتدادات المتوقعة لهذا النظام:', 'retrovault-core' ); ?>
					<code id="rv-ext-hint"><?php echo $system ? esc_html( '.' . implode( ' .', $system['ext'] ) ) : esc_html__( 'اختر النظام أولاً', 'retrovault-core' ); ?></code>
					<?php esc_html_e( '— ويُقبل ملف ‎.zip أيضاً. لألعاب PS1 استخدم ‎.chd أو ‎.pbp أو ملف zip يضم ‎.cue و‎.bin (أو ملف PS-EXE مضغوطاً في zip).', 'retrovault-core' ); ?>
				</p>
				<p>
					<label for="rv-rom-url"><?php esc_html_e( 'أو رابط مباشر للملف (للملفات الكبيرة المرفوعة عبر FTP). الرابط المباشر لا يُحمى من التنزيل:', 'retrovault-core' ); ?></label>
					<input type="url" class="large-text code" id="rv-rom-url" name="rv[rom_url]" value="<?php echo esc_attr( $v['rom_url'] ); ?>" placeholder="https://">
				</p>
				<p>
					<label for="rv-core"><?php esc_html_e( 'نواة المحاكي', 'retrovault-core' ); ?></label>
					<select id="rv-core" name="rv[core]" data-current="<?php echo esc_attr( $v['core'] ); ?>">
						<option value=""><?php esc_html_e( 'الافتراضية للنظام', 'retrovault-core' ); ?></option>
						<?php
						if ( $system ) {
							foreach ( $system['cores'] as $core ) {
								printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $core ), selected( $v['core'], $core, false ) );
							}
						}
						?>
					</select>
					<span class="description"><?php esc_html_e( 'غيّرها فقط إذا واجهت مشكلة توافق مع النواة الافتراضية.', 'retrovault-core' ); ?></span>
				</p>
			</section>

			<section class="rv-meta__section">
				<h3><?php esc_html_e( 'التفاصيل', 'retrovault-core' ); ?></h3>
				<div class="rv-meta__grid">
					<p>
						<label for="rv-status"><?php esc_html_e( 'حالة اللعبة', 'retrovault-core' ); ?></label>
						<select id="rv-status" name="rv[status]">
							<?php foreach ( Games::statuses() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $v['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="rv-version"><?php esc_html_e( 'رقم الإصدار', 'retrovault-core' ); ?></label>
						<input type="text" id="rv-version" name="rv[version]" value="<?php echo esc_attr( $v['version'] ); ?>" placeholder="1.0.0">
						<?php $rv_followers = Favorites::count( $post->ID ); ?>
						<label class="rv-inline-check"><input type="checkbox" name="rv[notify]" value="1" checked>
							<?php
							/* translators: %s: followers count */
							echo esc_html( sprintf( __( 'راسل المتابعين عند تغيير الإصدار (%s)', 'retrovault-core' ), number_format_i18n( $rv_followers ) ) );
							?>
						</label>
					</p>
					<p>
						<label for="rv-year"><?php esc_html_e( 'سنة الإصدار', 'retrovault-core' ); ?></label>
						<input type="number" id="rv-year" name="rv[year]" min="1970" max="2100" value="<?php echo $v['year'] ? esc_attr( $v['year'] ) : ''; ?>">
					</p>
					<p>
						<label for="rv-players"><?php esc_html_e( 'عدد اللاعبين', 'retrovault-core' ); ?></label>
						<select id="rv-players" name="rv[players]">
							<?php for ( $i = 1; $i <= 4; $i++ ) : ?>
								<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $v['players'], $i ); ?>><?php echo esc_html( Games::players_label( $i ) ); ?></option>
							<?php endfor; ?>
						</select>
					</p>
					<p class="rv-meta__full">
						<label for="rv-languages"><?php esc_html_e( 'لغات اللعبة', 'retrovault-core' ); ?></label>
						<input type="text" id="rv-languages" class="regular-text" name="rv[languages]" value="<?php echo esc_attr( $v['languages'] ); ?>" placeholder="<?php esc_attr_e( 'العربية، English', 'retrovault-core' ); ?>">
					</p>
				</div>
			</section>

			<section class="rv-meta__section">
				<h3><?php esc_html_e( 'الصور والفيديو', 'retrovault-core' ); ?></h3>
				<p class="description"><?php esc_html_e( 'الغلاف من صندوق «غلاف اللعبة» (يفضّل عمودياً 3:4، مثلاً 600×800).', 'retrovault-core' ); ?></p>
				<div class="rv-media rv-media--image" data-rv-media data-type="image">
					<label><?php esc_html_e( 'صورة عريضة للعرض (اختيارية، 16:9)', 'retrovault-core' ); ?></label>
					<input type="hidden" name="rv[banner_id]" value="<?php echo $v['banner_id'] ? esc_attr( $v['banner_id'] ) : ''; ?>">
					<div class="rv-media__preview" data-rv-preview><?php echo $banner ? '<img src="' . esc_url( $banner ) . '" alt="">' : ''; ?></div>
					<button type="button" class="button" data-rv-pick data-title="<?php esc_attr_e( 'اختر صورة عريضة', 'retrovault-core' ); ?>" data-button="<?php esc_attr_e( 'استخدام الصورة', 'retrovault-core' ); ?>"><?php esc_html_e( 'اختيار صورة', 'retrovault-core' ); ?></button>
					<button type="button" class="button-link rv-media__clear" data-rv-clear <?php echo $v['banner_id'] ? '' : 'hidden'; ?>><?php esc_html_e( 'إزالة', 'retrovault-core' ); ?></button>
				</div>

				<div class="rv-gallery" data-rv-gallery>
					<label><?php esc_html_e( 'لقطات من اللعبة', 'retrovault-core' ); ?></label>
					<input type="hidden" name="rv[screenshots]" value="<?php echo esc_attr( implode( ',', $v['screenshots'] ) ); ?>">
					<ul class="rv-gallery__list" data-rv-gallery-list>
						<?php foreach ( $v['screenshots'] as $shot_id ) : ?>
							<?php $thumb = wp_get_attachment_image_url( $shot_id, 'thumbnail' ); ?>
							<?php if ( $thumb ) : ?>
								<li data-id="<?php echo esc_attr( $shot_id ); ?>"><img src="<?php echo esc_url( $thumb ); ?>" alt=""><button type="button" class="rv-gallery__remove" aria-label="<?php esc_attr_e( 'إزالة اللقطة', 'retrovault-core' ); ?>">×</button></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
					<button type="button" class="button" data-rv-gallery-add data-title="<?php esc_attr_e( 'اختر لقطات من اللعبة', 'retrovault-core' ); ?>" data-button="<?php esc_attr_e( 'إضافة اللقطات', 'retrovault-core' ); ?>"><?php esc_html_e( 'إضافة لقطات', 'retrovault-core' ); ?></button>
					<p class="description"><?php esc_html_e( 'اسحب لإعادة الترتيب. ارفع اللقطات بالدقة الأصلية للجهاز (مثل 240×160 لـ GBA) وسيعرضها القالب بحواف بكسل حادة دون تنعيم.', 'retrovault-core' ); ?></p>
				</div>

				<p>
					<label for="rv-trailer"><?php esc_html_e( 'رابط فيديو العرض (YouTube أو غيره)', 'retrovault-core' ); ?></label>
					<input type="url" class="large-text" id="rv-trailer" name="rv[trailer]" value="<?php echo esc_attr( $v['trailer'] ); ?>" placeholder="https://www.youtube.com/watch?v=">
				</p>
			</section>

			<section class="rv-meta__section rv-meta__section--wide">
				<h3><?php esc_html_e( 'نصوص إضافية', 'retrovault-core' ); ?></h3>
				<p>
					<label for="rv-controls"><?php esc_html_e( 'ملاحظات التحكم', 'retrovault-core' ); ?></label>
					<textarea id="rv-controls" name="rv[controls]" rows="3" class="large-text"><?php echo esc_textarea( $v['controls'] ); ?></textarea>
					<span class="description"><?php esc_html_e( 'جدول الأزرار الافتراضي للنظام يظهر تلقائياً؛ اكتب هنا ما يخص لعبتك فقط (مثل: اضغط B مطولاً للركض).', 'retrovault-core' ); ?></span>
				</p>
				<p>
					<label for="rv-changelog"><?php esc_html_e( 'سجل التحديثات', 'retrovault-core' ); ?></label>
					<textarea id="rv-changelog" name="rv[changelog]" rows="5" class="large-text"><?php echo esc_textarea( $v['changelog'] ); ?></textarea>
				</p>
				<p>
					<label for="rv-credits"><?php esc_html_e( 'الشكر والمساهمون', 'retrovault-core' ); ?></label>
					<textarea id="rv-credits" name="rv[credits]" rows="3" class="large-text"><?php echo esc_textarea( $v['credits'] ); ?></textarea>
				</p>
			</section>

		</div>
		<?php
	}

	/**
	 * @param \WP_Post $post اللعبة.
	 */
	public static function render_side( $post ) {
		$v    = self::values( $post->ID );
		$game = Games::get( $post );
		?>
		<p><label><input type="checkbox" name="rv[featured]" value="1" <?php checked( $v['featured'] ); ?>> <?php esc_html_e( 'لعبة مميزة (تظهر أولاً في الرئيسية)', 'retrovault-core' ); ?></label></p>
		<p>
			<label><input type="checkbox" name="rv[downloadable]" value="1" <?php checked( $v['downloadable'] ); ?>> <?php esc_html_e( 'السماح بتنزيل ملف اللعبة', 'retrovault-core' ); ?></label>
			<?php if ( ! Settings::get( 'downloads' ) ) : ?>
				<br><span class="description"><?php esc_html_e( 'التنزيل معطّل من إعدادات المكتبة.', 'retrovault-core' ); ?></span>
			<?php endif; ?>
		</p>
		<?php if ( $game && 'auto-draft' !== $post->post_status ) : ?>
			<ul class="rv-side-stats">
				<li><span><?php esc_html_e( 'مرات اللعب', 'retrovault-core' ); ?></span><strong><?php echo esc_html( number_format_i18n( $game['plays'] ) ); ?></strong></li>
				<li><span><?php esc_html_e( 'التنزيلات', 'retrovault-core' ); ?></span><strong><?php echo esc_html( number_format_i18n( $game['downloads'] ) ); ?></strong></li>
				<li><span><?php esc_html_e( 'في مفضلة الأعضاء', 'retrovault-core' ); ?></span><strong><?php echo esc_html( number_format_i18n( Favorites::count( $post->ID ) ) ); ?></strong></li>
				<li><span><?php esc_html_e( 'التقييم', 'retrovault-core' ); ?></span><strong><?php echo $game['rating']['count'] ? esc_html( number_format_i18n( $game['rating']['average'], 1 ) . ' / 5 (' . number_format_i18n( $game['rating']['count'] ) . ')' ) : '—'; ?></strong></li>
			</ul>
			<?php if ( $game['playable'] ) : ?>
				<p><a class="button" href="<?php echo esc_url( $game['player_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'تجربة المشغّل', 'retrovault-core' ); ?></a></p>
			<?php endif; ?>
			<p class="rv-side-devlog">
				<a href="<?php echo esc_url( admin_url( 'post-new.php?rv_game=' . (int) $post->ID ) ); ?>"><?php esc_html_e( 'اكتب تدوينة عن هذه اللعبة', 'retrovault-core' ); ?></a>
				<?php
				$rv_devlog_count = Devlog::count_for_game( $post->ID );
				if ( $rv_devlog_count ) {
					/* translators: %s: number of devlog posts */
					echo '<br><span class="description">' . esc_html( sprintf( __( 'مرتبطة بـ %s من يوميات التطوير', 'retrovault-core' ), number_format_i18n( $rv_devlog_count ) ) ) . '</span>';
				}
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param string $hook الصفحة الحالية.
	 */
	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$is_game   = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && Post_Types::GAME === $screen->post_type;
		$is_system = in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) && Post_Types::SYSTEM === $screen->taxonomy;
		$is_opts   = false !== strpos( (string) $hook, 'retrovault-settings' ) || false !== strpos( (string) $hook, 'retrovault-stats' ) || 'index.php' === $hook;
		$is_list   = 'edit.php' === $hook && Post_Types::GAME === $screen->post_type;
		$is_post   = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && 'post' === $screen->post_type;
		if ( $is_post ) {
			wp_enqueue_style( 'retrovault-admin', RETROVAULT_URL . 'assets/admin.css', array(), RETROVAULT_VERSION );
			return;
		}
		if ( ! ( $is_game || $is_system || $is_opts || $is_list ) ) {
			return;
		}

		wp_enqueue_style( 'retrovault-admin', RETROVAULT_URL . 'assets/admin.css', array(), RETROVAULT_VERSION );
		if ( $is_list ) {
			return;
		}

		$deps = array( 'jquery', 'wp-color-picker' );
		wp_enqueue_style( 'wp-color-picker' );
		if ( $is_game ) {
			wp_enqueue_media();
			$deps[] = 'jquery-ui-sortable';
		}
		wp_enqueue_script( 'retrovault-admin', RETROVAULT_URL . 'assets/admin.js', $deps, RETROVAULT_VERSION, true );

		$systems = array();
		foreach ( Systems::all() as $key => $system ) {
			$systems[ $key ] = array(
				'cores' => $system['cores'],
				'ext'   => $system['ext'],
			);
		}
		wp_localize_script(
			'retrovault-admin',
			'RVAdmin',
			array(
				'systems' => $systems,
				'i18n'    => array(
					'defaultCore' => __( 'الافتراضية للنظام', 'retrovault-core' ),
					'pickSystem'  => __( 'اختر النظام أولاً', 'retrovault-core' ),
					'remove'      => __( 'إزالة اللقطة', 'retrovault-core' ),
				),
			)
		);
	}
}
