<?php
/**
 * Plugin Name:       RetroVault Core
 * Plugin URI:        https://github.com/barod1986-ship-it/wordpress_theme
 * Description:       النواة الوظيفية لمكتبة ألعاب الرترو: نوع محتوى «لعبة»، الأنظمة والأنواع، مشغّل EmulatorJS داخل المتصفح، التقييم بالنجوم، الحفظ السحابي (حالات + حفظ اللعبة الداخلي) والمفضلة وصفحة حساب للأعضاء، يوميات التطوير، إشعارات المتابعين، تطبيق ويب يعمل بدون إنترنت، الإحصائيات وواجهة REST.
 * Version:           1.5.1
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            RetroVault
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/barod1986-ship-it/wordpress_theme
 * Text Domain:       retrovault-core
 * Domain Path:       /languages
 *
 * لماذا إضافة منفصلة عن القالب؟
 * البيانات (الألعاب، الأنظمة، التقييمات، الإحصائيات) يجب أن تبقى حتى لو غيّرت القالب يوماً ما.
 * القالب مسؤول عن الشكل فقط، وهذه الإضافة مسؤولة عن كل ما هو «بيانات ووظائف».
 */

defined( 'ABSPATH' ) || exit;

define( 'RETROVAULT_VERSION', '1.5.1' );
define( 'RETROVAULT_DB_VERSION', '7' );
define( 'RETROVAULT_FILE', __FILE__ );
define( 'RETROVAULT_PATH', plugin_dir_path( __FILE__ ) );
define( 'RETROVAULT_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'systems', 'settings', 'post-types', 'game-meta', 'games', 'uploads', 'ratings', 'stats', 'query', 'player', 'rest', 'favorites', 'saves', 'account', 'devlog', 'notifier', 'pwa', 'analytics', 'comments', 'members', 'seo', 'admin', 'installer' ) as $retrovault_file ) {
	require_once RETROVAULT_PATH . "includes/class-{$retrovault_file}.php";
}
unset( $retrovault_file );
require_once RETROVAULT_PATH . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'RetroVault\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RetroVault\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		add_action( 'init', array( 'RetroVault\\Installer', 'maybe_upgrade' ), 20 );
		RetroVault\Settings::init();
		RetroVault\Post_Types::init();
		RetroVault\Game_Meta::init();
		RetroVault\Uploads::init();
		RetroVault\Ratings::init();
		RetroVault\Stats::init();
		RetroVault\Query::init();
		RetroVault\Player::init();
		RetroVault\Rest::init();
		RetroVault\Favorites::init();
		RetroVault\Saves::init();
		RetroVault\Account::init();
		RetroVault\Devlog::init();
		RetroVault\Notifier::init();
		RetroVault\Pwa::init();
		RetroVault\Analytics::init();
		RetroVault\Comments::init();
		RetroVault\Members::init();
		RetroVault\Seo::init();
		if ( is_admin() ) {
			RetroVault\Admin::init();
		}
	}
);

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'retrovault-core', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	},
	1
);
