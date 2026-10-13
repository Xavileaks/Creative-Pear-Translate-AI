<?php
// Verify independent identifiers with XLeon installed in an isolated local WordPress.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) {
    fwrite( STDERR, "Usage: php tests/coexistence.php /path/to/isolated/wordpress\n" );
    exit( 1 );
}
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) {
    throw new RuntimeException( 'Refusing to run outside a local WordPress.' );
}
foreach ( array( 'xleon-translate-ai', 'creative-pear-translate-ai' ) as $slug ) {
    require_once WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
}
$checks = 0;
function verify_independence( bool $condition, string $name ): void {
    $GLOBALS['checks']++;
    if ( ! $condition ) { throw new RuntimeException( $name ); }
}
$original_options = get_option( 'xlt_settings' );
$original_dictionary = XLT_Store::dictionary( 'es', 'en' );
verify_independence( XLT_Store::table() !== CPTAI_Store::table(), 'independent database tables' );
verify_independence( XLT_FILE !== CPTAI_FILE, 'independent plugin constants' );
CPTAI_Store::capture( array( 'Coexistence fixture' ), 'es', 'en', home_url( '/' ) );
CPTAI_Store::put( 'Coexistence fixture', 'Creative Pear fixture', 'es', 'en', home_url( '/' ) );
verify_independence( $original_options === get_option( 'xlt_settings' ), 'original settings unchanged' );
verify_independence( $original_dictionary === XLT_Store::dictionary( 'es', 'en' ), 'original dictionary unchanged' );
$_GET['xlt_lang'] = 'en';
verify_independence( 'en' === XLT_Frontend::target() && '' === CPTAI_Frontend::target(), 'original language parameter isolated' );
unset( $_GET['xlt_lang'] );
$_GET['cptai_lang'] = 'en';
verify_independence( '' === XLT_Frontend::target() && 'en' === CPTAI_Frontend::target(), 'Creative Pear language parameter isolated' );
unset( $_GET['cptai_lang'] );
XLT_Frontend::boot();
CPTAI_Frontend::boot();
verify_independence( isset( $GLOBALS['shortcode_tags']['xleon_language_switcher'], $GLOBALS['shortcode_tags']['creative_pear_language_switcher'] ), 'independent language shortcodes' );
XLT_Admin::boot();
CPTAI_Admin::boot();
verify_independence( has_action( 'wp_ajax_xlt_capture' ) && has_action( 'wp_ajax_cptai_capture' ), 'independent AJAX handlers' );
echo 'PASS: ' . $checks . " coexistence checks.\n";
