<?php
// Standalone regressions with the real WordPress lexical HTML API. No installation.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-includes/html-api/class-wp-html-tag-processor.php' ) ) {
    fwrite( STDERR, "Usage: php tests/core.php /path/to/wordpress-core\n" ); exit( 1 );
}
$root = $argv[1] . '/wp-includes/';
foreach ( array( 'utf8.php', 'class-wp-token-map.php' ) as $file ) { if ( is_file( $root . $file ) ) { require $root . $file; } }
foreach ( array( 'html5-named-character-references', 'class-wp-html-span', 'class-wp-html-attribute-token', 'class-wp-html-text-replacement', 'class-wp-html-decoder', 'class-wp-html-tag-processor' ) as $file ) {
    require $root . 'html-api/' . $file . '.php';
}
function wp_kses_uri_attributes() { return array( 'href', 'src' ); }
function esc_url( $url ) { return htmlspecialchars( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
require __DIR__ . '/run.php';
