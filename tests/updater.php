<?php
// Native update integration using isolated WordPress and simulated GitHub only.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) { exit( 1 ); }
define( 'WP_INSTALLING', true ); define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WordPress required.' ); }
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
wp_installing( false );
$before = get_option( CPTAI_Updater::STATE ); $cron_before = get_option( 'cron' ); $updates_before = get_site_transient( 'update_plugins' ); $auto_before = get_site_option( 'auto_update_plugins' );
$checks = 0; $requests = array(); $code = 200; $fixture = array( 'tag_name' => 'v2.0.0', 'draft' => false, 'prerelease' => false, 'body' => '<script>alert(1)</script>', 'assets' => array( array( 'name' => 'creative-pear-translate-ai.zip', 'state' => 'uploaded', 'browser_download_url' => CPTAI_Updater::REPO . '/releases/download/v2.0.0/creative-pear-translate-ai.zip' ) ) );
function update_check( bool $ok, string $name ): void { $GLOBALS['checks']++; if ( ! $ok ) { throw new RuntimeException( $name ); } }
function update_expire(): void { $state = get_option( CPTAI_Updater::STATE, array() ); $state['checked'] = time() - 301; update_option( CPTAI_Updater::STATE, $state, false ); }
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
    if ( 'https://api.github.com/repos/Xavileaks/Creative-Pear-Translate-AI/releases/latest' !== $url ) { return new WP_Error( 'test', 'No real network requests allowed.' ); }
    $GLOBALS['requests'][] = $args;
    if ( 0 === $GLOBALS['code'] ) { return new WP_Error( 'offline', 'Private raw connection failure.' ); }
    return array( 'response' => array( 'code' => $GLOBALS['code'] ), 'headers' => array( 'etag' => '"cptai-fixture"' ), 'body' => is_array( $GLOBALS['fixture'] ) ? wp_json_encode( $GLOBALS['fixture'] ) : $GLOBALS['fixture'] );
}, 10, 3 );
try {
    delete_option( CPTAI_Updater::STATE ); delete_option( 'cptai_github_update_lock' );
    CPTAI_Updater::boot(); CPTAI_Updater::schedule(); CPTAI_Updater::schedule();
    $events = 0;
    foreach ( _get_cron_array() as $hooks ) { $events += count( $hooks[ CPTAI_Updater::HOOK ] ?? array() ); }
    $event = wp_get_scheduled_event( CPTAI_Updater::HOOK );
    update_check( 1 === $events && 300 === $event->interval, 'exactly one recurring five-minute event' );
    update_check( $event->timestamp > time() && $event->timestamp <= time() + 300, 'first cron event due within five minutes' );
    $file = plugin_basename( CPTAI_FILE );
    set_site_transient( 'update_plugins', (object) array( 'last_checked' => 123, 'response' => array( 'other/other.php' => (object) array( 'new_version' => '3.0' ) ) ) );
    CPTAI_Updater::check();
    $state = get_option( CPTAI_Updater::STATE );
    update_check( 1 === count( $requests ) && '2.0.0' === $state['release']['version'], 'cron retrieves stable release metadata' );
    update_check( '' === $state['error'] && '"cptai-fixture"' === $state['etag'], 'successful query and conditional cache recorded' );
    $updates = get_site_transient( 'update_plugins' );
    update_check( '2.0.0' === $updates->response[ $file ]->new_version, 'new version offered in native updates' );
    update_check( isset( $updates->response['other/other.php'] ) && 123 === $updates->last_checked, 'other plugin updates and core timestamp preserved' );
    update_check( ! isset( $updates->response[ $file ]->autoupdate ) && get_site_option( 'auto_update_plugins' ) === $auto_before, 'automatic installation remains administrator choice' );
    CPTAI_Updater::check(); CPTAI_Updater::native_update( false, array(), $file, array() ); get_site_transient( 'update_plugins' );
    update_check( 1 === count( $requests ), 'admin cron and native readers share five-minute throttle' );
    $args = $requests[0];
    update_check( 10 === $args['timeout'] && 0 === $args['redirection'] && 131073 === $args['limit_response_size'], 'bounded HTTPS request without redirects' );
    update_check( empty( $args['cookies'] ) && empty( $args['headers']['Authorization'] ), 'no site cookies or tokens sent to GitHub' );
    update_check( false === CPTAI_Updater::native_update( false, array(), 'other/plugin.php', array() ), 'hostname filter leaves other GitHub plugins alone' );
    update_expire(); $code = 304; CPTAI_Updater::check();
    update_check( 2 === count( $requests ) && '"cptai-fixture"' === $requests[1]['headers']['If-None-Match'], 'five-minute renewal uses ETag' );
    update_check( '2.0.0' === CPTAI_Updater::payload()['new_version'], '304 preserves known release' );
    update_expire(); $code = 503; CPTAI_Updater::check();
    update_check( '2.0.0' === CPTAI_Updater::payload()['new_version'] && get_option( CPTAI_Updater::STATE )['error'], 'temporary failures preserve recently verified release' );
    CPTAI_Updater::check(); update_check( 3 === count( $requests ), 'failures do not cause per-page retry storm' );
    update_expire(); $code = 0; CPTAI_Updater::check();
    update_check( ! str_contains( get_option( CPTAI_Updater::STATE )['error'], 'Private raw' ), 'network failures reported without raw details' );
    update_expire(); $code = 200; $fixture = '{not json'; CPTAI_Updater::check();
    update_check( '2.0.0' === CPTAI_Updater::payload()['new_version'], 'malformed response does not destroy recent good metadata' );
    $valid = array( 'tag_name' => 'v2.0.0', 'assets' => array( array( 'name' => 'creative-pear-translate-ai.zip', 'state' => 'uploaded', 'browser_download_url' => CPTAI_Updater::REPO . '/releases/download/v2.0.0/creative-pear-translate-ai.zip' ) ) );
    foreach ( array( 'draft', 'prerelease' ) as $flag ) { update_check( false === CPTAI_Updater::parse( $valid + array( $flag => true ) ), 'unpublished release rejected' ); }
    foreach ( array( 'v2.0.0-beta', 'v2.0', '../../bad', array( 'v2.0.0' ) ) as $tag ) { $bad = $valid; $bad['tag_name'] = $tag; update_check( false === CPTAI_Updater::parse( $bad ), 'invalid tag rejected' ); }
    $bad = $valid; $bad['assets'][0]['browser_download_url'] = 'https://evil.invalid/plugin.zip';
    update_check( false === CPTAI_Updater::parse( $bad ), 'external download package rejected' );
    $bad = $valid; $bad['assets'] = array(); update_check( false === CPTAI_Updater::parse( $bad ), 'source-code archive never substituted for installable ZIP' );
    $state = get_option( CPTAI_Updater::STATE ); $state['verified'] = time() - DAY_IN_SECONDS - 1; update_option( CPTAI_Updater::STATE, $state, false );
    $updates = get_site_transient( 'update_plugins' );
    update_check( ! isset( $updates->response[ $file ] ) && isset( $updates->no_update[ $file ] ), 'expired metadata cannot offer stale package' );
    update_expire(); $fixture = $valid; $fixture['body'] = '<script>unsafe notes</script>'; CPTAI_Updater::check();
    $info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'creative-pear-translate-ai' ) );
    update_check( '2.0.0' === $info->version && str_contains( $info->download_link, '/v2.0.0/' ), 'native plugin details use release ZIP' );
    update_check( ! str_contains( $info->sections['changelog'], '<script>' ), 'GitHub notes cannot inject HTML' );
    update_check( false === apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'other' ) ), 'other plugin information remains untouched' );
    $state = get_option( CPTAI_Updater::STATE ); $state['release']['version'] = '1.0.0'; update_option( CPTAI_Updater::STATE, $state, false );
    update_check( ! isset( get_site_transient( 'update_plugins' )->response[ $file ] ), 'older release never offered as downgrade' );
    update_expire(); add_option( 'cptai_github_update_lock', time() . ':fixture', '', false ); $count = count( $requests ); CPTAI_Updater::check();
    update_check( $count === count( $requests ), 'concurrent request lease avoids duplicate query' );
    update_option( 'cptai_github_update_lock', ( time() - 61 ) . ':abandoned', false ); CPTAI_Updater::check();
    update_check( $count + 1 === count( $requests ), 'abandoned request lease recovers' );
    CPTAI_Updater::completed( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( $file ) ) );
    update_check( ! get_option( CPTAI_Updater::STATE ), 'updating own plugin resets metadata cache' );
    CPTAI_Updater::deactivate(); update_check( ! wp_next_scheduled( CPTAI_Updater::HOOK ), 'deactivation removes only own recurring event' );
} finally {
    remove_filter( 'site_transient_update_plugins', array( CPTAI_Updater::class, 'inject' ) );
    if ( false === $before ) { delete_option( CPTAI_Updater::STATE ); } else { update_option( CPTAI_Updater::STATE, $before, false ); }
    delete_option( 'cptai_github_update_lock' ); update_option( 'cron', $cron_before, false );
    set_site_transient( 'update_plugins', $updates_before );
}
echo "PASS $checks GitHub updater checks; no real external requests.\n";
