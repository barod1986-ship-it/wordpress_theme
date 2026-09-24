<?php
/**
 * الواجهة العامة للقوالب. أي قالب يستطيع استخدام هذه الدوال دون معرفة التفاصيل الداخلية.
 *
 * @package RetroVault
 */

defined( 'ABSPATH' ) || exit;

use RetroVault\Account;
use RetroVault\Devlog;
use RetroVault\Favorites;
use RetroVault\Games;
use RetroVault\Saves;
use RetroVault\Player;
use RetroVault\Query;
use RetroVault\Ratings;
use RetroVault\Stats;
use RetroVault\Systems;

/**
 * كل بيانات لعبة في مصفوفة واحدة.
 *
 * @param int|WP_Post|null $post اللعبة (الافتراضي: الحالية).
 * @return array|null
 */
function rv_get_game( $post = null ) {
	return Games::get( $post );
}

/**
 * كود المشغّل (صورة + زر تشغيل + شريط أدوات).
 *
 * @param int|WP_Post|null $post اللعبة.
 * @return string
 */
function rv_get_player( $post = null ) {
	return Player::markup( $post );
}

/**
 * @param int|WP_Post|null $post اللعبة.
 */
function rv_player( $post = null ) {
	echo rv_get_player( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- مُهرَّب داخل Player::markup.
}

/**
 * استعلام ألعاب جاهز للرفوف: sort (newest|rating|plays|updated|year|title), number, system, genre, featured...
 *
 * @param array $args الوسائط.
 * @return WP_Query
 */
function rv_query_games( $args = array() ) {
	return Games::query( $args );
}

/**
 * @param int|WP_Post|null $post  اللعبة.
 * @param int              $count العدد.
 * @return WP_Query
 */
function rv_related_games( $post = null, $count = 6 ) {
	return Games::related( $post, $count );
}

/**
 * الأنظمة مع بياناتها (اللون، النسبة، العدد...).
 *
 * @param bool $hide_empty إخفاء الأنظمة بلا ألعاب.
 * @return array[]
 */
function rv_get_systems( $hide_empty = true ) {
	return Games::systems( $hide_empty );
}

/**
 * @param WP_Term $term تصنيف النظام.
 * @return array
 */
function rv_get_system( $term ) {
	return Games::system_data( $term );
}

/**
 * جدول أزرار لوحة المفاتيح الافتراضية لنظام.
 *
 * @param string $system_key مفتاح النظام في السجل.
 * @return array
 */
function rv_get_controls( $system_key ) {
	return Systems::controls( $system_key );
}

/**
 * @return array{q:string,system:string,genre:string,players:string,status:string,sort:string}
 */
function rv_current_filters() {
	return Query::filters();
}

/** @return array<string,string> */
function rv_sort_options() {
	return Query::sorts();
}

/** @return array<string,string> */
function rv_status_options() {
	return Games::statuses();
}

/**
 * @param int $players عدد اللاعبين.
 */
function rv_players_label( $players ) {
	return Games::players_label( $players );
}

/**
 * تقييم عضو للعبة.
 *
 * @param int $game_id رقم اللعبة.
 * @param int $user_id رقم العضو (الافتراضي: الحالي).
 */
function rv_user_rating( $game_id, $user_id = 0 ) {
	return Ratings::get_user_rating( $game_id, $user_id ? $user_id : get_current_user_id() );
}

/**
 * تقييمات كل الأعضاء للعبة (user_id => rating).
 *
 * @param int $game_id رقم اللعبة.
 * @return array<int,int>
 */
function rv_game_ratings( $game_id ) {
	return Ratings::for_game( $game_id );
}

/**
 * @param WP_Comment $comment التعليق.
 */
function rv_is_developer_comment( $comment ) {
	return RetroVault\Comments::is_developer( $comment );
}

/**
 * @return array{games:int,systems:int,plays:int,ratings:int}
 */
function rv_totals() {
	return Stats::totals();
}

/**
 * @param int $attachment_id رقم الصورة.
 */
function rv_is_pixel_image( $attachment_id ) {
	return Games::is_pixel_image( $attachment_id );
}

/** رابط «لعبة عشوائية». */
function rv_random_url() {
	return add_query_arg( 'rv_random', '1', home_url( '/' ) );
}

/* -------------------------------------------------------------------------
 * ميزات الأعضاء
 * ---------------------------------------------------------------------- */

/**
 * هل اللعبة في مفضلة العضو؟
 *
 * @param int $game_id رقم اللعبة.
 * @param int $user_id رقم العضو (الافتراضي: الحالي).
 */
function rv_is_favorite( $game_id, $user_id = 0 ) {
	return Favorites::has( $game_id, $user_id ? $user_id : get_current_user_id() );
}

/**
 * ألعاب مفضلة العضو (المنشورة، الأحدث إضافة أولاً).
 *
 * @param int $user_id رقم العضو.
 * @return int[]
 */
function rv_get_favorites( $user_id = 0 ) {
	return Favorites::get( $user_id ? $user_id : get_current_user_id() );
}

/**
 * @param int $game_id رقم اللعبة.
 */
function rv_favorite_count( $game_id ) {
	return Favorites::count( $game_id );
}

/** هل الحفظ السحابي مفعّل؟ */
function rv_cloud_saves_enabled() {
	return Saves::enabled();
}

/**
 * حفظ العضو السحابي للعبة (time, ago, size, version, outdated, shot_url...) أو null.
 *
 * @param int $game_id رقم اللعبة.
 * @param int $user_id رقم العضو.
 * @return array|null
 */
function rv_get_save( $game_id, $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	return ( $user_id && Saves::enabled() ) ? Saves::info( $user_id, $game_id ) : null;
}

/**
 * كل حفظ العضو السحابي مجمّعاً حسب اللعبة، الأحدث نشاطاً أولاً.
 * كل عنصر: حقول أحدث حالة (slot, time, ago, shot_url...) + states (كل الخانات) + sram.
 *
 * @param int $user_id رقم العضو.
 * @return array[]
 */
function rv_get_saves( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	return ( $user_id && Saves::enabled() ) ? Saves::for_user( $user_id ) : array();
}

/**
 * تقييمات العضو (game_id, rating, time).
 *
 * @param int $user_id رقم العضو.
 * @return array[]
 */
function rv_get_user_ratings( $user_id = 0 ) {
	return Ratings::for_user( $user_id ? $user_id : get_current_user_id() );
}

/** رابط صفحة «حسابي». */
function rv_account_url() {
	return Account::url();
}

/** هل الصفحة الحالية هي صفحة «حسابي»؟ */
function rv_is_account_page() {
	return Account::is_page();
}

/** هل مزامنة حفظ اللعبة الداخلي (SRAM) مفعّلة؟ */
function rv_sram_sync_enabled() {
	return Saves::sram_enabled();
}

/* -------------------------------------------------------------------------
 * يوميات التطوير
 * ---------------------------------------------------------------------- */

/**
 * تدوينات لعبة معينة.
 *
 * @param int $game_id رقم اللعبة.
 * @param int $number  العدد.
 * @return WP_Query
 */
function rv_game_devlog( $game_id, $number = 3 ) {
	return Devlog::for_game( $game_id, $number );
}

/**
 * أحدث التدوينات.
 *
 * @param int $number العدد.
 * @return WP_Query
 */
function rv_devlog_latest( $number = 3 ) {
	return Devlog::latest( $number );
}

/**
 * الألعاب المرتبطة بتدوينة (المنشورة).
 *
 * @param int $post_id رقم التدوينة.
 * @return int[]
 */
function rv_post_games( $post_id = 0 ) {
	return Devlog::games_for_post( $post_id ? $post_id : get_the_ID() );
}

/** رابط صفحة «يوميات التطوير». */
function rv_devlog_url() {
	return Devlog::url();
}

/**
 * هل التعليقات في هذا المحتوى للأعضاء فقط؟
 *
 * @param int|WP_Post|null $post المحتوى.
 */
function rv_members_only_comments( $post = null ) {
	$post = get_post( $post );
	return $post && in_array( $post->post_type, RetroVault\Comments::members_only_types(), true );
}

/* -------------------------------------------------------------------------
 * الإشعارات وتطبيق الويب
 * ---------------------------------------------------------------------- */

/**
 * «الجديد في ألعابك»: تحديثات الألعاب التي يتابعها العضو.
 *
 * @param int $user_id رقم العضو.
 * @param int $limit   العدد.
 * @return array[] type, time, ago, game_id, game_title, system, text, url, new.
 */
function rv_get_notices( $user_id = 0, $limit = 10 ) {
	return RetroVault\Notifier::for_user( $user_id ? $user_id : get_current_user_id(), $limit );
}

/**
 * @param int $user_id رقم العضو.
 */
function rv_unseen_notices_count( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	return $user_id ? RetroVault\Notifier::unseen_count( $user_id ) : 0;
}

/**
 * @param int $user_id رقم العضو.
 */
function rv_mark_notices_seen( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	if ( $user_id ) {
		RetroVault\Notifier::mark_seen( $user_id );
	}
}

/**
 * هل يستقبل العضو رسائل التحديثات؟
 *
 * @param int $user_id رقم العضو.
 */
function rv_notify_email_enabled( $user_id = 0 ) {
	return RetroVault\Notifier::email_enabled( $user_id ? $user_id : get_current_user_id() );
}

/** هل رسائل المتابعين مفعّلة في الموقع؟ */
function rv_follower_emails_enabled() {
	return (bool) RetroVault\Settings::get( 'notify_email' );
}

/** هل تطبيق الويب مفعّل؟ */
function rv_pwa_enabled() {
	return RetroVault\Pwa::enabled();
}

/**
 * مفتاح «متاحة بدون إنترنت» للعبة.
 *
 * @param int $game_id رقم اللعبة.
 */
function rv_offline_key( $game_id ) {
	return RetroVault\Pwa::offline_key( $game_id );
}
