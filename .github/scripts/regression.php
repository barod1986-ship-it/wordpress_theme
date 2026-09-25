<?php
/** WordPress integration regressions, run only inside smoke.sh's disposable installation. */

use RetroVault\Game_Meta;
use RetroVault\Roms;
use RetroVault\Saves;

function rv_assert( $condition, $message ) {
	if ( ! $condition ) { WP_CLI::error( $message ); }
	WP_CLI::log( 'ok ' . $message );
}
function rv_request( $method, $route, $data = array() ) {
	$request = new WP_REST_Request( $method, $route );
	$request->set_body_params( $data );
	return rest_do_request( $request );
}
function rv_test_attachment( $name, $owner ) {
	$upload = wp_upload_bits( $name, null, str_repeat( 'test', 16 ) );
	rv_assert( empty( $upload['error'] ), 'test attachment written' );
	return wp_insert_attachment( array( 'post_title' => $name, 'post_author' => $owner, 'post_mime_type' => 'application/octet-stream', 'post_status' => 'inherit' ), $upload['file'] );
}

wp_set_current_user( 1 );
$author = wp_insert_user( array( 'user_login' => 'regression-author', 'user_pass' => wp_generate_password(), 'user_email' => 'regression@example.com', 'role' => 'author' ) );
rv_assert( ! is_wp_error( $author ), 'author fixture created' );
$foreign = rv_test_attachment( 'admin-regression.nes', 1 );
$owned   = rv_test_attachment( 'author-regression.nes', $author );
$image   = rv_test_attachment( 'not-a-game.png', $author );
$foreign_path = get_attached_file( $foreign );
$image_path = get_attached_file( $image );

wp_set_current_user( $author );
$response = rv_request( 'POST', '/wp/v2/rv_game', array( 'title' => 'REST regression', 'status' => 'publish', 'meta' => array( '_rv_version' => '<b>2.0</b>', '_rv_players' => 99, '_rv_controls' => '<script>alert(1)</script><p>Safe</p>', '_rv_rom_id' => $owned ) ) );
rv_assert( 201 === $response->get_status(), 'REST creates a game with registered metadata' );
$data = $response->get_data();
$game_id = $data['id'];
rv_assert( '2.0' === $data['meta']['_rv_version'] && 8 === $data['meta']['_rv_players'], 'REST metadata uses the same sanitizers as the editor' );
rv_assert( false === strpos( $data['meta']['_rv_controls'], '<script' ), 'REST HTML metadata strips unsafe markup' );
rv_assert( Roms::is_protected_path( get_attached_file( $owned ) ), 'an authorized ROM is protected' );

$response = rv_request( 'POST', '/wp/v2/rv_game/' . $game_id, array( 'meta' => array( '_rv_rom_id' => $foreign ) ) );
rv_assert( 403 === $response->get_status(), 'REST rejects another author attachment' );
rv_assert( false === update_post_meta( $game_id, '_rv_rom_id', $foreign ), 'metadata API also rejects unauthorized ROM assignments' );
rv_assert( get_attached_file( $foreign ) === $foreign_path && file_exists( $foreign_path ), 'unauthorized attachment path remains unchanged' );
$response = rv_request( 'POST', '/wp/v2/rv_game/' . $game_id, array( 'meta' => array( '_rv_rom_id' => $image ) ) );
rv_assert( 400 === $response->get_status(), 'REST rejects a non-ROM attachment' );
rv_assert( false === Roms::protect( $image ) && get_attached_file( $image ) === $image_path, 'background protection never relocates images' );
rv_assert( (int) get_post_meta( $game_id, '_rv_rom_id', true ) === (int) $owned, 'invalid edits preserve the current game file' );

// Exercise the actual classic-editor save path with a valid nonce.
$_POST = array( 'rv_game_nonce' => wp_create_nonce( 'rv_game_save' ), 'rv' => array( 'rom_id' => $foreign, 'version' => '2.1' ) );
Game_Meta::save( $game_id, get_post( $game_id ) );
$_POST = array();
rv_assert( (int) get_post_meta( $game_id, '_rv_rom_id', true ) === (int) $owned, 'classic editor rejects the unauthorized attachment too' );

