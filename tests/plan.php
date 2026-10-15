<?php
// Isolated WordPress bridge/AJAX tests. No provider requests or real credentials.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) { fwrite( STDERR, "Usage: php tests/plan.php /isolated/wordpress\n" ); exit( 1 ); }
define( 'WP_INSTALLING', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local environment required.' ); }
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
CPTAI_Plan::boot();
$original_settings = get_option( 'cptai_settings' );
$administrator = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $administrator->ID );
class CPTAI_Plan_Test_Response extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', static fn() => static function () { throw new CPTAI_Plan_Test_Response(); } );
$checks = 0; $external = 0;
add_filter( 'pre_http_request', static function () { $GLOBALS['external']++; return new WP_Error( 'blocked', 'External network forbidden during tests.' ); } );
function assert_plan( bool $value, string $name ): void { $GLOBALS['checks']++; if ( ! $value ) { throw new RuntimeException( $name ); } }
function ajax_plan( string $method, array $input = array() ): array {
    $_POST = wp_slash( $input ); $_REQUEST = array_merge( $_POST, array( 'nonce' => wp_create_nonce( 'cptai_admin' ) ) );
    ob_start(); try { CPTAI_Admin::$method(); } catch ( CPTAI_Plan_Test_Response $e ) {}
    $value = json_decode( ob_get_clean(), true ); if ( ! is_array( $value ) ) { throw new RuntimeException( 'Invalid AJAX output' ); } return $value;
}
function rest_plan( string $operation, array $input, string $secret ): WP_REST_Response {
    $request = new WP_REST_Request( 'POST', '/cptai/v1/plan/' . $operation );
    $request->set_header( 'Content-Type', 'application/json' ); $request->set_header( 'X-CPTAI-Companion', $secret ); $request->set_body( wp_json_encode( $input ) );
    return rest_do_request( $request );
}
function poll_plan( array $connection, bool $heartbeat = false ): WP_REST_Response { return rest_plan( 'poll', array( 'models' => array( 'fake-text-model' ), 'account' => 'fake@example.invalid', 'heartbeat' => $heartbeat ), $connection['secret'] ); }
try {
    CPTAI_Plan::disconnect();
    CPTAI_Settings::save( array( 'source' => 'en', 'targets' => array( 'es' ), 'provider' => 'openai', 'openai_key' => 'fake-openai-test-only', 'claude_key' => 'fake-claude-test-only', 'openai_model' => 'fake-api-model', 'claude_model' => 'fake-claude-model' ) );
    $keys = array( CPTAI_Settings::key( 'openai' ), CPTAI_Settings::key( 'claude' ) );
    $settings_input = array( 'source' => 'en', 'targets' => array( 'es' ), 'provider' => 'chatgpt', 'chatgpt_model' => 'fake-text-model', 'openai_model' => 'fake-api-model', 'claude_model' => 'fake-claude-model' );
    $saved = ajax_plan( 'save_settings_ajax', $settings_input );
    assert_plan( $saved['success'] && 'chatgpt' === $saved['data']['provider'], 'new provider selectable' );
    assert_plan( $keys === array( CPTAI_Settings::key( 'openai' ), CPTAI_Settings::key( 'claude' ) ), 'switching providers preserves both API keys' );
    assert_plan( '' === CPTAI_Settings::key( 'chatgpt' ), 'plan cannot borrow Claude API key' );
    assert_plan( ! CPTAI_Settings::ready(), 'unlinked plan not ready' );
    assert_plan( is_wp_error( CPTAI_Provider::translate( array( 'Send' ), 'en', 'es' ) ), 'direct provider call cannot fall back to API' );
    assert_plan( is_wp_error( CPTAI_Provider::models( 'chatgpt' ) ), 'offline model lookup fails without inference' );
    $connection = CPTAI_Plan::connection();
    assert_plan( is_array( $connection ) && 64 === strlen( $connection['secret'] ), 'scoped bridge connection issued' );
    assert_plan( ! str_contains( wp_json_encode( get_option( 'cptai_plan_link' ) ), $connection['secret'] ), 'bridge credential stored as hash only' );
    assert_plan( 401 === rest_plan( 'poll', array(), str_repeat( 'a', 64 ) )->get_status(), 'unauthorized client rejected' );
    assert_plan( 401 === rest_plan( 'poll', array(), '' )->get_status(), 'anonymous visitor rejected' );
    assert_plan( 400 === rest_plan( 'poll', array( 'account' => array( 'bad' ) ), $connection['secret'] )->get_status(), 'invalid polling input rejected' );
    $polled = poll_plan( $connection );
    assert_plan( 200 === $polled->get_status() && null === $polled->get_data()['job'], 'authenticated worker can poll' );
    assert_plan( 'no-store, private' === $polled->get_headers()['Cache-Control'], 'bridge responses never cached' );
    assert_plan( CPTAI_Settings::ready(), 'online worker and selected model ready' );
    $linked_before_update = get_option( 'cptai_plan_link' );
    CPTAI_Store::install();
    CPTAI_Settings::save( $settings_input );
    assert_plan( $linked_before_update === get_option( 'cptai_plan_link' ), 'schema update and settings save preserve existing worker binding' );
    assert_plan( 'fake-text-model' === CPTAI_Settings::get()['chatgpt_model'] && CPTAI_Settings::ready(), 'schema update preserves selected plan model and online state' );
    assert_plan( $keys === array( CPTAI_Settings::key( 'openai' ), CPTAI_Settings::key( 'claude' ) ), 'schema update preserves both optional API credentials' );
    assert_plan( array( 'fake-text-model' ) === CPTAI_Provider::models( 'chatgpt' ), 'models use local companion catalog' );
    $status = ajax_plan( 'plan_status' );
    assert_plan( $status['success'] && $status['data']['online'], 'status available to administrator' );
    assert_plan( ! str_contains( wp_json_encode( $status ), $connection['secret'] ) && ! isset( $status['data']['hash'] ), 'admin public status omits credential and hash' );
    CPTAI_Store::capture( array( 'CPTAI plan test Send %s', 'CPTAI plan test Save' ), 'en', 'es', home_url( '/' ) );
    CPTAI_Store::put( 'CPTAI plan test Send %s', '', 'en', 'es', home_url( '/' ) );
    global $wpdb;
    $row_id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . CPTAI_Store::table() . ' WHERE original=%s AND target_lang=%s', 'CPTAI plan test Send %s', 'es' ) );
    $row = ajax_plan( 'row', array( 'id' => $row_id ) );
    $ticket = $row['data']['plan_ticket'];
    assert_plan( $row['success'] && 32 === strlen( $ticket ), 'row translation creates a queued ticket' );
    assert_plan( ! ajax_plan( 'row', array( 'id' => $row_id ) )['success'], 'concurrent request cannot duplicate inference' );
    assert_plan( ajax_plan( 'plan_result', array( 'ticket' => $ticket ) )['data']['plan_waiting'], 'browser polls without blocking PHP' );
    assert_plan( null === poll_plan( $connection, true )->get_data()['job'], 'heartbeat never claims work' );
    $work = poll_plan( $connection )->get_data()['job'];
    assert_plan( $ticket === $work['id'] && array( 'CPTAI plan test Send %s' ) === $work['strings'], 'worker receives only selected strings' );
    assert_plan( ! isset( $work['user'], $work['context'], $work['original_settings'] ), 'worker gets no WordPress admin context' );
    assert_plan( null === poll_plan( $connection )->get_data()['job'], 'second worker poll cannot repeat inference' );
    $complete = rest_plan( 'complete', array( 'id' => $ticket, 'translations' => array( 'Enviar %s' ) ), $connection['secret'] );
    assert_plan( 200 === $complete->get_status(), 'completed translation delivered' );
    assert_plan( 200 === rest_plan( 'complete', array( 'id' => $ticket, 'translations' => array( 'Override %s' ) ), $connection['secret'] )->get_status(), 'delivery retry acknowledged idempotently' );
    wp_set_current_user( 0 );
    assert_plan( is_wp_error( CPTAI_Plan::result( $ticket ) ), 'another user cannot consume result' );
    wp_set_current_user( $administrator->ID );
    $suggestion = ajax_plan( 'plan_result', array( 'ticket' => $ticket ) );
    assert_plan( 'Enviar %s' === $suggestion['data']['text'], 'result is returned for review' );
    assert_plan( array() === CPTAI_Store::find( array( 'CPTAI plan test Send %s' ), 'en', 'es' ), 'suggestion never auto saves row' );
    assert_plan( $suggestion === ajax_plan( 'plan_result', array( 'ticket' => $ticket ) ), 'repeat polling returns stable applied result' );
    $row = ajax_plan( 'row', array( 'id' => $row_id ) ); $ticket = $row['data']['plan_ticket']; poll_plan( $connection );
    rest_plan( 'complete', array( 'id' => $ticket, 'translations' => array( 'Lost marker' ) ), $connection['secret'] );
    assert_plan( ! ajax_plan( 'plan_result', array( 'ticket' => $ticket ) )['success'], 'lost placeholders rejected' );
    $batch_id = wp_generate_uuid4();
    CPTAI_Store::put( 'CPTAI plan test Save', '', 'en', 'es', home_url( '/' ) );
    set_transient( 'cptai_job_' . $batch_id, array( 'user' => $administrator->ID, 'source' => 'en', 'target' => 'es', 'strings' => array( 'CPTAI plan test Save' ), 'offset' => 0 ), HOUR_IN_SECONDS );
    $batch = ajax_plan( 'batch', array( 'job' => $batch_id ) ); $ticket = $batch['data']['plan_ticket'];
    assert_plan( $ticket === ajax_plan( 'batch', array( 'job' => $batch_id ) )['data']['plan_ticket'], 'batch retry reuses ticket' );
    poll_plan( $connection );
    CPTAI_Store::put( 'CPTAI plan test Save', 'Manual choice', 'en', 'es', home_url( '/' ) );
    rest_plan( 'complete', array( 'id' => $ticket, 'translations' => array( 'Guardar' ) ), $connection['secret'] );
    $result = ajax_plan( 'plan_result', array( 'ticket' => $ticket ) );
    assert_plan( $result['success'] && $result['data']['complete'] && 1 === $result['data']['done'], 'batch completes asynchronously' );
    assert_plan( 'Manual choice' === CPTAI_Store::find( array( 'CPTAI plan test Save' ), 'en', 'es' )['CPTAI plan test Save'], 'manual edit made during inference preserved' );
    assert_plan( 1 === get_transient( 'cptai_job_' . $batch_id )['offset'], 'batch advances once' );
    ajax_plan( 'plan_result', array( 'ticket' => $ticket ) );
    assert_plan( 1 === get_transient( 'cptai_job_' . $batch_id )['offset'], 'repeated completion does not advance twice' );
    $batch_id2 = wp_generate_uuid4();
    set_transient( 'cptai_job_' . $batch_id2, array( 'user' => $administrator->ID, 'source' => 'en', 'target' => 'es', 'strings' => array( 'CPTAI plan test Send %s' ), 'offset' => 0 ), HOUR_IN_SECONDS );
    $batch = ajax_plan( 'batch', array( 'job' => $batch_id2 ) ); $ticket = $batch['data']['plan_ticket']; poll_plan( $connection );
    rest_plan( 'complete', array( 'id' => $ticket, 'error' => 'subscription_sharing_usage_limit_exceeded' ), $connection['secret'] );
    $error = ajax_plan( 'plan_result', array( 'ticket' => $ticket ) );
    assert_plan( ! $error['success'] && str_contains( $error['data']['message'], 'API de pago' ), 'plan limit shown with no fallback' );
    assert_plan( ! isset( get_transient( 'cptai_job_' . $batch_id2 )['plan_ticket'] ), 'failed batch ticket cleared for retry' );
    $batch = ajax_plan( 'batch', array( 'job' => $batch_id2 ) );
    assert_plan( $batch['success'] && $ticket !== $batch['data']['plan_ticket'], 'explicit retry can queue again' );
    $old_secret = $connection['secret']; $connection = CPTAI_Plan::connection();
    assert_plan( 401 === rest_plan( 'poll', array(), $old_secret )->get_status(), 'new connection invalidates old helper' );
    poll_plan( $connection );
    $row = ajax_plan( 'row', array( 'id' => $row_id ) ); $ticket = $row['data']['plan_ticket'];
    $job = get_transient( 'cptai_plan_' . $ticket ); $job['expires'] = time() - 1; set_transient( 'cptai_plan_' . $ticket, $job, 60 );
    assert_plan( ! ajax_plan( 'plan_result', array( 'ticket' => $ticket ) )['success'], 'expired ticket stops inference' );
    $row = ajax_plan( 'row', array( 'id' => $row_id ) ); $ticket = $row['data']['plan_ticket']; poll_plan( $connection );
    rest_plan( 'complete', array( 'id' => $ticket, 'error' => 'fake-secret-should-never-appear' ), $connection['secret'] );
    $error = ajax_plan( 'plan_result', array( 'ticket' => $ticket ) );
    assert_plan( ! str_contains( wp_json_encode( $error ), 'fake-secret' ), 'unknown upstream messages never exposed' );
    $row = ajax_plan( 'row', array( 'id' => $row_id ) ); $ticket = $row['data']['plan_ticket']; poll_plan( $connection );
    $changed = CPTAI_Settings::get(); $changed['provider'] = 'openai'; update_option( 'cptai_settings', $changed, false );
    rest_plan( 'complete', array( 'id' => $ticket, 'translations' => array( 'Enviar %s' ) ), $connection['secret'] );
    assert_plan( ! ajax_plan( 'plan_result', array( 'ticket' => $ticket ) )['success'], 'changed provider rejects outstanding plan result' );
    $disconnected = ajax_plan( 'plan_disconnect' );
    assert_plan( $disconnected['success'] && ! CPTAI_Plan::status()['linked'], 'administrator can unlink site' );
    assert_plan( 401 === poll_plan( $connection )->get_status(), 'revoked bridge rejects worker' );
    assert_plan( $keys === array( CPTAI_Settings::key( 'openai' ), CPTAI_Settings::key( 'claude' ) ), 'unlinking preserves API credentials' );
    assert_plan( 0 === $external, 'no OpenAI/Claude calls during any plan operation' );
    echo "PASS: $checks plan bridge checks; no external requests.\n";
} finally {
    CPTAI_Plan::disconnect();
    update_option( 'cptai_settings', $original_settings, false );
    $wpdb->query( "DELETE FROM " . CPTAI_Store::table() . " WHERE original LIKE 'CPTAI plan test %'" );
}
