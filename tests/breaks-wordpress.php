<?php
// Full sentence capture, provider payload and manual-save integration, no real AI.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) { exit( 1 ); }
define( 'WP_INSTALLING', true ); define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true ); define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WordPress required.' ); }
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
CPTAI_Store::install();
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
    $dictionary = br_ajax( 'dictionary', array( 'target' => 'uk', 's' => CPTAI_Store::search_text( $original ) ) );
    br_check( 1 === $dictionary['data']['total'] && ! str_contains( $dictionary['data']['html'], '[cptai_' ), 'editor and phrase search hide internal formatting tokens' );
    $dictionary = br_ajax( 'dictionary', array( 'target' => 'uk', 's' => $first ) );
    br_check( 1 === $dictionary['data']['total'], 'superseded fragment hidden after complete page capture' );
    br_check( 'Existing independent translation' === CPTAI_Store::find( array( $first ), 'en', 'uk' )[ $first ], 'hidden legacy translation retained in database' );
    CPTAI_Store::capture( array( $first ), 'en', 'uk', home_url( '/independent/' ), true );
    $dictionary = br_ajax( 'dictionary', array( 'target' => 'uk', 's' => $first ) );
    br_check( 2 === $dictionary['data']['total'], 'genuine independent occurrence remains visible alongside full phrase' );
    CPTAI_Store::capture( array( 'Different text' ), 'en', 'uk', home_url( '/independent/' ), true );
    $dictionary = br_ajax( 'dictionary', array( 'target' => 'uk', 's' => $first ) );
    br_check( 1 === $dictionary['data']['total'], 'rereading removes stale independent occurrence' );
    $plain = "CPTAI prueba: Un equipo entero\na tu alcance!";
    $saved = br_ajax( 'save_string', array( 'id' => $row->id, 'plain' => '1', 'translated' => $plain, 'expected' => CPTAI_Breaks::display( $translated ) ) );
    br_check( $saved['success'] && $plain === $saved['data']['text'], 'normal text with line break saves without typing tokens' );
    $internal = CPTAI_Store::find( array( $original ), 'en', 'uk' )[ $original ];
    br_check( CPTAI_Breaks::valid( $original, $internal ) && CPTAI_Breaks::display( $internal ) === $plain, 'stored formatting restored automatically' );
    $stale = br_ajax( 'save_string', array( 'id' => $row->id, 'plain' => '1', 'translated' => 'Otro texto', 'expected' => CPTAI_Breaks::display( $translated ) ) );
    br_check( ! $stale['success'] && 'conflict' === $stale['data']['code'], 'plain editor still rejects concurrent stale save' );
    $single = br_ajax( 'save_string', array( 'id' => $row->id, 'plain' => '1', 'translated' => 'Un equipo creativo a tu alcance!', 'expected' => $plain ) );
    br_check( $single['success'] && ! str_contains( $single['data']['text'], '[cptai_' ), 'single line automatically retains original visual break' );
    $cleared = br_ajax( 'save_string', array( 'id' => $row->id, 'plain' => '1', 'translated' => '', 'expected' => $single['data']['text'] ) );
    br_check( $cleared['success'] && '' === $cleared['data']['text'], 'clear resets grouped translation' );
    $suggestion = 'Un equipo entero [cptai_br_1] creativo para ti!';
    $saved = br_ajax( 'save_string', array( 'id' => $row->id, 'plain' => '1', 'translated' => str_replace( "\n", "\r\n", CPTAI_Breaks::display( $suggestion ) ), 'formatted' => $suggestion, 'expected' => '' ) );
    br_check( $saved['success'] && CPTAI_Store::find( array( $original ), 'en', 'uk' )[ $original ] === $suggestion, 'saving reviewed AI suggestion retains its exact internal layout' );
    $again = br_ajax( 'save_string', array( 'id' => $row->id, 'plain' => '1', 'translated' => "Un equipo creativo\r\npara ti!", 'expected' => str_replace( "\n", "\r\n", $saved['data']['text'] ) ) );
    br_check( $again['success'] && "Un equipo creativo\npara ti!" === $again['data']['text'], 'multipart CRLF line endings allow repeated saves without false conflict' );
    CPTAI_Store::put( $original, '', 'en', 'uk', home_url( '/' ) );
    CPTAI_Store::put( $first, '', 'en', 'uk', home_url( '/' ) );
    $all = br_ajax( 'prepare_all', array( 'target' => 'uk' ) );
    $all_job = get_transient( 'cptai_job_' . $all['data']['job'] );
    $fragment_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CPTAI_Store::table() . ' WHERE original=%s AND target_lang=%s', $first, 'uk' ) );
    br_check( in_array( (int) $row->id, $all_job['ids'], true ) && ! in_array( $fragment_id, $all_job['ids'], true ), 'translate-all includes complete phrase and excludes superseded pending fragment' );
    delete_transient( 'cptai_job_' . $all['data']['job'] );
    CPTAI_Store::capture( array( $first ), 'en', 'uk', home_url( '/' ) );
    CPTAI_Store::capture( array( $original ), 'en', 'uk', home_url( '/' ), true );
    $dictionary = br_ajax( 'dictionary', array( 'target' => 'uk', 's' => $first ) );
    br_check( 2 === $dictionary['data']['total'], 'complete HTML scan preserves independent dynamic message previously captured in browser' );
    $wpdb->delete( CPTAI_Store::locations(), array( 'source_lang' => 'en', 'target_lang' => 'uk', 'source_hash' => hash( 'sha256', $first ) ) );
    $manual = br_ajax( 'add_string', array( 'target' => 'uk', 'original' => $first, 'translated' => '' ) );
    CPTAI_Store::capture( array( $original ), 'en', 'uk', home_url( '/' ), true );
    $dictionary = br_ajax( 'dictionary', array( 'target' => 'uk', 's' => $first ) );
    br_check( $manual['success'] && 2 === $dictionary['data']['total'], 'explicit manual message remains editable after rereading matching grouped phrase' );
} finally {
    if ( $job ) { delete_transient( 'cptai_job_' . $job ); }
    $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CPTAI_Store::table() . ' WHERE source_lang=%s AND target_lang=%s AND original IN (%s,%s,%s)', 'en', 'uk', $original, $first, 'Different text' ) );
    $wpdb->delete( CPTAI_Store::locations(), array( 'source_lang' => 'en', 'target_lang' => 'uk' ) );
    update_option( 'cptai_settings', $before, false );
}
echo "PASS $checks WordPress BR checks; no real provider requests.\n";
