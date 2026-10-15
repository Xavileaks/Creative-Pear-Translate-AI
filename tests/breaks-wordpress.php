<?php
// Full sentence capture, provider payload and manual-save integration, no real AI.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) { exit( 1 ); }
define( 'WP_INSTALLING', true ); define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true ); define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WordPress required.' ); }
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
wp_installing( false ); $before = get_option( 'cptai_settings' ); $checks = 0; $requests = array();
$first = 'CPTAI BR regression: An entire creative team'; $last = 'at your fingertips!';
$original = $first . ' [cptai_br_1] ' . $last; $translated = 'CPTAI prueba: Todo un equipo creativo [cptai_br_1] al alcance de tu mano!';
class CPTAI_BR_Response extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', static fn() => static function () { throw new CPTAI_BR_Response(); } );
function br_check( bool $ok, string $name ): void { $GLOBALS['checks']++; if ( ! $ok ) { throw new RuntimeException( $name ); } }
function br_ajax( string $action, array $input ): array {
    $_POST = wp_slash( $input ); $_REQUEST = array_merge( $_POST, array( 'nonce' => wp_create_nonce( 'cptai_admin' ) ) );
    ob_start(); try { CPTAI_Admin::$action(); } catch ( CPTAI_BR_Response $e ) {}
    return json_decode( ob_get_clean(), true, 512, JSON_THROW_ON_ERROR );
}
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
    if ( home_url( '/' ) === $url ) { return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '<html><body><p>' . esc_html( $GLOBALS['first'] ) . '<br>' . esc_html( $GLOBALS['last'] ) . '</p></body></html>' ); }
    if ( 'https://api.openai.com/v1/responses' === $url ) {
        $GLOBALS['requests'][] = json_decode( $args['body'], true );
        return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'status' => 'completed', 'output' => array( array( 'content' => array( array( 'type' => 'output_text', 'text' => wp_json_encode( array( $GLOBALS['translated'] ) ) ) ) ) ) ) ) );
    }
    return new WP_Error( 'test', 'No real HTTP allowed.' );
}, 10, 3 );
global $wpdb; $job = null;
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
try {
    CPTAI_Settings::save( array( 'source' => 'en', 'targets' => array( 'uk' ), 'provider' => 'openai', 'openai_key' => 'fake-br-test-only', 'openai_model' => 'fake-br-model' ) );
    CPTAI_Store::put( $first, 'Existing independent translation', 'en', 'uk', home_url( '/' ) );
    $result = br_ajax( 'prepare', array( 'url' => home_url( '/' ), 'target' => 'uk' ) );
    br_check( $result['success'] && 1 === $result['data']['total'] && 1 === $result['data']['pending'], 'HTML capture returns one full sentence even with translated legacy fragment' );
    $job = $result['data']['job']; $state = get_transient( 'cptai_job_' . $job );
    br_check( array( $original ) === $state['strings'], 'AI page job contains complete phrase with BR marker' );
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CPTAI_Store::table() . ' WHERE original=%s AND target_lang=%s', $original, 'uk' ) );
    br_check( $row && '' === $row->translated, 'new complete sentence stored as pending' );
    $ai = br_ajax( 'row', array( 'id' => $row->id ) );
    br_check( $ai['success'] && $translated === $ai['data']['text'], 'single-row AI suggestion preserves marker' );
    br_check( 1 === count( $requests ) && array( $original ) === json_decode( $requests[0]['input'], true ), 'provider receives one full phrase instead of independent fragments' );
    br_check( str_contains( $requests[0]['instructions'], 'visual line breaks inside one complete phrase' ), 'provider instructed about semantic context and layout markers' );
    $bad = br_ajax( 'save_string', array( 'id' => $row->id, 'translated' => 'Todo un equipo creativo a tu alcance!', 'expected' => '' ) );
    br_check( ! $bad['success'], 'manual save rejects missing BR token' );
    $saved = br_ajax( 'save_string', array( 'id' => $row->id, 'translated' => $translated, 'expected' => '' ) );
    br_check( $saved['success'], 'manual full-sentence translation saved' );
    $found = CPTAI_Store::find( array( $original, $first ), 'en', 'uk' );
    br_check( $found[ $original ] === $translated && $found[ $first ] === 'Existing independent translation', 'full phrase and existing independent usage preserved separately' );
    $dictionary = br_ajax( 'dictionary', array( 'target' => 'uk', 's' => $original ) );
    br_check( 1 === $dictionary['data']['total'] && str_contains( $dictionary['data']['html'], 'Frase completa:' ), 'editor shows complete entry with marker guidance' );
} finally {
    if ( $job ) { delete_transient( 'cptai_job_' . $job ); }
    $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CPTAI_Store::table() . ' WHERE source_lang=%s AND target_lang=%s AND original IN (%s,%s)', 'en', 'uk', $original, $first ) );
    update_option( 'cptai_settings', $before, false );
}
echo "PASS $checks WordPress BR checks; no real provider requests.\n";
