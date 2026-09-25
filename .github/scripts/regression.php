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
WP_CLI::success( 'Security, REST, ROM authorization and save-persistence regressions passed.' );
