<?php
// Full-site HTML scan: isolated WordPress, deterministic HTTP, no AI requests.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) { exit( 1 ); }
define( 'WP_INSTALLING', true ); define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true ); define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WordPress required.' ); }
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
wp_installing( false ); CPTAI_Routing::boot();
$before = get_option( 'cptai_settings' ); $cart_before = get_option( 'woocommerce_cart_page_id' );
$checks = 0; $requests = array(); $posts = array(); $jobs = array();
global $wpdb;
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]; wp_set_current_user( $admin->ID );
class CPTAI_Scan_Response extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', static fn() => static function () { throw new CPTAI_Scan_Response(); } );
function scan_check( bool $ok, string $name ): void { $GLOBALS['checks']++; if ( ! $ok ) { throw new RuntimeException( $name ); } }
function scan_ajax( string $action, array $input = array() ): array {
    $_POST = wp_slash( $input ); $_REQUEST = array_merge( $_POST, array( 'nonce' => wp_create_nonce( 'cptai_admin' ) ) );
    ob_start(); try { CPTAI_Admin::$action(); } catch ( CPTAI_Scan_Response $e ) {}
    return json_decode( ob_get_clean(), true, 512, JSON_THROW_ON_ERROR );
}
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
    if ( ! str_starts_with( $url, home_url( '/' ) ) ) { return new WP_Error( 'test', 'External HTTP forbidden.' ); }
    $GLOBALS['requests'][] = array( $url, $args );
    $path = wp_parse_url( $url, PHP_URL_PATH );
    $status = 200; $headers = array();
    $html = '<!doctype html><html><head><title>CPTAI site scan common</title></head><body><p>CPTAI site scan URL ' . esc_html( $path ) . '</p><button>CPTAI site scan common</button></body></html>';
    if ( str_contains( $path, 'scan-redirect-external' ) ) { $status = 301; $headers['location'] = 'https://evil.invalid/private/'; }
    elseif ( str_contains( $path, 'scan-redirect-private' ) ) { $status = 302; $headers['location'] = get_permalink( get_option( 'woocommerce_cart_page_id' ) ); }
    elseif ( str_contains( $path, 'scan-redirect' ) ) { $status = 301; $headers['location'] = home_url( '/scan-final/' ); }
    elseif ( str_contains( $path, 'scan-failure' ) ) { $status = 500; }
    elseif ( str_contains( $path, 'scan-large' ) ) { $html .= str_repeat( 'x', 2097153 ); }
    elseif ( str_contains( $path, 'scan-json' ) ) { $html = '{}'; }
    return array( 'response' => array( 'code' => $status ), 'headers' => $headers, 'body' => $html );
}, 10, 3 );
try {
    CPTAI_Settings::save( array( 'source' => 'en', 'targets' => array( 'uk' ), 'provider' => 'openai', 'openai_model' => '' ) );
    scan_check( ! CPTAI_Settings::ready(), 'fixture intentionally has no usable AI connection' );
    if ( ! post_type_exists( 'product' ) ) { register_post_type( 'product', array( 'public' => true ) ); }
    $fixtures = array();
    foreach ( array( 'page', 'post', 'product' ) as $type ) {
        $id = wp_insert_post( array( 'post_title' => 'CPTAI scan ' . $type, 'post_name' => 'cptai-scan-' . $type, 'post_type' => $type, 'post_status' => 'publish' ) );
        $posts[] = $id; $fixtures[] = $id;
    }
    $draft = wp_insert_post( array( 'post_title' => 'CPTAI scan draft', 'post_type' => 'page', 'post_status' => 'draft' ) ); $posts[] = $draft;
    $protected = wp_insert_post( array( 'post_title' => 'CPTAI scan protected', 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => 'fake-password' ) ); $posts[] = $protected;
    $cart = wp_insert_post( array( 'post_title' => 'CPTAI scan cart', 'post_type' => 'page', 'post_status' => 'publish' ) ); $posts[] = $cart; update_option( 'woocommerce_cart_page_id', $cart );
    $failing = wp_insert_post( array( 'post_title' => 'CPTAI scan failure', 'post_name' => 'cptai-scan-failure', 'post_type' => 'page', 'post_status' => 'publish' ) ); $posts[] = $failing;
    $changing = wp_insert_post( array( 'post_title' => 'CPTAI scan changing', 'post_type' => 'page', 'post_status' => 'publish' ) ); $posts[] = $changing;
    CPTAI_Store::put( 'CPTAI site scan common', 'Manual choice retained', 'en', 'uk', home_url( '/' ) );
    $prepared = scan_ajax( 'scan_prepare', array( 'target' => 'uk' ) );
    scan_check( $prepared['success'] && $prepared['data']['total'] >= 4, 'all-site job can start without AI' );
    $job_id = $prepared['data']['job']; $jobs[] = $job_id;
    $state = get_transient( 'cptai_site_scan_' . $job_id ); $ids = array_column( $state['items'], 'id' );
    foreach ( $fixtures as $id ) { scan_check( in_array( $id, $ids, true ), 'published type included ' . get_post_type( $id ) ); }
    foreach ( array( $draft, $protected, $cart ) as $id ) { scan_check( ! in_array( $id, $ids, true ), 'nonpublic or sensitive page excluded ' . $id ); }
    scan_check( count( $state['items'] ) === count( array_unique( array_column( $state['items'], 'url' ) ) ), 'home page and permalink deduplicated' );
    wp_update_post( array( 'ID' => $changing, 'post_status' => 'draft' ) );
    $first = scan_ajax( 'scan_batch', array( 'job' => $job_id, 'offset' => 0 ) );
    scan_check( $first['success'] && 1 === $first['data']['done'], 'one URL processed per AJAX call' );
    $count_before = count( $requests );
    $replay = scan_ajax( 'scan_batch', array( 'job' => $job_id, 'offset' => 0 ) );
    scan_check( $first === $replay && $count_before === count( $requests ), 'lost AJAX response replay does not repeat fetch or counts' );
    $next = $first;
    while ( ! $next['data']['complete'] ) {
        $next = scan_ajax( 'scan_batch', array( 'job' => $job_id, 'offset' => $next['data']['done'] ) );
        scan_check( $next['success'], 'reading continues after pause and failed URLs' );
    }
    $result = $next['data'];
    scan_check( 1 === $result['failed'] && 1 === $result['skipped'], 'HTTP failure reported and newly private page skipped' );
    scan_check( str_contains( $result['failures'][0]['url'], 'scan-failure' ), 'failed URL available for manual retry' );
    scan_check( $result['read'] + $result['failed'] + $result['skipped'] === $result['total'], 'progress accounts for every scheduled URL' );
    $dictionary = CPTAI_Store::dictionary( 'en', 'uk' );
    scan_check( 'Manual choice retained' === $dictionary['CPTAI site scan common'], 'existing translation never overwritten' );
    $common_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CPTAI_Store::table() . ' WHERE source_lang=%s AND target_lang=%s AND original=%s', 'en', 'uk', 'CPTAI site scan common' ) );
    scan_check( 1 === $common_count, 'shared button has one dictionary entry across whole site' );
    foreach ( $requests as list( $url, $args ) ) {
        scan_check( empty( $args['cookies'] ) && ! isset( $args['headers']['Authorization'] ) && 0 === $args['redirection'] && 2097153 === $args['limit_response_size'], 'anonymous bounded request ' . $url );
    }
    $redirect = CPTAI_Scanner::read( home_url( '/scan-redirect/' ), 'en', 'uk' );
    scan_check( ! is_wp_error( $redirect ) && home_url( '/scan-final/' ) === $redirect['url'], 'validated local redirects followed' );
    $count_before = count( $requests );
    scan_check( is_wp_error( CPTAI_Scanner::read( home_url( '/scan-redirect-external/' ), 'en', 'uk' ) ) && count( $requests ) === $count_before + 1, 'external redirect rejected before sending request' );
    $count_before = count( $requests );
    scan_check( is_wp_error( CPTAI_Scanner::read( home_url( '/scan-redirect-private/' ), 'en', 'uk' ) ) && count( $requests ) === $count_before + 1, 'sensitive redirect rejected before sending request' );
    scan_check( is_wp_error( CPTAI_Scanner::read( home_url( '/scan-large/' ), 'en', 'uk' ) ), 'oversized document rejected' );
    scan_check( is_wp_error( CPTAI_Scanner::read( home_url( '/scan-json/' ), 'en', 'uk' ) ), 'non-HTML document rejected' );
    $another = scan_ajax( 'scan_prepare', array( 'target' => 'uk' ) )['data']['job']; $jobs[] = $another;
    add_option( 'cptai_site_scan_lock_' . $another, time() . ':' . wp_generate_uuid4(), '', false );
    $busy = scan_ajax( 'scan_batch', array( 'job' => $another, 'offset' => 0 ) );
    scan_check( ! $busy['success'] && 'busy' === $busy['data']['code'], 'concurrent request blocked' );
    update_option( 'cptai_site_scan_lock_' . $another, ( time() - 200 ) . ':' . wp_generate_uuid4() );
    scan_check( scan_ajax( 'scan_batch', array( 'job' => $another, 'offset' => 0 ) )['success'], 'abandoned lease expires and scan can continue' );
    wp_set_current_user( 0 );
    scan_check( is_wp_error( CPTAI_Scanner::step( $another, 1 ) ), 'job bound to administrator' );
    ob_start(); try { CPTAI_Admin::scan_prepare(); } catch ( CPTAI_Scan_Response $e ) {} ob_end_clean();
    wp_set_current_user( $admin->ID );
    $changed = CPTAI_Settings::get(); $changed['source'] = 'fr'; update_option( 'cptai_settings', $changed );
    scan_check( is_wp_error( CPTAI_Scanner::step( $another, 1 ) ), 'source change pauses existing scan' );
} catch ( Throwable $e ) { fwrite( STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n" ); throw $e; }
finally {
    update_option( 'cptai_settings', $before ); update_option( 'woocommerce_cart_page_id', $cart_before );
    foreach ( $jobs as $id ) { delete_transient( 'cptai_site_scan_' . $id ); delete_option( 'cptai_site_scan_lock_' . $id ); }
    $wpdb->query( "DELETE FROM " . CPTAI_Store::table() . " WHERE original LIKE 'CPTAI site scan %'" );
    foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
}
echo "PASS: $checks site-scan checks; no real HTTP or AI requests.\n";
