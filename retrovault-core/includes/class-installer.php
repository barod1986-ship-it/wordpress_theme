<?php
/**
 * التفعيل والترقية.
 *
 * @package RetroVault
 */

namespace RetroVault;

defined( 'ABSPATH' ) || exit;

final class Installer {

	public static function activate() {
		Post_Types::register();
		Game_Meta::register();
		Ratings::create_table();
		Favorites::create_table();
		Notifier::create_table();
		Analytics::create_table();
		Favorites::migrate_legacy();
		self::seed_terms();
		self::backfill();
		Account::ensure_page();
		Devlog::ensure_pages();
		if ( ! get_option( 'retrovault_db_version' ) || version_compare( (string) get_option( 'retrovault_db_version' ), '4', '<' ) ) {
			Notifier::mark_existing_posts();
		}
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
		Roms::protect_all();
		update_option( 'retrovault_db_version', RETROVAULT_DB_VERSION );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * ترقية عند تحديث الإضافة برفع نسخة جديدة (ووردبريس لا يستدعي التفعيل عند التحديث).
	 * 2: صفحة «حسابي». 3: صفحة «يوميات التطوير».
	 * 4: جدولا المتابعة والإشعارات، ونقل المفضلة إليهما.
	 * 5: جدول الإحصائيات اليومية و«الرائجة». 6: (النسخة الإنجليزية معطّلة).
	 * 7: أُزيلت النسخة الإنجليزية وتُحذف بياناتها المتبقية.
	 * 8: حماية ملفات الألعاب: نقلها للمجلد المحمي، وقاعدة رابط /rom/ الجديدة.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'retrovault_db_version' ) === RETROVAULT_DB_VERSION ) {
			return;
		}
		// بعد التحديث قد تصل عدة طلبات في نفس اللحظة؛ قفل ذرّي يضمن أن طلباً واحداً فقط ينفّذ الترقية.
		if ( ! self::lock() ) {
			return;
		}
		$from = (string) get_option( 'retrovault_db_version' );
		Ratings::create_table();
		Favorites::create_table();
		Notifier::create_table();
		Analytics::create_table();
		Favorites::migrate_legacy();
		if ( version_compare( $from, '7', '<' ) ) {
			self::remove_english_data();
		}
		if ( version_compare( $from, '8', '<' ) ) {
			Roms::protect_all();
			flush_rewrite_rules( false );
		}
		if ( version_compare( $from, '5', '<' ) ) {
			self::backfill();
			Analytics::recompute_trends();
		}
		if ( version_compare( $from, '4', '<' ) ) {
			// التدوينات المنشورة قبل هذه الميزة لا تُرسَل عنها إشعارات.
			Notifier::mark_existing_posts();
		}
		Account::ensure_page();
		Devlog::ensure_pages();
		update_option( 'retrovault_db_version', RETROVAULT_DB_VERSION );
		delete_option( 'retrovault_upgrade_lock' );
	}

	/**
	 * قفل ذرّي (INSERT IGNORE)، يُعتبر منتهياً بعد دقيقتين إن توقف الطلب الذي أخذه.
	 */
	private static function lock() {
		global $wpdb;
		$name = 'retrovault_upgrade_lock';
		$got  = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $got ) {
			return true;
		}
		$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( time() - $since < 2 * MINUTE_IN_SECONDS ) {
			return false;
		}
		$wpdb->update( $wpdb->options, array( 'option_value' => time() ), array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	/** يُنشئ الأنظمة والأنواع الافتراضية مرة واحدة فقط (إن كان التصنيف فارغاً). */
	private static function seed_terms() {
		if ( ! get_terms( array( 'taxonomy' => Post_Types::SYSTEM, 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ) ) ) {
			$slugs = array(
				'psx'     => 'ps1',
				'genesis' => 'mega-drive',
				'sms'     => 'master-system',
				'gg'      => 'game-gear',
				'a2600'   => 'atari-2600',
				'pce'     => 'pc-engine',
			);
			foreach ( Systems::seeded() as $key ) {
				$system = Systems::get( $key );
				if ( ! $system ) {
					continue;
				}
				$result = wp_insert_term(
					$system['name'],
					Post_Types::SYSTEM,
					array( 'slug' => isset( $slugs[ $key ] ) ? $slugs[ $key ] : $key )
				);
				if ( ! is_wp_error( $result ) ) {
					update_term_meta( $result['term_id'], 'rv_system_key', $key );
				}
			}
		}

		if ( ! get_terms( array( 'taxonomy' => Post_Types::GENRE, 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ) ) ) {
			$genres = array(
				'platformer' => 'منصات',
				'action'     => 'أكشن',
				'adventure'  => 'مغامرة',
				'rpg'        => 'تقمّص أدوار',
				'puzzle'     => 'ألغاز',
				'shooter'    => 'إطلاق نار',
				'racing'     => 'سباق',
				'fighting'   => 'قتال',
				'sports'     => 'رياضة',
				'strategy'   => 'استراتيجية',
				'arcade'     => 'أركيد',
			);
			foreach ( $genres as $slug => $name ) {
				wp_insert_term( $name, Post_Types::GENRE, array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * 1.5: أُزيلت النسخة الإنجليزية من الكود؛ تُحذف بياناتها المتبقية من قاعدة البيانات
	 * (حقول الألعاب الإنجليزية، أسماء الأنواع، لغة التدوينات والأعضاء، ونصوص المخصِّص).
	 */
	private static function remove_english_data() {
		foreach ( array( '_rv_title_en', '_rv_excerpt_en', '_rv_content_en', '_rv_languages_en', '_rv_controls_en', '_rv_changelog_en', '_rv_credits_en', '_rv_lang' ) as $key ) {
			delete_post_meta_by_key( $key );
		}
		delete_metadata( 'term', 0, 'rv_name_en', '', true );
		delete_metadata( 'user', 0, '_rv_lang', '', true );
		$options = get_option( Settings::OPTION );
		if ( is_array( $options ) ) {
			unset( $options['english'], $options['site_name_en'], $options['tagline_en'] );
			update_option( Settings::OPTION, $options );
		}
		if ( 'retrovault' === get_template() ) {
			foreach ( array( 'rvt_hero_title_en', 'rvt_hero_text_en', 'rvt_menu_title_en', 'rvt_footer_text_en' ) as $mod ) {
				remove_theme_mod( $mod );
			}
		}
	}

	/** يضمن وجود كل الحقول للألعاب الموجودة مسبقاً (عند إعادة التفعيل). */
	private static function backfill() {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::GAME,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			Game_Meta::ensure_defaults( $id );
		}
	}
}
