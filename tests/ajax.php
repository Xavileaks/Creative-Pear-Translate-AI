<?php
// AJAX integration checks without calling external services.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) {
    fwrite( STDERR, "Usage: php tests/ajax.php /path/to/isolated/wordpress\n" );
    exit( 1 );
}
define( 'WP_INSTALLING', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Refusing to run outside local WordPress.' ); }
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
CPTAI_Routing::boot();
$users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $users[0]->ID );
class CPTAI_Test_Response extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', static fn() => static function () { throw new CPTAI_Test_Response(); } );
$checks = 0;
function cptai_assert_ajax( bool $pass, string $name ): void {
    $GLOBALS['checks']++;
    if ( ! $pass ) { throw new RuntimeException( $name ); }
}
function cptai_call_ajax( string $method, array $input ): array {
    $_POST = wp_slash( $input );
    $_REQUEST = array_merge( $_POST, array( 'nonce' => wp_create_nonce( 'cptai_admin' ) ) );
    ob_start();
    try { CPTAI_Admin::$method(); } catch ( CPTAI_Test_Response $e ) {}
    $output = ob_get_clean();
    $response = json_decode( $output, true );
    if ( ! is_array( $response ) ) { throw new RuntimeException( 'Malformed AJAX response: ' . $output ); }
    return $response;
}
$settings = cptai_call_ajax( 'save_settings_ajax', array( 'source' => 'es', 'targets' => array( 'en', 'fr' ), 'footer' => 1 ) );
cptai_assert_ajax( $settings['success'], 'settings saved through AJAX' );
cptai_assert_ajax( ! isset( $settings['data']['openai_key'], $settings['data']['claude_key'] ), 'settings response never includes secrets' );
CPTAI_Store::capture( array( 'AJAX fixture %s' ), 'es', 'en', home_url( '/' ) );
CPTAI_Store::put( 'AJAX fixture %s', '', 'es', 'en', home_url( '/' ) );
global $wpdb;
$id = $wpdb->get_var( "SELECT id FROM " . CPTAI_Store::table() . " WHERE original='AJAX fixture %s' AND target_lang='en'" );
$saved = cptai_call_ajax( 'save_string', array( 'id' => $id, 'translated' => 'Saved %s', 'expected' => '' ) );
cptai_assert_ajax( $saved['success'] && 'Saved %s' === $saved['data']['text'], 'row saved without navigation' );
$invalid = cptai_call_ajax( 'save_string', array( 'id' => $id, 'translated' => 'Lost placeholder', 'expected' => 'Saved %s' ) );
cptai_assert_ajax( ! $invalid['success'], 'placeholder validation during AJAX save' );
$conflict = cptai_call_ajax( 'save_string', array( 'id' => $id, 'translated' => 'Stale edit %s', 'expected' => '' ) );
cptai_assert_ajax( ! $conflict['success'], 'concurrent changes cannot silently overwrite' );
$same = cptai_call_ajax( 'save_string', array( 'id' => $id, 'translated' => 'Saved %s', 'expected' => 'Saved %s' ) );
cptai_assert_ajax( $same['success'], 'idempotent save succeeds' );
$clear = cptai_call_ajax( 'save_string', array( 'id' => $id, 'translated' => '', 'expected' => 'Saved %s' ) );
cptai_assert_ajax( $clear['success'] && ! $clear['data']['translated'], 'clearing translation returns to original' );
$manual_original = 'CPTAI manual success fixture %s';
$manual = cptai_call_ajax( 'add_string', array( 'original' => $manual_original, 'translated' => 'Mensaje guardado %s', 'target' => 'en' ) );
cptai_assert_ajax( $manual['success'], 'manual message added without HTML capture' );
$duplicate = cptai_call_ajax( 'add_string', array( 'original' => $manual_original, 'translated' => 'Replacement %s', 'target' => 'en' ) );
cptai_assert_ajax( ! $duplicate['success'] && ( CPTAI_Store::dictionary( 'es', 'en' )[ $manual_original ] ?? '' ) === 'Mensaje guardado %s', 'manual duplicates preserve previous translation' );
$bad = cptai_call_ajax( 'add_string', array( 'original' => 'CPTAI rejected %s', 'translated' => 'Sin marcador', 'target' => 'en' ) );
cptai_assert_ajax( ! $bad['success'], 'manual message rejects lost placeholders' );
$bad = cptai_call_ajax( 'add_string', array( 'original' => '<script>alert(1)</script>', 'translated' => '', 'target' => 'en' ) );
cptai_assert_ajax( ! $bad['success'], 'manual original rejects executable HTML' );
$bad = cptai_call_ajax( 'add_string', array( 'original' => 'CPTAI invalid reference', 'translated' => '', 'target' => 'en', 'url' => 'https://other.example/contact/' ) );
cptai_assert_ajax( ! $bad['success'], 'manual reference restricted to local public URL' );
$pending = cptai_call_ajax( 'add_string', array( 'original' => 'CPTAI manual pending fixture', 'translated' => '', 'target' => 'en' ) );
cptai_assert_ajax( $pending['success'], 'manual message can be added as pending' );
$bad = cptai_call_ajax( 'add_string', array( 'original' => 'MARZO 5, 2026', 'translated' => 'MARCH', 'target' => 'en' ) );
cptai_assert_ajax( ! $bad['success'], 'manual date cannot lose day and year' );
CPTAI_Store::capture( array( 'MARZO 5, 2026' ), 'es', 'en', home_url( '/' ) );
$date_id = $wpdb->get_var( "SELECT id FROM " . CPTAI_Store::table() . " WHERE original='MARZO 5, 2026' AND source_lang='es' AND target_lang='en'" );
$bad = cptai_call_ajax( 'save_string', array( 'id' => $date_id, 'translated' => 'MARCH', 'expected' => '' ) );
cptai_assert_ajax( ! $bad['success'], 'dictionary edit cannot lose date numbers' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CPTAI_Store::table() . ' WHERE source_lang=%s AND target_lang=%s AND original IN (%s,%s,%s)', 'es', 'en', $manual_original, 'CPTAI manual pending fixture', 'MARZO 5, 2026' ) );
wp_set_current_user( 0 );
ob_start();
try { CPTAI_Admin::save_string(); } catch ( CPTAI_Test_Response $e ) {}
$denied = ob_get_clean();
cptai_assert_ajax( str_contains( $denied, 'No tienes permisos.' ) || '' === $denied, 'visitor save denied' );
ob_start();
try { CPTAI_Admin::add_string(); } catch ( CPTAI_Test_Response $e ) {}
$denied = ob_get_clean();
cptai_assert_ajax( str_contains( $denied, 'No tienes permisos.' ) || '' === $denied, 'visitor cannot add manual strings' );
echo 'PASS: ' . $checks . " AJAX checks.\n";
