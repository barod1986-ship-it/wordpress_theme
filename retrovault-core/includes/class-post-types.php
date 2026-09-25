<?php
/**
 * نوع المحتوى «لعبة» والتصنيفات (النظام / النوع).
 *
 * الروابط الناتجة:
 *   /games/                     مكتبة الألعاب
 *   /games/{slug}/              صفحة اللعبة
 *   /games/{slug}/play/         صفحة المشغّل المستقلة (تُعرض داخل iframe في صفحة اللعبة)
 *   /games/{slug}/download/     تنزيل الملف (إن كان مسموحاً)
 *   /system/{slug}/  و /genre/{slug}/
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Post_Types {

	const GAME   = 'rv_game';
	const SYSTEM = 'rv_system';
	const GENRE  = 'rv_genre';

	/** قناع نقاط نهاية خاص بصفحات الألعاب فقط، حتى لا تظهر /play/ على المقالات والصفحات. */
	const EP_GAME = 1048576;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic_editor' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );

		add_action( self::SYSTEM . '_add_form_fields', array( __CLASS__, 'system_add_fields' ) );
		add_action( self::SYSTEM . '_edit_form_fields', array( __CLASS__, 'system_edit_fields' ) );
		add_action( 'created_' . self::SYSTEM, array( __CLASS__, 'save_system_fields' ) );
		add_action( 'edited_' . self::SYSTEM, array( __CLASS__, 'save_system_fields' ) );
		add_filter( 'manage_edit-' . self::SYSTEM . '_columns', array( __CLASS__, 'system_columns' ) );
		add_filter( 'manage_' . self::SYSTEM . '_custom_column', array( __CLASS__, 'system_column' ), 10, 3 );
	}

	public static function register() {
		register_post_type(
			self::GAME,
			array(
				'labels'        => array(
					'name'                  => __( 'الألعاب', 'retrovault-core' ),
					'singular_name'         => __( 'لعبة', 'retrovault-core' ),
					'menu_name'             => __( 'الألعاب', 'retrovault-core' ),
					'add_new'               => __( 'إضافة لعبة', 'retrovault-core' ),
					'add_new_item'          => __( 'إضافة لعبة جديدة', 'retrovault-core' ),
					'edit_item'             => __( 'تعديل اللعبة', 'retrovault-core' ),
					'new_item'              => __( 'لعبة جديدة', 'retrovault-core' ),
					'view_item'             => __( 'عرض اللعبة', 'retrovault-core' ),
					'view_items'            => __( 'عرض الألعاب', 'retrovault-core' ),
					'search_items'          => __( 'البحث في الألعاب', 'retrovault-core' ),
					'not_found'             => __( 'لا توجد ألعاب بعد.', 'retrovault-core' ),
					'not_found_in_trash'    => __( 'لا توجد ألعاب في سلة المهملات.', 'retrovault-core' ),
					'all_items'             => __( 'كل الألعاب', 'retrovault-core' ),
					'archives'              => __( 'مكتبة الألعاب', 'retrovault-core' ),
					'featured_image'        => __( 'غلاف اللعبة', 'retrovault-core' ),
					'set_featured_image'    => __( 'تعيين الغلاف', 'retrovault-core' ),
					'remove_featured_image' => __( 'إزالة الغلاف', 'retrovault-core' ),
					'use_featured_image'    => __( 'استخدام كغلاف', 'retrovault-core' ),
					'items_list'            => __( 'قائمة الألعاب', 'retrovault-core' ),
				),
				'public'        => true,
				'has_archive'   => 'games',
				'rewrite'       => array(
					'slug'       => 'games',
					'with_front' => false,
					'ep_mask'    => self::EP_GAME,
				),
				'menu_icon'     => 'dashicons-games',
				'menu_position' => 5,
				'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'revisions', 'author', 'custom-fields' ),
				'show_in_rest'  => true,
				'taxonomies'    => array( self::SYSTEM, self::GENRE ),
			)
		);

		register_taxonomy(
			self::SYSTEM,
			self::GAME,
			array(
				'labels'             => array(
					'name'          => __( 'الأنظمة', 'retrovault-core' ),
					'singular_name' => __( 'نظام', 'retrovault-core' ),
					'menu_name'     => __( 'الأنظمة', 'retrovault-core' ),
					'all_items'     => __( 'كل الأنظمة', 'retrovault-core' ),
					'edit_item'     => __( 'تعديل النظام', 'retrovault-core' ),
					'view_item'     => __( 'عرض النظام', 'retrovault-core' ),
					'update_item'   => __( 'تحديث النظام', 'retrovault-core' ),
					'add_new_item'  => __( 'إضافة نظام جديد', 'retrovault-core' ),
					'new_item_name' => __( 'اسم النظام', 'retrovault-core' ),
					'search_items'  => __( 'البحث في الأنظمة', 'retrovault-core' ),
					'not_found'     => __( 'لا توجد أنظمة.', 'retrovault-core' ),
					'back_to_items' => __( 'العودة إلى الأنظمة', 'retrovault-core' ),
				),
				'hierarchical'       => true,
				'public'             => true,
				'show_admin_column'  => true,
				'show_in_rest'       => true,
				'show_in_quick_edit' => false,
				'meta_box_cb'        => array( __CLASS__, 'system_meta_box' ),
				'query_var'          => 'system',
				'rewrite'            => array(
					'slug'       => 'system',
					'with_front' => false,
				),
			)
		);

		register_taxonomy(
			self::GENRE,
			self::GAME,
			array(
				'labels'            => array(
					'name'          => __( 'الأنواع', 'retrovault-core' ),
					'singular_name' => __( 'نوع', 'retrovault-core' ),
					'menu_name'     => __( 'الأنواع', 'retrovault-core' ),
					'all_items'     => __( 'كل الأنواع', 'retrovault-core' ),
					'edit_item'     => __( 'تعديل النوع', 'retrovault-core' ),
					'view_item'     => __( 'عرض النوع', 'retrovault-core' ),
					'update_item'   => __( 'تحديث النوع', 'retrovault-core' ),
					'add_new_item'  => __( 'إضافة نوع جديد', 'retrovault-core' ),
					'new_item_name' => __( 'اسم النوع', 'retrovault-core' ),
					'search_items'  => __( 'البحث في الأنواع', 'retrovault-core' ),
					'not_found'     => __( 'لا توجد أنواع.', 'retrovault-core' ),
					'back_to_items' => __( 'العودة إلى الأنواع', 'retrovault-core' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => 'genre',
				'rewrite'           => array(
					'slug'       => 'genre',
					'with_front' => false,
				),
			)
		);

		register_term_meta(
			self::SYSTEM,
			'rv_system_key',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_system_key' ),
			)
		);
		register_term_meta(
			self::SYSTEM,
			'rv_color',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_hex_color',
			)
		);
		register_term_meta(
			self::SYSTEM,
			'rv_bios_url',
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'esc_url_raw',
			)
		);

		add_rewrite_endpoint( 'play', self::EP_GAME, 'rv_play' );
		add_rewrite_endpoint( 'download', self::EP_GAME, 'rv_download' );
		// ملف اللعبة المحمي للمشغّل: /games/{slug}/rom/{رمز}/{اسم الملف} (انظر Roms).
		add_rewrite_endpoint( 'rom', self::EP_GAME, 'rv_rom' );
	}

	/**
	 * @param mixed $value القيمة.
	 * @return string
	 */
	public static function sanitize_system_key( $value ) {
		$value = is_string( $value ) ? $value : '';
		return Systems::get( $value ) ? $value : '';
	}

	/**
	 * المحرر الكلاسيكي لصفحة اللعبة: البيانات مُنظمة في صناديق حقول واضحة.
	 *
	 * @param bool   $use       هل يُستخدم محرر المكوّنات.
	 * @param string $post_type نوع المحتوى.
	 */
	public static function classic_editor( $use, $post_type ) {
		return self::GAME === $post_type ? false : $use;
	}

	/**
	 * @param string   $text النص الافتراضي.
	 * @param \WP_Post $post المقالة.
	 */
	public static function title_placeholder( $text, $post ) {
		return self::GAME === $post->post_type ? __( 'اسم اللعبة', 'retrovault-core' ) : $text;
	}

	/**
	 * صندوق اختيار النظام: أزرار راديو لأن اللعبة تعمل على نظام واحد فقط.
	 *
	 * @param \WP_Post $post المقالة.
	 */
	public static function system_meta_box( $post ) {
		$terms   = get_terms(
			array(
				'taxonomy'   => self::SYSTEM,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$current = wp_get_object_terms( $post->ID, self::SYSTEM, array( 'fields' => 'ids' ) );
		$current = ( ! is_wp_error( $current ) && $current ) ? (int) $current[0] : 0;

		echo '<div class="rv-system-radios">';
		echo '<input type="hidden" name="tax_input[' . esc_attr( self::SYSTEM ) . '][]" value="0">';
		if ( is_wp_error( $terms ) || ! $terms ) {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'لا توجد أنظمة بعد.', 'retrovault-core' ),
				esc_url( admin_url( 'edit-tags.php?taxonomy=' . self::SYSTEM . '&post_type=' . self::GAME ) ),
				esc_html__( 'أضف نظاماً', 'retrovault-core' )
			);
		} else {
			foreach ( $terms as $term ) {
				$key = (string) get_term_meta( $term->term_id, 'rv_system_key', true );
				printf(
					'<label><input type="radio" name="tax_input[%1$s][]" value="%2$d" data-system-key="%3$s" %4$s> %5$s</label>',
					esc_attr( self::SYSTEM ),
					(int) $term->term_id,
					esc_attr( $key ),
					checked( $current, $term->term_id, false ),
					esc_html( $term->name )
				);
			}
		}
		echo '</div>';
	}

	public static function system_add_fields() {
		wp_nonce_field( 'rv_system_meta', 'rv_system_nonce' );
		?>
		<div class="form-field">
			<label for="rv_system_key"><?php esc_html_e( 'المحاكي المستخدم', 'retrovault-core' ); ?></label>
			<?php self::system_key_select( '' ); ?>
			<p><?php esc_html_e( 'يحدد نواة EmulatorJS وامتدادات الملفات ونسبة الشاشة لهذا النظام.', 'retrovault-core' ); ?></p>
		</div>
		<div class="form-field">
			<label for="rv_color"><?php esc_html_e( 'لون مميز (اختياري)', 'retrovault-core' ); ?></label>
			<input type="text" name="rv_color" id="rv_color" class="rv-color-field" value="">
		</div>
		<div class="form-field">
			<label for="rv_bios_url"><?php esc_html_e( 'رابط ملف BIOS (اختياري)', 'retrovault-core' ); ?></label>
			<input type="url" name="rv_bios_url" id="rv_bios_url" value="">
			<p><?php esc_html_e( 'أغلب الأنظمة لا تحتاجه. يُستخدم فقط مع الأنوية التي تتطلب BIOS، وبملف تملك حق استخدامه.', 'retrovault-core' ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param \WP_Term $term التصنيف.
	 */
	public static function system_edit_fields( $term ) {
		$key   = (string) get_term_meta( $term->term_id, 'rv_system_key', true );
		$color = (string) get_term_meta( $term->term_id, 'rv_color', true );
		$bios  = (string) get_term_meta( $term->term_id, 'rv_bios_url', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="rv_system_key"><?php esc_html_e( 'المحاكي المستخدم', 'retrovault-core' ); ?></label></th>
			<td>
				<?php wp_nonce_field( 'rv_system_meta', 'rv_system_nonce' ); ?>
				<?php self::system_key_select( $key ); ?>
				<p class="description"><?php esc_html_e( 'يحدد نواة EmulatorJS وامتدادات الملفات ونسبة الشاشة لهذا النظام.', 'retrovault-core' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="rv_color"><?php esc_html_e( 'لون مميز (اختياري)', 'retrovault-core' ); ?></label></th>
			<td><input type="text" name="rv_color" id="rv_color" class="rv-color-field" value="<?php echo esc_attr( $color ); ?>"></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="rv_bios_url"><?php esc_html_e( 'رابط ملف BIOS (اختياري)', 'retrovault-core' ); ?></label></th>
			<td>
				<input type="url" name="rv_bios_url" id="rv_bios_url" value="<?php echo esc_attr( $bios ); ?>">
				<p class="description"><?php esc_html_e( 'أغلب الأنظمة لا تحتاجه. يُستخدم فقط مع الأنوية التي تتطلب BIOS، وبملف تملك حق استخدامه.', 'retrovault-core' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param string $current المفتاح الحالي.
	 */
	private static function system_key_select( $current ) {
		echo '<select name="rv_system_key" id="rv_system_key">';
		echo '<option value="">' . esc_html__( '— اختر —', 'retrovault-core' ) . '</option>';
		foreach ( Systems::choices() as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * @param int $term_id رقم التصنيف.
	 */
	public static function save_system_fields( $term_id ) {
		if ( ! isset( $_POST['rv_system_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rv_system_nonce'] ) ), 'rv_system_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$key   = isset( $_POST['rv_system_key'] ) ? self::sanitize_system_key( sanitize_text_field( wp_unslash( $_POST['rv_system_key'] ) ) ) : '';
		$color = isset( $_POST['rv_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['rv_color'] ) ) : '';
		$bios  = isset( $_POST['rv_bios_url'] ) ? esc_url_raw( wp_unslash( $_POST['rv_bios_url'] ) ) : '';

		foreach ( array(
			'rv_system_key' => $key,
			'rv_color'      => $color,
			'rv_bios_url'   => $bios,
		) as $meta_key => $value ) {
			if ( $value ) {
				update_term_meta( $term_id, $meta_key, $value );
			} else {
				delete_term_meta( $term_id, $meta_key );
			}
		}
		Games::flush();
	}

	/**
	 * @param array $columns الأعمدة.
	 */
	public static function system_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'name' === $key ) {
				$new['rv_emulator'] = __( 'المحاكي', 'retrovault-core' );
			}
		}
		return $new;
	}

	/**
	 * @param string $content المحتوى.
	 * @param string $column  اسم العمود.
	 * @param int    $term_id رقم التصنيف.
	 */
	public static function system_column( $content, $column, $term_id ) {
		if ( 'rv_emulator' !== $column ) {
			return $content;
		}
		$system = Systems::get( (string) get_term_meta( $term_id, 'rv_system_key', true ) );
		if ( ! $system ) {
			return '<span style="color:#b32d2e">' . esc_html__( 'غير محدد — لن تعمل ألعاب هذا النظام', 'retrovault-core' ) . '</span>';
		}
		return esc_html( $system['short'] . ' · ' . $system['cores'][0] );
	}
}
