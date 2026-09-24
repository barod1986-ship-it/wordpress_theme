<?php
/**
 * المخصِّص (مظهر ← تخصيص ← الصفحة الرئيسية للمكتبة).
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return array<string,mixed>
 */
function rvt_mod_defaults() {
	return array(
		'rvt_hero_title'   => __( 'ألعاب رترو جديدة لأجهزة قديمة', 'retrovault' ),
		'rvt_hero_text'    => __( 'كل لعبة هنا صمّمتها وبرمجتها بنفسي لأجهزة مثل NES وSNES وGame Boy Advance، وتعمل مباشرة في متصفحك بلا تحميل ولا تثبيت.', 'retrovault' ),
		'rvt_menu_title'   => __( 'اختر لعبة', 'retrovault' ),
		'rvt_footer_text'  => __( 'كل الألعاب في هذا الموقع من تصميمي وبرمجتي، ومتاحة للعب مجاناً في المتصفح.', 'retrovault' ),
		'rvt_show_rated'   => true,
		'rvt_show_played'  => true,
		'rvt_show_updated' => true,
		'rvt_show_devlog'  => true,
	);
}

/**
 * @param string $key المفتاح.
 * @return mixed
 */
function rvt_mod( $key ) {
	$defaults = rvt_mod_defaults();
	return get_theme_mod( $key, isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
}

add_action(
	'customize_register',
	static function ( $wp_customize ) {
		$defaults = rvt_mod_defaults();

		$wp_customize->add_section(
			'rvt_home',
			array(
				'title'    => __( 'الصفحة الرئيسية للمكتبة', 'retrovault' ),
				'priority' => 30,
			)
		);

		$texts = array(
			'rvt_hero_title'  => array( __( 'العنوان الرئيسي', 'retrovault' ), 'text' ),
			'rvt_hero_text'   => array( __( 'النص التعريفي', 'retrovault' ), 'textarea' ),
			'rvt_menu_title'  => array( __( 'عنوان قائمة الألعاب داخل الشاشة', 'retrovault' ), 'text' ),
			'rvt_footer_text' => array( __( 'نص التذييل', 'retrovault' ), 'textarea' ),
		);
		foreach ( $texts as $id => $conf ) {
			$wp_customize->add_setting(
				$id,
				array(
					'default'           => $defaults[ $id ],
					'sanitize_callback' => 'textarea' === $conf[1] ? 'sanitize_textarea_field' : 'sanitize_text_field',
				)
			);
			$wp_customize->add_control(
				$id,
				array(
					'label'   => $conf[0],
					'section' => 'rvt_home',
					'type'    => $conf[1],
				)
			);
		}

		$toggles = array(
			'rvt_show_rated'   => __( 'إظهار «الأعلى تقييماً»', 'retrovault' ),
			'rvt_show_played'  => __( 'إظهار «الأكثر لعباً»', 'retrovault' ),
			'rvt_show_updated' => __( 'إظهار «حُدّثت مؤخراً»', 'retrovault' ),
			'rvt_show_devlog'  => __( 'إظهار «من يوميات التطوير»', 'retrovault' ),
		);
		foreach ( $toggles as $id => $label ) {
			$wp_customize->add_setting(
				$id,
				array(
					'default'           => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
				)
			);
			$wp_customize->add_control(
				$id,
				array(
					'label'   => $label,
					'section' => 'rvt_home',
					'type'    => 'checkbox',
				)
			);
		}
	}
);
