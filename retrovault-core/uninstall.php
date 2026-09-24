<?php
/**
 * يُنفَّذ عند حذف الإضافة من لوحة التحكم.
 * لا يحذف شيئاً إلا إذا فعّل المدير خيار «احذف البيانات» في الإعدادات.
 * الألعاب نفسها (المقالات والصور والملفات) لا تُحذف أبداً.
 *
 * @package RetroVault
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// ذاكرة مؤقتة لا بيانات: تُحذف دائماً.
delete_site_transient( 'retrovault_github_release' );

$retrovault_settings = get_option( 'retrovault_settings', array() );
if ( empty( $retrovault_settings['delete_data'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rv_ratings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rv_follows" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rv_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rv_stats_daily" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
wp_clear_scheduled_hook( 'retrovault_recompute_trends' );
delete_option( 'retrovault_stats_since' );
delete_post_meta_by_key( '_rv_trend_score' );
wp_clear_scheduled_hook( 'retrovault_send_notices' );

foreach ( array( '_rv_play_count', '_rv_download_count', '_rv_rating_avg', '_rv_rating_count', '_rv_rating_score' ) as $retrovault_key ) {
	delete_post_meta_by_key( $retrovault_key );
}

delete_post_meta_by_key( '_rv_fav_count' );
delete_post_meta_by_key( '_rv_game' );
delete_post_meta_by_key( '_rv_notified' );
delete_metadata( 'user', 0, '_rv_notify_email', '', true );
delete_metadata( 'user', 0, '_rv_notices_seen', '', true );
delete_metadata( 'user', 0, '_rv_favorites', '', true );
delete_metadata( 'user', 0, '_rv_saves', '', true );
delete_metadata( 'user', 0, '_rv_saves_key', '', true );

// ملفات الحفظ السحابي.
$retrovault_uploads = wp_upload_dir( null, false );
$retrovault_saves   = trailingslashit( $retrovault_uploads['basedir'] ) . 'rv-saves';
if ( is_dir( $retrovault_saves ) ) {
	$retrovault_items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $retrovault_saves, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $retrovault_items as $retrovault_item ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		$retrovault_item->isDir() ? @rmdir( $retrovault_item->getPathname() ) : @unlink( $retrovault_item->getPathname() );
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $retrovault_saves );
}

$retrovault_page = (int) get_option( 'retrovault_account_page' );
if ( $retrovault_page ) {
	wp_delete_post( $retrovault_page, true );
}
delete_option( 'retrovault_account_page' );
delete_option( 'retrovault_settings' );
delete_option( 'retrovault_db_version' );
delete_transient( 'rv_totals' );
delete_transient( 'rv_rating_mean' );