// Save index mutations and failed persistence must preserve previous files.
$tmp = wp_tempnam( 'rv-regression' );
file_put_contents( $tmp, 'first save' );
$first = Saves::put_sram( $author, $game_id, $tmp, 'raw', 'aaaa-10', '' );
rv_assert( ! is_wp_error( $first ), 'initial SRAM write succeeds' );
file_put_contents( $tmp, 'second save' );
$second = Saves::put_sram( $author, $game_id, $tmp, 'raw', 'bbbb-11', 'aaaa-10' );
rv_assert( ! is_wp_error( $second ), 'acknowledged SRAM update succeeds' );
$stale = Saves::put_sram( $author, $game_id, $tmp, 'raw', 'cccc-11', 'aaaa-10' );
rv_assert( is_wp_error( $stale ) && 'rv_sram_conflict' === $stale->get_error_code(), 'stale device writes are rejected' );
$again = Saves::put_sram( $author, $game_id, $tmp, 'raw', 'bbbb-11', 'aaaa-10' );
rv_assert( ! is_wp_error( $again ), 'retrying the acknowledged content is idempotent' );
$before = get_user_meta( $author, Saves::META, true );
$uploads = wp_upload_dir();
$dir = $uploads['basedir'] . '/rv-saves/u' . $author . '-' . get_user_meta( $author, Saves::KEY_META, true );
$files_before = glob( $dir . '/*' );
$reject = static function ( $check, $user_id, $key ) { return Saves::META === $key ? false : $check; };
add_filter( 'update_user_metadata', $reject, 10, 3 );
$failed = Saves::put_sram( $author, $game_id, $tmp, 'raw', 'dddd-11', 'bbbb-11' );
remove_filter( 'update_user_metadata', $reject, 10 );
rv_assert( is_wp_error( $failed ), 'database failure is returned to the caller' );
rv_assert( $before === get_user_meta( $author, Saves::META, true ), 'database failure preserves the previous save index' );
rv_assert( $files_before === glob( $dir . '/*' ), 'database failure preserves old files and removes the orphan new file' );
add_option( 'rv_saves_mutex_' . $author, ( time() + 300 ) . ':other-writer', '', false );
$busy = Saves::put_sram( $author, $game_id, $tmp, 'raw', 'eeee-11', 'bbbb-11' );
rv_assert( is_wp_error( $busy ) && 'rv_save_busy' === $busy->get_error_code(), 'simultaneous index writers cannot acquire the same lock' );
delete_option( 'rv_saves_mutex_' . $author );
$state = Saves::add_state( $author, $game_id, $tmp, null, '', 'raw', 'fceumm' );
rv_assert( ! is_wp_error( $state ), 'state save and SRAM coexist in the index' );
$summary = Saves::summary( $author, $game_id );
rv_assert( 1 === count( $summary['states'] ) && 'bbbb-11' === $summary['sram']['hash'], 'adding a state preserves SRAM' );
wp_delete_file( $tmp );
wp_set_current_user( 1 );

// Signed ROM URLs must be scoped to this browser, account, game and time window.
$cookie = Roms::cookie_name();
$_COOKIE[ $cookie ] = str_repeat( 'a', 64 );
$window = (int) floor( time() / Roms::WINDOW );
$token = Roms::token( $game_id, $window );
rv_assert( Roms::valid_token( $game_id, $token ), 'current player session token is accepted' );
rv_assert( Roms::valid_token( $game_id, Roms::token( $game_id, $window - 1 ) ), 'previous token window allows an already open player' );
rv_assert( ! Roms::valid_token( $game_id, Roms::token( $game_id, $window - 2 ) ), 'expired ROM links are rejected' );
rv_assert( ! Roms::valid_token( $game_id + 1, $token ), 'ROM token cannot access another game' );
$_COOKIE[ $cookie ] = str_repeat( 'b', 64 );
rv_assert( ! Roms::valid_token( $game_id, $token ), 'ROM token cannot be shared with another browser session' );
unset( $_COOKIE[ $cookie ] );
rv_assert( ! Roms::valid_token( $game_id, $token ) && ! Roms::valid_token( $game_id, '' ), 'missing player cookie fails closed' );
$_COOKIE[ $cookie ] = str_repeat( 'a', 64 );
wp_set_current_user( $author );
rv_assert( ! Roms::valid_token( $game_id, $token ), 'switching accounts invalidates the earlier ROM token' );
wp_set_current_user( 1 );
unset( $_COOKIE[ $cookie ] );

