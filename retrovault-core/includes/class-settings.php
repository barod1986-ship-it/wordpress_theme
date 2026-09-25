<?php
/**
 * إعدادات المكتبة: الألعاب ← الإعدادات.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'retrovault_settings';

	/** EmulatorJS يُحمَّل افتراضياً من الـ CDN الرسمي (آخر إصدار مستقر). */
	const CDN = 'https://cdn.emulatorjs.org/stable/data/';

	/** @var array|null */
	private static $cache = null;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
	}

	public static function defaults() {
		return array(
			'data_path'   => self::CDN,
			'accent'      => '#D63A3A',
			'per_page'    => 24,
			'downloads'   => 1,
			'language'    => 'auto',
			'delete_data' => 0,
			'cloud_saves' => 1,
			'sram_sync'   => 1,
			'save_slots'  => 3,
			'save_max_mb' => 32,
			'netplay'     => 0,
			'netplay_url' => '',
			'notify_email' => 1,
			'pwa'          => 1,
			'app_name'     => '',
		);
	}

	/**
	 * @param string|null $key مفتاح الإعداد، أو null لكل الإعدادات.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			self::$cache = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * مسار مجلد data الخاص بـ EmulatorJS (ينتهي دائماً بـ /).
	 */
	public static function data_path() {
		$path = trim( (string) self::get( 'data_path' ) );
		return trailingslashit( $path ? $path : self::CDN );
	}

	/**
	 * لغة واجهة المحاكي بصيغة ملفات EmulatorJS (مثل ar-AR).
	 */
	public static function emulator_language() {
		$lang = (string) self::get( 'language' );
		if ( 'auto' !== $lang ) {
			return $lang;
		}
		$map    = array(
			'ar' => 'ar-AR',
			'en' => 'en-US',
			'de' => 'de-GER',
			'el' => 'el-GR',
			'es' => 'es-ES',
			'fa' => 'fa-AF',
			'fr' => 'af-FR',
			'hi' => 'hi-HI',
			'it' => 'it-IT',
			'ja' => 'ja-JA',
			'ko' => 'ko-KO',
			'pt' => 'pt-BR',
			'ro' => 'ro-RO',
			'ru' => 'ru-RU',
			'tr' => 'tr-TR',
			'vi' => 'vi-VN',
			'zh' => 'zh-CN',
		);
		$prefix = strtolower( substr( determine_locale(), 0, 2 ) );
		return isset( $map[ $prefix ] ) ? $map[ $prefix ] : 'en-US';
	}

	public static function register() {
		register_setting(
			'retrovault',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input القيم المرسلة من النموذج.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$path             = isset( $input['data_path'] ) ? esc_url_raw( trim( $input['data_path'] ) ) : '';
		$out['data_path'] = $path ? trailingslashit( $path ) : self::CDN;

		$accent        = isset( $input['accent'] ) ? sanitize_hex_color( $input['accent'] ) : '';
		$out['accent'] = $accent ? $accent : $out['accent'];

		$out['per_page'] = isset( $input['per_page'] ) ? max( 6, min( 96, absint( $input['per_page'] ) ) ) : 24;

		$out['downloads']   = empty( $input['downloads'] ) ? 0 : 1;
		$out['delete_data'] = empty( $input['delete_data'] ) ? 0 : 1;
		$out['cloud_saves'] = empty( $input['cloud_saves'] ) ? 0 : 1;
		$out['sram_sync']   = empty( $input['sram_sync'] ) ? 0 : 1;
		$out['save_slots']  = isset( $input['save_slots'] ) ? max( 1, min( 10, absint( $input['save_slots'] ) ) ) : 3;
		$out['save_max_mb'] = isset( $input['save_max_mb'] ) ? max( 1, min( 256, absint( $input['save_max_mb'] ) ) ) : 32;
		$out['netplay']     = empty( $input['netplay'] ) ? 0 : 1;
		$out['notify_email'] = empty( $input['notify_email'] ) ? 0 : 1;
		$out['pwa']          = empty( $input['pwa'] ) ? 0 : 1;
		$out['app_name']     = isset( $input['app_name'] ) ? sanitize_text_field( $input['app_name'] ) : '';
		$out['netplay_url'] = isset( $input['netplay_url'] ) ? untrailingslashit( esc_url_raw( trim( $input['netplay_url'] ) ) ) : '';

		$lang            = isset( $input['language'] ) ? sanitize_text_field( $input['language'] ) : 'auto';
		$out['language'] = in_array( $lang, array( 'auto', 'ar-AR', 'en-US' ), true ) ? $lang : 'auto';

		self::flush_cache();
		return $out;
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Types::GAME,
			__( 'إعدادات المكتبة', 'retrovault-core' ),
			__( 'الإعدادات', 'retrovault-core' ),
			'manage_options',
			'retrovault-settings',
			array( __CLASS__, 'page' )
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o    = self::get();
		$name = self::OPTION;
		?>
		<div class="wrap rv-settings">
			<h1><?php esc_html_e( 'إعدادات المكتبة', 'retrovault-core' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'retrovault' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rv-data-path"><?php esc_html_e( 'مسار ملفات EmulatorJS', 'retrovault-core' ); ?></label></th>
						<td>
							<input type="url" class="large-text code" id="rv-data-path" name="<?php echo esc_attr( $name ); ?>[data_path]" value="<?php echo esc_attr( $o['data_path'] ); ?>">
							<p class="description">
								<?php esc_html_e( 'الافتراضي هو الـ CDN الرسمي (آخر إصدار مستقر). لتثبيت إصدار محدد استخدم مثلاً https://cdn.emulatorjs.org/4.2.3/data/ — أو ارفع مجلد data من إصدارات EmulatorJS على GitHub إلى موقعك وضع رابطه هنا للاستضافة الذاتية.', 'retrovault-core' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rv-accent"><?php esc_html_e( 'لون واجهة المحاكي', 'retrovault-core' ); ?></label></th>
						<td><input type="text" id="rv-accent" class="rv-color-field" name="<?php echo esc_attr( $name ); ?>[accent]" value="<?php echo esc_attr( $o['accent'] ); ?>" data-default-color="#D63A3A"></td>
					</tr>
					<tr>
						<th scope="row"><label for="rv-language"><?php esc_html_e( 'لغة واجهة المحاكي', 'retrovault-core' ); ?></label></th>
						<td>
							<select id="rv-language" name="<?php echo esc_attr( $name ); ?>[language]">
								<option value="auto" <?php selected( $o['language'], 'auto' ); ?>><?php esc_html_e( 'تلقائي (حسب لغة الموقع)', 'retrovault-core' ); ?></option>
								<option value="ar-AR" <?php selected( $o['language'], 'ar-AR' ); ?>>العربية</option>
								<option value="en-US" <?php selected( $o['language'], 'en-US' ); ?>>English</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rv-per-page"><?php esc_html_e( 'عدد الألعاب في صفحة المكتبة', 'retrovault-core' ); ?></label></th>
						<td><input type="number" min="6" max="96" step="1" id="rv-per-page" name="<?php echo esc_attr( $name ); ?>[per_page]" value="<?php echo esc_attr( $o['per_page'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'تنزيل ملفات الألعاب', 'retrovault-core' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[downloads]" value="1" <?php checked( $o['downloads'], 1 ); ?>> <?php esc_html_e( 'السماح بزر «تنزيل الملف» للألعاب التي تفعّل له الخيار من صفحة اللعبة', 'retrovault-core' ); ?></label>
							<p class="description"><?php esc_html_e( 'مفيد لمن يريد تشغيل لعبتك على الجهاز الأصلي عبر فلاش كارت. المفتاح هنا عام، والتفعيل الفعلي لكل لعبة على حدة.', 'retrovault-core' ); ?></p>
							<p class="description"><?php esc_html_e( 'ملفات الألعاب المرفوعة تُنقل إلى مجلد محمي على Apache/LiteSpeed عند تفعيل قواعد ‎.htaccess، ويصل إليها المشغّل برابط مؤقت مرتبط بجلسة المتصفح. فتح الرابط مباشرة أو مشاركته وحده لا يسمح بالتنزيل. زر التنزيل (إن سمحت به) يرسل الملف دون كشف مكانه. إذا فشلت حماية المرفق يتوقف تشغيله وتنزيله. استخراج النسخة التي وصلت إلى جهاز اللاعب يظل ممكناً.', 'retrovault-core' ); ?></p>
							<p class="description"><?php esc_html_e( 'على nginx يلزم تطبيق قاعدة المنع التالية؛ الاسم العشوائي وحده لا يحمي المجلد. طبّق المنع على CDN أيضاً إن كان يقدّم ملفات الرفع مباشرة:', 'retrovault-core' ); ?></p>
							<p class="description"><code dir="ltr">location ^~ <?php echo esc_html( (string) wp_parse_url( trailingslashit( wp_upload_dir( null, false )['baseurl'] ) . Roms::DIR . '/', PHP_URL_PATH ) ); ?> { deny all; }</code></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'الحفظ السحابي للأعضاء', 'retrovault-core' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[cloud_saves]" value="1" <?php checked( $o['cloud_saves'], 1 ); ?>> <?php esc_html_e( 'زر «حفظ الحالة» في المحاكي يحفظ في حساب العضو ليكمل من أي جهاز', 'retrovault-core' ); ?></label>
							<p class="description"><?php esc_html_e( 'مضغوط ومخزّن في مجلد محمي. الزوار يحفظون في متصفحهم فقط.', 'retrovault-core' ); ?></p>
							<p>
								<label for="rv-save-slots"><?php esc_html_e( 'عدد الحفظات المحتفظ بها لكل لعبة:', 'retrovault-core' ); ?></label>
								<input type="number" min="1" max="10" id="rv-save-slots" class="small-text" name="<?php echo esc_attr( $name ); ?>[save_slots]" value="<?php echo esc_attr( $o['save_slots'] ); ?>">
								<span class="description"><?php esc_html_e( 'كل حفظ جديد يُضاف في الأول ويُحذف الأقدم، فلا يضيع التقدّم بضغطة حفظ في لحظة سيئة.', 'retrovault-core' ); ?></span>
							</p>
							<p>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[sram_sync]" value="1" <?php checked( $o['sram_sync'], 1 ); ?>> <?php esc_html_e( 'مزامنة حفظ اللعبة الداخلي (SRAM) تلقائياً بين أجهزة العضو', 'retrovault-core' ); ?></label>
								<br><span class="description"><?php esc_html_e( 'للألعاب التي تحفظ من قائمتها (مثل ألعاب تقمّص الأدوار): يُرفع الحفظ كل 30 ثانية وعند مغادرة الصفحة، ويُستعاد عند فتح اللعبة من جهاز آخر.', 'retrovault-core' ); ?></span>
							</p>
							<p>
								<label for="rv-save-max"><?php esc_html_e( 'الحد الأقصى لحجم الحفظ (ميغابايت، بعد الضغط):', 'retrovault-core' ); ?></label>
								<input type="number" min="1" max="256" id="rv-save-max" class="small-text" name="<?php echo esc_attr( $name ); ?>[save_max_mb]" value="<?php echo esc_attr( $o['save_max_mb'] ); ?>">
							</p>
							<p class="description">
								<?php
								/* translators: %s: max upload size */
								echo esc_html( sprintf( __( 'حفظ NES وSNES وGBA صغير (أقل من 1 ميغابايت)، أما N64 وPS1 فقد يصل لعدة ميغابايت. حد الرفع الحالي في خادمك: %s.', 'retrovault-core' ), size_format( wp_max_upload_size() ) ) );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'إشعارات المتابعين', 'retrovault-core' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[notify_email]" value="1" <?php checked( $o['notify_email'], 1 ); ?>> <?php esc_html_e( 'راسل من أضاف اللعبة لمفضلته عند صدور إصدار جديد أو نشر تدوينة عنها', 'retrovault-core' ); ?></label>
							<p class="description"><?php esc_html_e( 'التحديثات تظهر للأعضاء داخل الموقع دائماً («الجديد في ألعابك»). البريد يُرسل في الخلفية على دفعات، وبحد رسالة واحدة كل 6 ساعات لتحديثات اللعبة نفسها. لوصول أفضل للبريد استخدم إضافة SMTP.', 'retrovault-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'تطبيق الويب واللعب بدون إنترنت', 'retrovault-core' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[pwa]" value="1" <?php checked( $o['pwa'], 1 ); ?>> <?php esc_html_e( 'قابل للتثبيت كتطبيق، والألعاب التي شُغّلت مرة تعمل بعدها بدون إنترنت', 'retrovault-core' ); ?></label>
							<p>
								<label for="rv-app-name"><?php esc_html_e( 'اسم التطبيق القصير (تحت الأيقونة):', 'retrovault-core' ); ?></label>
								<input type="text" id="rv-app-name" class="regular-text" maxlength="24" name="<?php echo esc_attr( $name ); ?>[app_name]" value="<?php echo esc_attr( $o['app_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
							</p>
							<p class="description"><?php esc_html_e( 'يحتاج اتصالاً آمناً (HTTPS). أيقونة التطبيق هي أيقونة الموقع من «المظهر ← تخصيص ← هوية الموقع»، وإن لم توجد تُستخدم أيقونة خرطوشة افتراضية.', 'retrovault-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'اللعب الجماعي عبر الإنترنت (تجريبي)', 'retrovault-core' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[netplay]" value="1" <?php checked( $o['netplay'], 1 ); ?>> <?php esc_html_e( 'إظهار زر اللعب الجماعي (Netplay) في المحاكي', 'retrovault-core' ); ?></label>
							<p>
								<label for="rv-netplay-url"><?php esc_html_e( 'رابط خادم اللعب الجماعي:', 'retrovault-core' ); ?></label>
								<input type="url" class="regular-text code" id="rv-netplay-url" name="<?php echo esc_attr( $name ); ?>[netplay_url]" value="<?php echo esc_attr( $o['netplay_url'] ); ?>" placeholder="https://netplay.emulatorjs.org">
							</p>
							<p class="description"><?php esc_html_e( 'اتركه فارغاً لاستخدام خادم مشروع EmulatorJS العام، وهو مناسب للتجربة. للاستخدام الفعلي شغّل خادمك الخاص (مشروع EmulatorJS-Netplay) وضع رابطه هنا.', 'retrovault-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'عند حذف الإضافة', 'retrovault-core' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_data]" value="1" <?php checked( $o['delete_data'], 1 ); ?>> <?php esc_html_e( 'احذف جدول التقييمات والإعدادات والإحصائيات (الألعاب نفسها لا تُحذف أبداً)', 'retrovault-core' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'حفظ الإعدادات', 'retrovault-core' ) ); ?>
			</form>
		</div>
		<?php
	}
}
