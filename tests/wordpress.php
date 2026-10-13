<?php
// Run only on an isolated WordPress whose WP_ENVIRONMENT_TYPE is local.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) {
    fwrite( STDERR, "Usage: php tests/wordpress.php /path/to/isolated/wordpress\n" );
    exit( 1 );
}
define( 'WP_INSTALLING', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) {
    throw new RuntimeException( 'Refusing to run outside a local WordPress.' );
}
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_blog_installed() ) {
    wp_install( 'CPTAI isolated validation', 'cptai-test', 'local@example.invalid', false, '', 'cptai-local-test-password' );
}
$activation = activate_plugin( 'creative-pear-translate-ai/creative-pear-translate-ai.php' );
if ( is_wp_error( $activation ) ) {
    throw new RuntimeException( $activation->get_error_message() );
}
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
CPTAI_Routing::boot();
CPTAI_Store::install();
$checks = 0;
function verify_cptai( bool $condition, string $name ): void {
    $GLOBALS['checks']++;
    if ( ! $condition ) { throw new RuntimeException( $name ); }
}
CPTAI_Settings::save( array( 'source' => 'es', 'targets' => array( 'en', 'fr' ), 'footer' => 1 ) );
CPTAI_Store::put( 'Comprar', '', 'es', 'en', home_url( '/' ) );
$first = CPTAI_Store::capture( array( 'Comprar', 'Comprar', 'Hola %s' ), 'es', 'en', home_url( '/' ) );
verify_cptai( empty( CPTAI_Store::find( array( 'Comprar' ), 'es', 'en' ) ), 'pending never renders as empty' );
verify_cptai( CPTAI_Store::put( 'Comprar', 'Buy', 'es', 'en', home_url( '/' ) ), 'database save' );
CPTAI_Store::capture( array( 'Comprar' ), 'es', 'en', home_url( '/second/' ) );
verify_cptai( array( 'Comprar' => 'Buy' ) === CPTAI_Store::find( array( 'Comprar' ), 'es', 'en' ), 'capture does not overwrite correction' );
CPTAI_Store::fill( 'Comprar', 'Overwrite attempted', 'es', 'en' );
verify_cptai( array( 'Comprar' => 'Buy' ) === CPTAI_Store::find( array( 'Comprar' ), 'es', 'en' ), 'AI fill cannot overwrite a manual edit' );
global $wpdb;
$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CPTAI_Store::table() . ' WHERE source_lang=%s AND target_lang=%s AND original=%s', 'es', 'en', 'Comprar' ) );
verify_cptai( 1 === $count, 'one row across duplicates and pages' );
verify_cptai( empty( CPTAI_Store::find( array( 'Comprar' ), 'es', 'fr' ) ), 'languages isolated' );
$id_before = $wpdb->get_var( "SELECT id FROM " . CPTAI_Store::table() . " WHERE original='Comprar'" );
CPTAI_Store::put( 'Comprar', 'Shop now', 'es', 'en', home_url( '/' ) );
verify_cptai( $id_before === $wpdb->get_var( "SELECT id FROM " . CPTAI_Store::table() . " WHERE original='Comprar'" ), 'editing retains row ID' );
CPTAI_Store::put( 'Comprar', 'Buy', 'es', 'en', home_url( '/' ) );
CPTAI_Store::put( 'Botón dinámico', 'Dynamic button', 'es', 'en', home_url( '/' ) );
CPTAI_Store::put( 'Mensaje desplegable', 'Expanded message', 'es', 'en', home_url( '/' ) );
CPTAI_Store::put( 'Tu nombre', 'Your name', 'es', 'en', home_url( '/' ) );
$_GET['cptai_lang'] = 'en';
$html = '<!doctype html><html lang="es"><head><title>Comprar</title></head><body><button>Comprar</button><button>Comprar</button><p>Pendiente</p><script>window.example="Comprar";</script></body></html>';
$output = CPTAI_Frontend::translate( $html );
verify_cptai( 3 === substr_count( $output, 'Buy' ), 'one edit changes title and all buttons' );
verify_cptai( str_contains( $output, 'Pendiente' ), 'unknown original preserved' );
verify_cptai( str_contains( $output, 'window.example="Comprar"' ), 'scripts preserved' );
$_GET['cptai_lang'] = 'not-allowed';
verify_cptai( $html === CPTAI_Frontend::translate( $html ), 'unconfigured language does not translate' );
unset( $_GET['cptai_lang'] );
$administrator = get_user_by( 'login', 'cptai-test' );
if ( ! $administrator ) {
    $administrators = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
    $administrator = $administrators[0] ?? null;
}
if ( ! $administrator ) {
    throw new RuntimeException( 'The isolated WordPress needs an administrator.' );
}
wp_set_current_user( $administrator->ID );
$_GET['s'] = 'Comprar';
ob_start(); CPTAI_Admin::page(); $admin_html = ob_get_clean();
unset( $_GET['s'] );
verify_cptai( str_contains( $admin_html, 'Comprar' ) && str_contains( $admin_html, 'Guardar para todas las apariciones' ), 'native backend renders dictionary' );
verify_cptai( str_contains( $admin_html, 'value="" autocomplete="new-password"' ), 'API fields are always empty' );
wp_set_current_user( 0 );
$_GET['cptai_scan'] = 'en'; $_GET['_cptai_nonce'] = wp_create_nonce( 'cptai_scan' );
verify_cptai( '' === CPTAI_Frontend::scan_target(), 'visitors cannot enable capture' );
unset( $_GET['cptai_scan'], $_GET['_cptai_nonce'] );
wp_set_current_user( $administrator->ID );
$content = '<h2>Prueba de cadenas</h2><button>Comprar</button> <button>Comprar</button><input type="submit" value="Comprar"><input placeholder="Tu nombre"><p translate="no">Texto excluido</p><div id="cptai-dynamic"></div><script>setTimeout(function(){var b=document.createElement("button");b.textContent="Botón dinámico";b.onclick=function(){var p=document.createElement("p");p.textContent="Mensaje desplegable";document.getElementById("cptai-dynamic").appendChild(p);};document.getElementById("cptai-dynamic").appendChild(b);},500);</script>[creative_pear_language_switcher]';
$fixture_id = get_option( 'cptai_fixture_id' );
$data = array( 'post_title' => 'Comprar', 'post_content' => $content, 'post_status' => 'publish', 'post_type' => 'page' );
if ( $fixture_id ) { $data['ID'] = $fixture_id; }
$fixture_id = wp_insert_post( $data );
update_option( 'cptai_fixture_id', $fixture_id );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $fixture_id );
echo 'PASS: ' . $checks . ' WordPress ' . $GLOBALS['wp_version'] . " integration checks.\n";