// A database error during protection must not lose the original attachment or expose a fallback.
$broken = rv_test_attachment( 'protection-failure.nes', 1 );
$original = get_attached_file( $broken );
$reject_path = static function ( $check, $id, $key ) use ( $broken ) {
	return (int) $id === (int) $broken && '_wp_attached_file' === $key ? false : $check;
};
add_filter( 'update_post_metadata', $reject_path, 20, 3 );
rv_assert( ! Roms::protect( $broken ), 'attachment path persistence failure rejects protection' );
remove_filter( 'update_post_metadata', $reject_path, 20 );
rv_assert( get_attached_file( $broken ) === $original && file_exists( $original ), 'failed protection preserves the original attachment file' );
$meta = \RetroVault\Game_Meta::values( $game_id );
$meta['rom_id'] = $broken;
$meta['rom_url'] = 'https://example.com/unprotected-fallback.nes';
$rom = \RetroVault\Games::rom( $game_id, $meta );
rv_assert( '' === $rom['url'] && '' === $rom['raw_url'], 'failed attachment protection cannot fall back to any public URL' );
rv_assert( ! Roms::is_protected_path( dirname( get_attached_file( $owned ) ) . '/../' . basename( $original ) ), 'a directory prefix alone never establishes protection' );
rv_assert(
	'nes' === \RetroVault\Systems::for_extension( 'NES' ) && 'gb' === \RetroVault\Systems::for_extension( '.gb' ) && 'gbc' === \RetroVault\Systems::for_extension( 'gb', array( 'gbc' ) )
		&& '' === \RetroVault\Systems::for_extension( 'bin' ) && '' === \RetroVault\Systems::for_extension( 'cue' ) && '' === \RetroVault\Systems::for_extension( 'zip' ),
	'a game system is guessed only from an unambiguous file extension'
);
// Instant sign-up: the chosen password is stored exactly as wp_signon later compares it (slashed).
add_filter( 'send_auth_cookies', '__return_false' );
$mails = array();
$capture_mail = static function ( $result, $atts ) use ( &$mails ) {
	$mails[] = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'];
	return true;
};
add_filter( 'pre_wp_mail', $capture_mail, 10, 2 );
$pagenow_before     = $GLOBALS['pagenow'];
$GLOBALS['pagenow'] = 'wp-login.php';
$_REQUEST['action'] = 'register';
$_POST = array( 'rv_pass' => 'short' );
$result = register_new_user( 'regsignup', 'regsignup@example.com' );
rv_assert( is_wp_error( $result ) && in_array( 'rv_pass_short', $result->get_error_codes(), true ) && ! username_exists( 'regsignup' ), 'instant sign-up rejects a short password before creating the account' );
$_POST = array( 'rv_pass' => wp_slash( "it's-a-pass1" ), 'rv_website' => 'http://spam.example' );
$result = register_new_user( 'regsignup', 'regsignup@example.com' );
rv_assert( is_wp_error( $result ) && in_array( 'rv_signup_blocked', $result->get_error_codes(), true ) && ! username_exists( 'regsignup' ), 'the hidden bot field blocks sign-up' );
$_POST  = array( 'rv_pass' => wp_slash( "it's-a-pass1" ) );
$member = register_new_user( 'regsignup', 'regsignup@example.com' );
rv_assert( is_int( $member ) && get_current_user_id() === $member, 'instant sign-up creates the account and signs it in' );
rv_assert( wp_authenticate( 'regsignup', wp_slash( "it's-a-pass1" ) ) instanceof WP_User, 'the chosen password works on the login form' );
rv_assert( '' === get_user_meta( $member, 'default_password_nag', true ), 'a chosen password gets no change-password nag' );
rv_assert( array( get_option( 'admin_email' ) ) === $mails, 'only the admin is emailed about the new member' );
$GLOBALS['pagenow'] = $pagenow_before;
unset( $_REQUEST['action'] );
$_POST = array();

