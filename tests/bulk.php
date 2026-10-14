<?php
// All-dictionary translation and published-content discovery, isolated WordPress.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) { exit( 1 ); }
define( 'WP_INSTALLING', true ); define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true ); define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WordPress required.' ); }
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
wp_installing( false );
CPTAI_Plan::boot(); CPTAI_Routing::boot();
$before = get_option( 'cptai_settings' ); $checks = 0; $requests = array(); $posts = array();
global $wpdb;
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]; wp_set_current_user( $admin->ID );
class CPTAI_Bulk_Response extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', static fn() => static function () { throw new CPTAI_Bulk_Response(); } );
function bulk_check( bool $ok, string $name ): void { $GLOBALS['checks']++; if ( ! $ok ) { throw new RuntimeException( $name ); } }
function bulk_ajax( string $action, array $input = array() ): array {
    $_POST = wp_slash( $input ); $_REQUEST = array_merge( $_POST, array( 'nonce' => wp_create_nonce( 'cptai_admin' ) ) );
    ob_start(); try { CPTAI_Admin::$action(); } catch ( CPTAI_Bulk_Response $e ) {}
    return json_decode( ob_get_clean(), true, 512, JSON_THROW_ON_ERROR );
}
function bulk_rest( string $operation, array $data, string $secret ): WP_REST_Response {
    $req = new WP_REST_Request( 'POST', '/cptai/v1/plan/' . $operation ); $req->set_header( 'Content-Type', 'application/json' ); $req->set_header( 'X-CPTAI-Companion', $secret ); $req->set_body( wp_json_encode( $data ) ); return rest_do_request( $req );
}
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
    if ( 'https://api.openai.com/v1/responses' !== $url ) { return new WP_Error( 'blocked', 'External requests forbidden in tests.' ); }
    $payload = json_decode( $args['body'], true );
    $input = json_decode( $payload['input'], true );
    $strings = $input;
    $GLOBALS['requests'][] = $strings;
    $text = wp_json_encode( array_map( static fn( $s ) => 'Traducido ' . $s, $strings ) );
    return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'status' => 'completed', 'output' => array( array( 'content' => array( array( 'type' => 'output_text', 'text' => $text ) ) ) ) ) ) );
}, 10, 3 );
try {
    CPTAI_Plan::disconnect();
    CPTAI_Settings::save( array( 'source' => 'en', 'targets' => array( 'ja' ), 'provider' => 'openai', 'openai_key' => 'fake-test-only', 'openai_model' => 'fake-text-model' ) );
    $ids = array();
    for ( $i = 0; $i < 36; $i++ ) {
        $text = 'CPTAI bulk fixture ' . sprintf( '%02d', $i );
        CPTAI_Store::put( $text, 35 === $i ? 'Saved manual translation' : '', 'en', 'ja', home_url( '/' ) );
        $ids[] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CPTAI_Store::table() . ' WHERE source_lang=%s AND target_lang=%s AND original=%s', 'en', 'ja', $text ) );
    }
    $others = array_diff( array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . CPTAI_Store::table() ) ), $ids );
    $skip = array_merge( $others, array( $ids[0] ) );
    $prepared = bulk_ajax( 'prepare_all', array( 'target' => 'ja', 'sort' => 'oldest', 'skip' => wp_json_encode( array_values( $skip ) ) ) );
    bulk_check( $prepared['success'] && 34 === $prepared['data']['total'], 'all pending across more than one page, saved and drafts excluded' );
    $job_id = $prepared['data']['job'];
    $job = get_transient( 'cptai_job_' . $job_id );
    bulk_check( 34 === count( $job['ids'] ) && ! isset( $job['strings'] ), 'snapshot stores IDs instead of full strings' );
    CPTAI_Store::put( 'CPTAI bulk fixture 02', 'Concurrent manual choice', 'en', 'ja', home_url( '/' ) );
    $skip[] = $ids[3];
    do {
        $result = bulk_ajax( 'batch', array( 'job' => $job_id, 'skip' => wp_json_encode( array_values( $skip ) ) ) );
        bulk_check( $result['success'], 'per-string batch succeeds' );
        if ( isset( $result['data']['row'] ) ) {
            $row = $result['data']['row'];
            $stored = $wpdb->get_var( $wpdb->prepare( 'SELECT translated FROM ' . CPTAI_Store::table() . ' WHERE id=%d', $row['id'] ) );
            bulk_check( $row['text'] === $stored || ( '' === $row['text'] && null === $stored ), 'each returned translation already persisted' );
        }
    } while ( ! $result['data']['complete'] );
    bulk_check( 32 === count( $requests ) && 1 === max( array_map( 'count', $requests ) ), 'one inference and save per pending string' );
    $dictionary = CPTAI_Store::dictionary( 'en', 'ja' );
    bulk_check( 'Concurrent manual choice' === $dictionary['CPTAI bulk fixture 02'] && 'Saved manual translation' === $dictionary['CPTAI bulk fixture 35'], 'manual and existing translations preserved' );
    bulk_check( ! isset( $dictionary['CPTAI bulk fixture 00'], $dictionary['CPTAI bulk fixture 03'] ), 'drafts before and during run stay pending' );
    bulk_check( ! bulk_ajax( 'prepare_all', array( 'target' => 'es' ) )['success'], 'inactive destination rejected' );
    $prepared = bulk_ajax( 'prepare_all', array( 'target' => 'ja', 'skip' => wp_json_encode( array_values( $others ) ) ) );
    $new_settings = CPTAI_Settings::get(); $new_settings['openai_model'] = 'changed-model'; update_option( 'cptai_settings', $new_settings );
    bulk_check( ! bulk_ajax( 'batch', array( 'job' => $prepared['data']['job'] ) )['success'], 'changing model stops outstanding dictionary job' );
    // Exercise the asynchronous plan path with a mock worker, never OpenAI.
    CPTAI_Settings::save( array( 'source' => 'en', 'targets' => array( 'ja' ), 'provider' => 'chatgpt', 'chatgpt_model' => 'fake-plan-model' ) );
    $link = CPTAI_Plan::connection();
    bulk_rest( 'poll', array( 'models' => array( 'fake-plan-model' ), 'account' => 'test@example.invalid' ), $link['secret'] );
    $prepared = bulk_ajax( 'prepare_all', array( 'target' => 'ja', 'sort' => 'oldest', 'skip' => wp_json_encode( array_values( $others ) ) ) );
    $queued = bulk_ajax( 'batch', array( 'job' => $prepared['data']['job'] ) ); $ticket = $queued['data']['plan_ticket'];
    $work = bulk_rest( 'poll', array( 'models' => array( 'fake-plan-model' ) ), $link['secret'] )->get_data()['job'];
    bulk_check( 1 === count( $work['strings'] ), 'plan also processes one string' );
    bulk_rest( 'complete', array( 'id' => $ticket, 'translations' => array( 'Plan translation' ) ), $link['secret'] );
    $done = bulk_ajax( 'plan_result', array( 'ticket' => $ticket ) );
    bulk_check( $done['success'] && 1 === $done['data']['done'] && 'Plan translation' === $done['data']['row']['text'], 'plan completion saves before reporting progress' );
    bulk_check( $done === bulk_ajax( 'plan_result', array( 'ticket' => $ticket ) ), 'plan completion idempotent' );
    foreach ( array( 'page', 'post', 'product' ) as $type ) {
        if ( ! post_type_exists( $type ) ) { register_post_type( $type, array( 'public' => true ) ); }
        $public_id = wp_insert_post( array( 'post_title' => 'CPTAI content public ' . $type, 'post_type' => $type, 'post_status' => 'publish' ) ); $posts[] = $public_id;
        $private_id = wp_insert_post( array( 'post_title' => 'CPTAI content private ' . $type, 'post_type' => $type, 'post_status' => 'draft' ) ); $posts[] = $private_id;
        $password_id = wp_insert_post( array( 'post_title' => 'CPTAI content password ' . $type, 'post_type' => $type, 'post_status' => 'publish', 'post_password' => 'test-password' ) ); $posts[] = $password_id;
        $contents = bulk_ajax( 'content_list', array( 'type' => $type ) ); $found = array_column( $contents['data']['items'], 'id' );
        bulk_check( in_array( $public_id, $found, true ) && ! in_array( $private_id, $found, true ) && ! in_array( $password_id, $found, true ), 'discovery includes only published unprotected ' . $type );
    }
    bulk_check( ! bulk_ajax( 'content_list', array( 'type' => 'attachment' ) )['success'], 'discovery post types restricted' );
    wp_set_current_user( 0 );
    ob_start(); try { CPTAI_Admin::prepare_all(); } catch ( CPTAI_Bulk_Response $e ) {} ob_end_clean();
    bulk_check( 32 === count( $requests ), 'visitor cannot trigger inference' );
} catch ( Throwable $e ) {
    fwrite( STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" );
    throw $e;
} finally {
    CPTAI_Plan::disconnect(); update_option( 'cptai_settings', $before );
    $wpdb->query( "DELETE FROM " . CPTAI_Store::table() . " WHERE original LIKE 'CPTAI bulk fixture %'" );
    foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
}
echo "PASS: $checks bulk and content-discovery checks; no real provider requests.\n";
