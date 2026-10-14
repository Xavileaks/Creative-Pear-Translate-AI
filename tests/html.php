<?php
// Lossless rendering against the real WordPress HTML API, without provider calls.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) { exit( 1 ); }
define( 'WP_INSTALLING', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/es/who-we-are/';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WordPress required.' ); }
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
$before = get_option( 'cptai_settings' );
$checks = 0;
function html_check( bool $ok, string $name ): void { $GLOBALS['checks']++; if ( ! $ok ) { throw new RuntimeException( $name ); } }
try {
    CPTAI_Settings::save( array( 'source' => 'en', 'targets' => array( 'es' ) ) );
    html_check( CPTAI_HTML::preserves_markup(), 'WordPress lossless HTML API available' );
    // The live Elementor heading contains this unclosed DIV. The browser repairs
    // it locally; DOMDocument serialization used to pull later sections into H2.
    $body = '<main><section class="process"><div><h2><div class="customTitleStyle">Our Process<div></h2></div><div><p>Next block</p></div></section><section class="cta"><h2>Work with us!</h2><svg viewBox="0 0 5 5"><defs><linearGradient id="g"><stop offset="1" /></linearGradient></defs><foreignObject><div>SVG text</div></foreignObject></svg></section></main>';
    $html = '<!doctype html><html lang="en" dir="ltr"><head><title>Our Process</title><meta name="robots" content="index,follow"><link rel="canonical" href="http://127.0.0.1:8764/who-we-are/"><style>.cta::after{content:"á > &"}</style><script type="application/json">{"a":"<div>á & café</div>"}</script></head><body>' . $body . '</body></html>';
    $doc = new CPTAI_HTML( $html );
    html_check( in_array( 'Our Process', $doc->strings(), true ) && in_array( 'Work with us!', $doc->strings(), true ), 'headings captured as separate strings' );
    html_check( ! in_array( 'SVG text', $doc->strings(), true ), 'foreign SVG content excluded' );
    $out = $doc->render( array( 'Our Process' => 'Nuestro proceso', 'Work with us!' => 'Trabaja con nosotros' ), 'es' );
    $expected_body = str_replace( array( 'Our Process', 'Work with us!' ), array( 'Nuestro proceso', 'Trabaja con nosotros' ), $body );
    html_check( str_contains( $out, '<body>' . $expected_body . '</body>' ), 'all body markup, including malformed heading, preserved byte-for-byte' );
    html_check( str_contains( $out, '.cta::after{content:"á > &"}' ), 'CSS exact bytes retained' );
    html_check( str_contains( $out, '{"a":"<div>á & café</div>"}' ), 'JSON exact bytes retained' );
    html_check( str_contains( $out, 'lang="es"' ) && str_contains( $out, 'noindex,follow' ) && str_contains( $out, '/es/who-we-are/' ), 'language and SEO attributes localized' );
    $same = ( new CPTAI_HTML( $html ) )->render( array(), 'en' );
    html_check( $same === $html, 'source rendering without translations leaves entire document identical' );
    $safe = '<!doctype html><html><head></head><body><p title="A &amp; B"> A &amp; B </p><input type="email" value="Private"><input type="submit" value="Send"><div translate="no"><p>Excluded</p></div><p>After exclusion</p><template><p>Template</p></template><iframe title="Frame">Private frame</iframe><nav class="cptai-switcher"><a href="/">English</a></nav><a href="/contact/">Send</a></body></html>';
    $doc = new CPTAI_HTML( $safe );
    foreach ( array( 'Private', 'Excluded', 'Template', 'Private frame', 'Frame', 'English' ) as $text ) { html_check( ! in_array( $text, $doc->strings(), true ), 'excluded ' . $text ); }
    html_check( in_array( 'After exclusion', $doc->strings(), true ), 'exclusion ends at closing tag' );
    $out = $doc->render( array( 'A & B' => 'C & <D>', 'Send' => 'Enviar' ), 'es' );
    html_check( str_contains( $out, '<p title="C &amp; &lt;D&gt;"> C &amp; &lt;D&gt; </p>' ), 'translated text and attributes escaped safely' );
    html_check( str_contains( $out, 'value="Enviar"' ) && str_contains( $out, '/es/contact/' ) && str_contains( $out, '<a href="/">English</a>' ), 'button and navigation translated, selector left alone' );
    html_check( home_url( '/who-we-are/?paged=2#team' ) === CPTAI_Routing::url( home_url( '/es/who-we-are/?paged=2#team' ), 'en' ), 'source selector returns original URL with query and fragment' );
    $widget = CPTAI_Elementor::render( array( 'display' => 'flags_name' ) );
    html_check( str_contains( $widget, 'href="' . home_url( '/who-we-are/' ) . '"' ) && ! str_contains( $widget, '/en/who-we-are/' ), 'Elementor source language uses original link' );
} finally { update_option( 'cptai_settings', $before ); }
echo "PASS: $checks lossless HTML checks.\n";
