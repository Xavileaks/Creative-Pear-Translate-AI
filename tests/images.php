<?php
// Isolated WordPress image replacements, no provider calls or network requests.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) { exit( 1 ); }
define( 'WP_INSTALLING', true ); define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true ); define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REQUEST_URI'] = '/es/sample/';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WordPress required.' ); }
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
wp_installing( false ); CPTAI_Images::install();
$before = get_option( 'cptai_settings' ); $checks = 0; $attachments = array();
class CPTAI_Image_Response extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', static fn() => static function () { throw new CPTAI_Image_Response(); } );
function image_check( bool $ok, string $name ): void { $GLOBALS['checks']++; if ( ! $ok ) { throw new RuntimeException( $name ); } }
function image_ajax( string $action, array $input ): array {
    $_POST = wp_slash( $input ); $_REQUEST = array_merge( $_POST, array( 'nonce' => wp_create_nonce( 'cptai_admin' ) ) );
    ob_start(); try { CPTAI_Admin::$action(); } catch ( CPTAI_Image_Response $e ) {}
    $output = ob_get_clean();
    return $output ? json_decode( $output, true, 512, JSON_THROW_ON_ERROR ) : array();
}
add_filter( 'pre_http_request', static fn() => new WP_Error( 'test', 'No external requests in image tests.' ) );
global $wpdb;
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]; wp_set_current_user( $admin->ID );
try {
    CPTAI_Settings::save( array( 'source' => 'en', 'targets' => array( 'uk', 'fr' ) ) );
    $upload = wp_upload_dir();
    foreach ( array( 'original', 'replacement' ) as $name ) {
        $file = $upload['path'] . '/cptai-image-test-' . $name . '.png';
        copy( dirname( __DIR__ ) . '/assets/design/cutout-71-fe00c9aa3e07.png', $file );
        $id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'CPTAI disposable image ' . $name, 'post_status' => 'inherit' ), $file );
        $attachments[] = $id;
        wp_update_attachment_metadata( $id, array( 'file' => ltrim( str_replace( $upload['basedir'], '', $file ), '/' ), 'width' => 800, 'height' => 600, 'sizes' => array( 'small' => array( 'file' => 'cptai-image-test-' . $name . '-300x225.png', 'width' => 300, 'height' => 225, 'mime-type' => 'image/png' ) ) ) );
        $urls[ $name ] = wp_get_attachment_url( $id );
    }
    $old = $urls['original']; $new = $urls['replacement']; $small = str_replace( '.png', '-300x225.png', $old );
    foreach ( array( 'javascript:alert(1)', 'data:image/png;base64,foo', 'https://user:pass@host/a.png', 'https://host/a.png#fragment', 'https://host/a".png', "https://host/a\n.png" ) as $bad ) { image_check( ! CPTAI_Images::valid_url( $bad ), 'unsafe URL rejected' ); }
    image_check( CPTAI_Images::valid_url( 'https://cdn.example.test/a.png?width=400&format=webp' ), 'CDN originals supported' );
    image_check( ! image_ajax( 'image_save', array( 'target' => 'uk', 'original' => $old, 'replacement' => 'https://evil.invalid/a.png' ) )['success'], 'replacement must be in local media' );
    image_check( ! image_ajax( 'image_save', array( 'target' => 'en', 'original' => $old, 'replacement' => $new ) )['success'], 'cannot replace source language image' );
    image_check( image_ajax( 'image_save', array( 'target' => 'uk', 'original' => $old, 'replacement' => $new ) )['success'], 'add using selected destination' );
    image_check( ! image_ajax( 'image_save', array( 'target' => 'uk', 'original' => $old, 'replacement' => $new ) )['success'], 'duplicate requires explicit edit' );
    $rows = image_ajax( 'image_list', array( 'target' => 'uk' ) )['data']['rows'];
    image_check( count( $rows ) === 1 && $rows[0]['replacement'] === $new, 'list reports original and replacement' );
    $map = CPTAI_Images::map( 'en', 'uk' );
    image_check( $map[ $old ] === $new && $map[ $small ] === $new, 'registered responsive sizes are aliases' );
    image_check( ! CPTAI_Images::map( 'en', 'fr' ), 'other languages retain original' );
    $html = '<html><head><script>const image="' . $old . '";</script><style>.code{--url:"' . $old . '"}</style></head><body><a href="' . $old . '"><picture><source media="(max-width:600px)" srcset="' . $small . ' 300w"><img class="photo original" width="800" height="600" style="object-fit:cover;border-radius:20px" src="' . $old . '" srcset="' . $small . ' 300w, ' . $old . ' 800w" sizes="50vw" data-lazy-src="' . $small . '" alt="Photo"></picture></a><div class="hero" style="background-image:url(&quot;' . $old . '&quot;);background-size:cover;padding:20px"></div><img src="https://other.test/unrelated.png"></body></html>';
    $out = CPTAI_Images::render( $html, $map );
    image_check( str_contains( $out, 'src="' . $new . '"' ) && str_contains( $out, 'srcset="' . $new . '"' ), 'src and responsive candidates replaced' );
    image_check( str_contains( $out, 'data-lazy-src="' . $new . '"' ), 'lazy loader uses replacement' );
    image_check( str_contains( $out, 'class="photo original" width="800" height="600" style="object-fit:cover;border-radius:20px"' ) && str_contains( $out, 'sizes="50vw"' ), 'classes sizes and styles intact' );
    image_check( str_contains( $out, '<a href="' . $old . '">' ), 'image link remains original' );
    image_check( str_contains( $out, 'const image="' . $old . '";' ) && str_contains( $out, '.code{--url:"' . $old . '"}' ), 'executable and CSS code not rewritten globally' );
    image_check( str_contains( $out, 'url(&quot;' . $new . '&quot;);background-size:cover;padding:20px' ), 'inline background changes only image URL' );
    image_check( str_contains( $out, 'src="https://other.test/unrelated.png"' ), 'unrelated image untouched' );
    image_check( CPTAI_Images::render( $out, $map ) === $out, 'repeated rendering is idempotent' );
    image_check( CPTAI_Images::render( $html, array() ) === $html, 'no mapping preserves exact original bytes' );
    image_check( ! image_ajax( 'image_save', array( 'target' => 'uk', 'original' => $old, 'replacement' => '', 'expected' => 'stale' ) )['success'], 'stale update cannot restore image' );
    image_check( ! image_ajax( 'image_save', array( 'target' => 'uk', 'original' => $new, 'replacement' => $old ) )['success'], 'cycles rejected' );
    image_check( image_ajax( 'image_save', array( 'target' => 'uk', 'original' => $old, 'replacement' => '', 'expected' => $new ) )['success'], 'restore is explicit and reversible' );
    image_check( ! CPTAI_Images::map( 'en', 'uk' ), 'restored mapping no longer changes images' );
    image_check( count( CPTAI_Images::rows( 'en', 'uk' ) ) === 1, 'restore keeps editable row' );
    image_check( image_ajax( 'image_save', array( 'target' => 'uk', 'original' => $old, 'replacement' => $new, 'expected' => '' ) )['success'], 'restored row can be enabled again' );
    wp_set_current_user( 0 );
    image_check( ! image_ajax( 'image_list', array( 'target' => 'uk' ) ), 'unauthenticated access denied' );
} finally {
    wp_set_current_user( $admin->ID );
    $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . CPTAI_Images::table() . ' WHERE original=%s', $urls['original'] ?? '' ) );
    foreach ( $attachments as $id ) { wp_delete_attachment( $id, true ); }
    update_option( 'cptai_settings', $before, false );
}
echo "PASS $checks image checks\n";