// Account settings: the current password guards email and password changes.
$settings = static function ( $input ) use ( $member ) {
	return \RetroVault\Account::update_settings( get_userdata( $member ), $input );
};
$result = $settings( array( 'name' => 'x', 'email' => 'regsignup@example.com' ) );
rv_assert( isset( $result['errors']['name'] ), 'a one-letter display name is rejected' );
$result = $settings( array( 'name' => 'لاعب', 'email' => 'changed@example.com' ) );
rv_assert( isset( $result['errors']['current_password'] ) && 'regsignup@example.com' === get_userdata( $member )->user_email, 'an email change needs the current password' );
$result = $settings( array( 'name' => 'لاعب', 'email' => get_option( 'admin_email' ), 'current_password' => wp_slash( "it's-a-pass1" ) ) );
rv_assert( isset( $result['errors']['email'] ), "another account's email is rejected" );
$result = $settings( array( 'name' => 'لاعب', 'email' => 'regsignup@example.com', 'password' => wp_slash( 'N3w"Pass\\word' ), 'current_password' => wp_slash( 'wrong' ) ) );
rv_assert( isset( $result['errors']['current_password'] ) && wp_authenticate( 'regsignup', wp_slash( "it's-a-pass1" ) ) instanceof WP_User, 'a wrong current password changes nothing' );
$mails  = array();
$result = $settings( array( 'name' => "لاعب O'Neil", 'email' => 'changed@example.com', 'password' => wp_slash( 'N3w"Pass\\word' ), 'current_password' => wp_slash( "it's-a-pass1" ) ) );
$user   = get_userdata( $member );
rv_assert( ! $result['errors'] && $result['password'] && "لاعب O'Neil" === $user->display_name && 'changed@example.com' === $user->user_email, 'name, email and password save together' );
rv_assert( wp_authenticate( 'regsignup', wp_slash( 'N3w"Pass\\word' ) ) instanceof WP_User && is_wp_error( wp_authenticate( 'regsignup', wp_slash( "it's-a-pass1" ) ) ), 'the new password replaces the old one on the login form' );
rv_assert( in_array( 'regsignup@example.com', $mails, true ), 'the previous address is told about the email change' );
remove_filter( 'pre_wp_mail', $capture_mail, 10 );
remove_filter( 'send_auth_cookies', '__return_false' );
wp_set_current_user( 1 );
// Changelog lines starting with a dash are a list on the game page and in the version email.
rv_assert( '<p>1.1</p><ul><li>a</li><li>b</li></ul><p>note</p>' === \RetroVault\Games::changelog_html( "1.1\n- a\n* b\nnote" ), 'changelog dash lines render as a list' );
$log = \RetroVault\Games::changelog_items( "1.2\n- one\n- two\n- three\n\n1.1\n- older", 2 );
rv_assert( array( 'one', 'two' ) === $log['items'] && $log['more'], 'the version email takes the latest changelog section only' );

// Follower emails: plain-text part, the site as sender, the changelog as a list.
update_post_meta( $game_id, '_rv_changelog', "1.1\n- first item\n- second & third\n\n1.0\n- old entry" );
\RetroVault\Games::flush( $game_id );
$sent    = array();
$sender  = static function () {
	return 'wordpress@example.com';
};
$capture = static function ( $mailer ) use ( &$sent ) {
	$sent = array(
		'from' => $mailer->FromName,
		'text' => $mailer->AltBody,
		'html' => $mailer->Body,
	);
	$mailer->isSendmail();
	$mailer->Sendmail = '/bin/true';
};
add_filter( 'wp_mail_from', $sender );
add_action( 'phpmailer_init', $capture, 999 );
$send = new ReflectionMethod( '\RetroVault\Notifier', 'mail' );
$send->setAccessible( true );
$send->invoke( null, get_userdata( $author ), array( 'type' => 'version', 'games' => array( $game_id ), 'ref' => 0, 'version' => '1.1', 'offset' => 0, 'job' => 'r' ) );
remove_action( 'phpmailer_init', $capture, 999 );
remove_filter( 'wp_mail_from', $sender );
rv_assert( isset( $sent['from'] ) && get_bloginfo( 'name' ) === $sent['from'], 'follower emails are sent under the site name' );
rv_assert( false !== strpos( $sent['text'], '• first item' ) && false !== strpos( $sent['text'], 'second & third' ) && false === strpos( $sent['text'], 'old entry' ), 'follower emails carry a plain-text part with the latest changes' );
rv_assert( 2 === substr_count( $sent['html'], '<li' ) && false === strpos( $sent['html'], '- first' ), 'the HTML email lists changes without dash markers' );

// Automatic devlog excerpts are built from paragraphs; headings no longer run into the text.
$devlog = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'Regression devlog',
		'post_content' => "<!-- wp:paragraph -->\n<p>First paragraph.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Hidden heading</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Second paragraph.</p>\n<!-- /wp:paragraph -->",
	)
);
$excerpt = get_the_excerpt( $devlog );
rv_assert( false === strpos( $excerpt, 'Hidden heading' ) && false !== strpos( $excerpt, 'Second paragraph' ), 'automatic devlog excerpts skip headings' );

// Stats chart: a close round ceiling split into whole quarters, and whole weeks only.
$nice = new ReflectionMethod( '\RetroVault\Analytics', 'nice' );
$nice->setAccessible( true );
rv_assert( 2400 === $nice->invoke( null, 2300 ) && 12000 === $nice->invoke( null, 11700 ) && 8 === $nice->invoke( null, 5 ) && 4 === $nice->invoke( null, 0 ), 'chart axis ceilings stay close to the data' );
$days = array();
for ( $i = 9; $i >= 0; $i-- ) {
	$days[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = 1;
}
rv_assert( array( 7 ) === array_values( \RetroVault\Analytics::weekly( $days ) ), 'weekly chart bars are whole weeks ending today' );
// Security limits (1.13): IPv6 by network, per-member save quota, sign-up and password-guess limits, CSV cells.
$saved_settings = get_option( 'retrovault_settings' );
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa::1';
$v6a = \RetroVault\Stats::client_ip();
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:bbbb:cccc:dddd:eeee';
rv_assert( $v6a === \RetroVault\Stats::client_ip() && '2001:db8:1:3::1' !== $v6a, 'IPv6 visitors are counted by their /64 network' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
rv_assert( '203.0.113.7' === \RetroVault\Stats::client_ip(), 'IPv4 addresses are kept as they are' );

$quota_user = wp_insert_user( array( 'user_login' => 'regquota', 'user_pass' => wp_generate_password(), 'user_email' => 'regquota@example.com', 'role' => 'subscriber' ) );
update_option( 'retrovault_settings', array_merge( (array) get_option( 'retrovault_settings', array() ), array( 'save_max_mb' => 1, 'save_quota_mb' => 5, 'save_slots' => 1 ) ) );
\RetroVault\Settings::flush_cache();
rv_assert( 5 * MB_IN_BYTES === \RetroVault\Saves::quota(), 'the member save quota follows the setting' );
$blob = static function ( $bytes ) {
	$tmp = wp_tempnam( 'rv-quota' );
	file_put_contents( $tmp, random_bytes( $bytes ) );
	return $tmp;
};
$games = get_posts( array( 'post_type' => 'rv_game', 'post_status' => 'publish', 'posts_per_page' => 3, 'fields' => 'ids' ) );
rv_assert( 3 === count( $games ), 'quota fixtures have three games' );
rv_assert( ! is_wp_error( \RetroVault\Saves::add_state( $quota_user, $games[0], $blob( 900 * KB_IN_BYTES ), null, '', 'raw', 'fceumm' ) ), 'a save within the member quota is stored' );
rv_assert( ! is_wp_error( \RetroVault\Saves::put_sram( $quota_user, $games[1], $blob( 3500 * KB_IN_BYTES ), 'raw', 'aaa', '' ) ), 'game-save sync within the quota is stored' );
$over = \RetroVault\Saves::add_state( $quota_user, $games[2], $blob( 900 * KB_IN_BYTES ), null, '', 'raw', 'fceumm' );
rv_assert( is_wp_error( $over ) && 'rv_save_quota' === $over->get_error_code(), 'saves beyond the member quota are refused' );
$replace = \RetroVault\Saves::add_state( $quota_user, $games[0], $blob( 900 * KB_IN_BYTES ), null, '', 'raw', 'fceumm' );
rv_assert( ! is_wp_error( $replace ), 'a save that replaces the oldest one in a full slot still fits' );
$sram = \RetroVault\Saves::put_sram( $quota_user, $games[2], $blob( MB_IN_BYTES ), 'raw', 'bbb', '' );
rv_assert( is_wp_error( $sram ) && 'rv_save_quota' === $sram->get_error_code(), 'game-save sync respects the quota too' );
rv_assert( \RetroVault\Saves::usage( $quota_user ) <= \RetroVault\Saves::quota(), 'stored saves stay within the quota' );
\RetroVault\Saves::delete_all( $quota_user );
update_option( 'retrovault_settings', $saved_settings );
\RetroVault\Settings::flush_cache();

// Login attempts: five wrong passwords lock that name for this connection, even with the right password.
$locked_user = wp_insert_user( array( 'user_login' => 'reglocked', 'user_pass' => 'right-password-1', 'user_email' => 'reglocked@example.com', 'role' => 'subscriber' ) );
for ( $i = 0; $i < 5; $i++ ) {
	wp_authenticate( 'reglocked', 'wrong-password' );
}
$locked = wp_authenticate( 'reglocked', 'right-password-1' );
rv_assert( is_wp_error( $locked ) && 'rv_login_locked' === $locked->get_error_code(), 'five wrong passwords lock the login for this connection' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
rv_assert( wp_authenticate( 'reglocked', 'right-password-1' ) instanceof WP_User, 'the same account still logs in from another connection' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
\RetroVault\Guard::clear( 'login', 'reglocked' );
rv_assert( wp_authenticate( 'reglocked', 'right-password-1' ) instanceof WP_User, 'the lock lifts once cleared' );

// Account settings: five wrong current passwords stop further guesses.
for ( $i = 0; $i < 5; $i++ ) {
	\RetroVault\Account::update_settings( get_userdata( $locked_user ), array( 'name' => 'Locked', 'email' => 'other@example.com', 'current_password' => 'wrong' ) );
}
$guess = \RetroVault\Account::update_settings( get_userdata( $locked_user ), array( 'name' => 'Locked', 'email' => 'other@example.com', 'current_password' => 'right-password-1' ) );
rv_assert( isset( $guess['errors']['current_password'] ) && 'reglocked@example.com' === get_userdata( $locked_user )->user_email, 'current-password guesses in account settings are limited' );

// Instant sign-up: a handful of accounts per connection per hour.
$GLOBALS['pagenow'] = 'wp-login.php';
$_REQUEST['action'] = 'register';
for ( $i = 0; $i < 5; $i++ ) {
	\RetroVault\Guard::fail( 'signup', '' );
}
$_POST  = array( 'rv_pass' => 'long-enough-1' );
$signup = register_new_user( 'reglimit', 'reglimit@example.com' );
rv_assert( is_wp_error( $signup ) && in_array( 'rv_signup_limit', $signup->get_error_codes(), true ) && ! username_exists( 'reglimit' ), 'instant sign-ups from one connection are limited per hour' );
\RetroVault\Guard::clear( 'signup', '' );
$_POST = array();
unset( $_REQUEST['action'] );
$GLOBALS['pagenow'] = $pagenow_before;

rv_assert( "'=HYPERLINK(1)" === \RetroVault\Analytics::csv_text( '=HYPERLINK(1)' ) && 'Pixel Quest' === \RetroVault\Analytics::csv_text( 'Pixel Quest' ), 'CSV export cells cannot start a spreadsheet formula' );

// Search engines (1.15): no author sitemap or account page in the sitemap, usernames kept out of public output.
$sitemaps = wp_sitemaps_get_server();
rv_assert( ! array_key_exists( 'users', $sitemaps->registry->get_providers() ), 'the sitemap has no author list (it exposes login names)' );
$pages = array_column( $sitemaps->registry->get_provider( 'posts' )->get_url_list( 1, 'page' ), 'loc' );
rv_assert( \RetroVault\Account::url() && ! in_array( \RetroVault\Account::url(), $pages, true ), 'the account page is not in the sitemap' );
rv_assert( home_url( '/' ) === \RetroVault\Guard::oembed_author( array( 'author_url' => get_author_posts_url( 1 ) ) )['author_url'], 'embeds do not link the author archive' );
rv_assert( array( 'comment', 'byuser' ) === \RetroVault\Guard::comment_class( array( 'comment', 'byuser', 'comment-author-admin' ) ), 'comment classes do not carry login names' );
$writers = get_users( array( 'capability' => 'edit_posts', 'fields' => array( 'ID', 'display_name' ) ) );
foreach ( $writers as $writer ) {
	wp_update_user( array( 'ID' => $writer->ID, 'display_name' => 'Writer ' . $writer->ID ) );
}
rv_assert( 'good' === \RetroVault\Guard::site_health_logins()['status'], 'Site Health passes when no writer shows their login name' );
wp_update_user( array( 'ID' => 1, 'display_name' => get_userdata( 1 )->user_login ) );
rv_assert( 'recommended' === \RetroVault\Guard::site_health_logins()['status'], 'Site Health flags a display name that is the login name' );
foreach ( $writers as $writer ) {
	wp_update_user( array( 'ID' => $writer->ID, 'display_name' => $writer->display_name ) );
}
$_GET = array( 'genre' => 'rpg' );
$filtered = \RetroVault\Query::is_filtered();
$_GET = array();
rv_assert( $filtered && ! \RetroVault\Query::is_filtered(), 'a genre filter on a system page counts as filtered, the page itself does not' );

// Player (1.16): download failures explained, Site Health test registered, direct links checked before publishing.
wp_set_current_user( 0 );
$pixel = \RetroVault\Games::get( get_page_by_path( 'pixel-quest', OBJECT, 'rv_game' ) );
$ui    = \RetroVault\Player::ui_config( $pixel );
$keys  = array( 'offline', 'network', 'cors', 'mixed', 'session', 'token', 'origin', 'missing', 'password', 'blocked', 'server', 'link', 'bios' );
rv_assert( ! array_diff( $keys, array_keys( $ui['i18n'] ) ) && ! isset( $ui['admin'] ), 'visitors get a message for every download failure and no server details' );
wp_set_current_user( 1 );
$ui = \RetroVault\Player::ui_config( $pixel );
rv_assert( isset( $ui['admin'] ) && ! array_diff( $keys, array_keys( $ui['admin']['hints'] ) ) && false !== strpos( $ui['admin']['code']['token'], '/play/' ) && false === strpos( $ui['admin']['code']['token'], 'pixel-quest' ), 'editors get a fix for every failure, with generic cache paths' );
wp_set_current_user( 0 );
$tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
rv_assert( isset( $tests['async']['retrovault_roms']['has_rest'] ) && is_callable( $tests['async']['retrovault_roms']['async_direct_test'] ), 'Site Health checks that game files reach the player' );
$route = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/retrovault/v1/site-health/roms' ) );
rv_assert( in_array( $route->get_status(), array( 401, 403 ), true ), 'the Site Health route is for administrators only' );
$GLOBALS['_playground_consts'] = array();
$health                        = \RetroVault\Roms::site_health_roms();
unset( $GLOBALS['_playground_consts'] );
rv_assert( 'good' === $health['status'] && false !== strpos( $health['description'], 'Playground' ), 'Site Health does not send a loopback request inside WordPress Playground' );
rv_assert( null === \RetroVault\Roms::check_link( home_url( '/wp-content/uploads/game.nes' ) ), 'a direct link on the same site needs no permission' );
$https = static function ( $url ) {
	return set_url_scheme( $url, 'https' );
};
add_filter( 'home_url', $https );
$mixed = \RetroVault\Roms::check_link( 'http://files.example.com/game.nes' );
remove_filter( 'home_url', $https );
rv_assert( is_array( $mixed ) && 'error' === $mixed[0], 'an http direct link on an https site is flagged before visitors hit it' );
WP_CLI::success( 'Security, REST, ROM authorization, save-persistence, sign-up, account, email, stats, limit, search-engine and player regressions passed.' );
