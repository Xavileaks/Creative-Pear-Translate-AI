<?php
// Standalone regression tests: no WordPress installation, network or credentials.
define( 'ABSPATH', __DIR__ );
$options = array();
$http_response = array();
$http_request = array();
class WP_Error {
    public function __construct( public string $code, public string $message, public array $data = array() ) {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; }
function get_locale() { return 'es_ES'; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function wp_salt( $scheme ) { return 'isolated-test-salt-no-real-secret'; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return ( $GLOBALS['test_home'] ?? 'https://example.org' ) . $path; }
function wp_unslash( $value ) { return $value; }
function flush_rewrite_rules( $hard = true ) {}
function remove_query_arg( $keys, $url ) {
    $fragment = ''; $parts = explode( '#', $url, 2 ); $url = $parts[0];
    if ( isset( $parts[1] ) ) { $fragment = '#' . $parts[1]; }
    $parts = explode( '?', $url, 2 ); parse_str( $parts[1] ?? '', $query );
    foreach ( (array) $keys as $key ) { unset( $query[ $key ] ); }
    return $parts[0] . ( $query ? '?' . http_build_query( $query ) : '' ) . $fragment;
}
function add_query_arg( $key, $value, $url ) {
    $fragment = ''; $parts = explode( '#', $url, 2 ); $url = $parts[0];
    if ( isset( $parts[1] ) ) { $fragment = '#' . $parts[1]; }
    $parts = explode( '?', $url, 2 ); parse_str( $parts[1] ?? '', $query ); $query[ $key ] = $value;
    return $parts[0] . '?' . http_build_query( $query ) . $fragment;
}
function wp_remote_get( $url, $args ) { $GLOBALS['http_request'] = array( $url, $args ); return $GLOBALS['http_response']; }
function wp_remote_post( $url, $args ) { return wp_remote_get( $url, $args ); }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
class WP_Http {
    public static function make_absolute_url( $url, $base ) {
        if ( preg_match( '~^https?://~', $url ) ) { return $url; }
        if ( str_starts_with( $url, '//' ) ) { return 'https:' . $url; }
        if ( str_starts_with( $url, '/' ) ) { return 'https://example.org' . $url; }
        return 'https://example.org/' . $url;
    }
}
foreach ( array( 'settings', 'provider', 'calendar', 'html', 'routing', 'frontend' ) as $file ) {
    require dirname( __DIR__ ) . '/includes/class-' . $file . '.php';
}
$checks = 0;
set_error_handler( static function ( $severity, $message, $file, $line ) {
    throw new RuntimeException( "$message at $file:$line" );
} );
function check( bool $pass, string $message ): void {
    $GLOBALS['checks']++;
    if ( ! $pass ) { throw new RuntimeException( $message ); }
}
function response( array $body, int $code = 200 ): void {
    $GLOBALS['http_response'] = array( 'code' => $code, 'body' => json_encode( $body ) );
}

check( true === CPTAI_Settings::save( array( 'source' => 'es', 'targets' => array( 'es', 'en', 'fr', 'bad' ), 'openai_key' => 'test-key-not-a-credential', 'openai_model' => 'test-text-model' ) ), 'settings save' );
check( array( 'en', 'fr' ) === CPTAI_Settings::get()['targets'], 'validated destinations exclude source' );
check( 'test-key-not-a-credential' === CPTAI_Settings::key( 'openai' ), 'encrypted key round trip' );
check( ! str_contains( $options['cptai_settings']['openai_key'], 'test-key' ), 'key stored encrypted' );
CPTAI_Settings::save( array( 'source' => 'es', 'targets' => array( 'en' ), 'openai_model' => 'test-text-model' ) );
check( 'test-key-not-a-credential' === CPTAI_Settings::key( 'openai' ), 'blank field preserves key' );
check( is_wp_error( CPTAI_Settings::save( array( 'source' => 'bad' ) ) ), 'invalid source rejected' );
check( is_wp_error( CPTAI_Settings::save( array( 'source' => 'es', 'openai_key' => "bad\r\nkey" ) ) ), 'header injection rejected' );
check( CPTAI_Provider::valid( 'Hola %1$s {{name}} [button id="1"]', 'Hello %1$s {{name}} [button id="1"]' ), 'markers preserved' );
check( ! CPTAI_Provider::valid( 'Hola %s', 'Hello' ), 'lost marker rejected' );
check( ! CPTAI_Provider::valid( 'Hola', '<script>alert(1)</script>' ), 'HTML rejected' );
check( ! CPTAI_Provider::valid( 'Hola', '' ), 'empty output rejected' );
response( array( 'status' => 'completed', 'output' => array( array( 'content' => array( array( 'type' => 'output_text', 'text' => '["Buy","Hello %s"]' ) ) ) ) ) );
check( array( 'Buy', 'Hello %s' ) === CPTAI_Provider::translate( array( 'Comprar', 'Hola %s' ), 'es', 'en' ), 'OpenAI output parsed' );
check( 'https://api.openai.com/v1/responses' === $http_request[0], 'fixed OpenAI endpoint' );
$payload = json_decode( $http_request[1]['body'], true );
check( false === $payload['store'], 'OpenAI storage disabled' );
check( ! isset( $payload['temperature'] ), 'reasoning-model compatibility' );
response( array( 'status' => 'incomplete' ) );
check( is_wp_error( CPTAI_Provider::translate( array( 'Comprar' ), 'es', 'en' ) ), 'truncated response rejected' );
response( array( 'status' => 'completed', 'output' => array( array( 'content' => array( array( 'type' => 'output_text', 'text' => '["Buy"]' ) ) ) ) ) );
check( is_wp_error( CPTAI_Provider::translate( array( 'Comprar', 'Hola' ), 'es', 'en' ) ), 'partial array rejected' );
response( array( 'error' => array( 'message' => 'test-key-not-a-credential' ) ), 401 );
$error = CPTAI_Provider::translate( array( 'Hola' ), 'es', 'en' );
check( is_wp_error( $error ) && ! str_contains( $error->message, 'test-key' ), 'API error cannot echo secrets' );
CPTAI_Settings::save( array( 'source' => 'es', 'targets' => array( 'en' ), 'provider' => 'claude', 'claude_key' => 'test-claude-not-a-credential', 'claude_model' => 'test-claude-model' ) );
response( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => '["Buy"]' ) ) ) );
check( array( 'Buy' ) === CPTAI_Provider::translate( array( 'Comprar' ), 'es', 'en' ), 'Claude output parsed' );
check( 'https://api.anthropic.com/v1/messages' === $http_request[0] && '2023-06-01' === $http_request[1]['headers']['anthropic-version'], 'Claude endpoint and version' );
response( array( 'data' => array( array( 'id' => 'model-b' ), array( 'id' => 'model-a' ) ) ) );
check( array( 'model-a', 'model-b' ) === CPTAI_Provider::models( 'claude' ), 'account model list parsed' );

$_SERVER['REQUEST_URI'] = '/';
$html = '<!DOCTYPE html><html lang="es"><head><title>Comprar</title><meta name="description" content="A &amp; B"><script>const a="Comprar";</script><style>.Comprar{}</style></head><body><button> Comprar </button><a href="/shop/">Comprar</a><input type="submit" value="Comprar"><input type="email" value="private@example.org"><input placeholder="Tu nombre"><p>A &amp; B</p><p translate="no">No traducir</p><div class="notranslate">Excluir</div><div id="wpadminbar">Administrador</div><textarea>Privado</textarea><svg><text>Vector</text></svg><nav class="cptai-switcher"><a href="/">Español</a></nav><script type="application/json">{"name":"Comprar"}</script></body></html>';
$doc = new CPTAI_HTML( $html );
$strings = $doc->strings();
check( 1 === count( array_keys( $strings, 'Comprar', true ) ), 'duplicate button appears once' );
check( in_array( 'A & B', $strings, true ) && in_array( 'Tu nombre', $strings, true ), 'attributes and entities captured' );
foreach ( array( 'No traducir', 'Excluir', 'Administrador', 'Privado', 'Vector', 'Español', 'private@example.org' ) as $excluded ) {
    check( ! in_array( $excluded, $strings, true ), 'excluded text: ' . $excluded );
}
$rendered = $doc->render( array( 'Comprar' => 'Buy', 'A & B' => 'C & D', 'Tu nombre' => 'Your name' ), 'en' );
check( 4 === substr_count( $rendered, 'Buy' ), 'same translation applied to title and every button/link' );
check( str_contains( $rendered, 'const a="Comprar";' ), 'script content untouched' );
$unicode = '<!doctype html><html><head><style>.test::after{content:"Botón dinámico"}</style></head><body><script data-test="a>b">const name="Botón dinámico & café"; const json={"x":"á"};</script></body></html>';
$unicode_rendered = ( new CPTAI_HTML( $unicode ) )->render( array(), 'en' );
check( str_contains( $unicode_rendered, 'const name="Botón dinámico & café"; const json={"x":"á"};' ), 'Unicode and ampersands in executable script preserved byte-for-byte' );
check( str_contains( $unicode_rendered, 'content:"Botón dinámico"' ), 'Unicode CSS preserved byte-for-byte' );
check( str_contains( $rendered, 'lang="en"' ) && str_contains( $rendered, 'noindex,follow' ), 'language and indexing correct' );
check( str_contains( $rendered, 'C &amp; D' ), 'plain text ampersands serialized safely' );
check( str_contains( $rendered, 'content="C &amp; D"' ), 'ampersands in attributes preserved' );
check( str_contains( $rendered, 'href="https://example.org/en/shop/"' ), 'internal navigation retains language folder' );
check( str_contains( $rendered, '<a href="/">Espa' ), 'switcher links preserved' );
foreach ( array( '/?add-to-cart=1', '/wp-admin/', 'https://other.org/page', '/file.pdf', '#fragment', 'mailto:hello@example.org', 'https://example.org:444/' ) as $url ) {
    check( $url === CPTAI_Frontend::link( $url, 'en' ), 'unsafe or external navigation untouched: ' . $url );
}
check( ! CPTAI_Frontend::local_url( 'https://user:pass@example.org/' ), 'URL credentials rejected' );
check( 'https://example.org/es/shop/?paged=2#items' === CPTAI_Routing::url( 'https://example.org/en/shop/?paged=2#items', 'es' ), 'changing language replaces prefix and preserves query and fragment' );
check( 'https://example.org/shop/' === CPTAI_Routing::source_url( 'https://example.org/en/shop/?cptai_lang=en' ), 'canonical source URL removes language prefix and legacy parameter' );
check( 'https://example.org/es/' === CPTAI_Routing::url( 'https://example.org/', 'es' ), 'source language also has a folder' );
check( '' === CPTAI_Routing::language( 'https://example.org/unknown/shop/' ), 'unknown prefix ignored' );
$rules = CPTAI_Routing::rules( array( '^shop/([^/]+)/?$' => 'index.php?product=$matches[1]', '^wp-json/(.*)' => 'index.php?rest_route=/$matches[1]' ) );
check( 'index.php?product=$matches[1]&cptai_lang=en' === $rules['^en/shop/([^/]+)/?$'], 'native rewrite captures are unchanged' );
check( ! isset( $rules['^en/wp-json/(.*)'] ), 'REST routes not prefixed' );
check( isset( $rules['^es/?$'] ), 'source-language root rule exists' );
$GLOBALS['test_home'] = 'https://example.org/blog';
check( 'https://example.org/blog/es/services/' === CPTAI_Routing::url( 'https://example.org/blog/en/services/', 'es' ), 'language folders work in WordPress subdirectories' );
check( 'https://example.org/blog/services/' === CPTAI_Routing::source_url( 'https://example.org/blog/es/services/' ), 'source path preserves WordPress subdirectory' );
check( 'es' === CPTAI_Routing::language( 'https://example.org/blog/ES/services/' ), 'language prefix matching is case insensitive' );
unset( $GLOBALS['test_home'] );
CPTAI_Settings::save( array( 'source' => 'es', 'targets' => array( 'en' ), 'forget_openai' => 1 ) );
check( '' === CPTAI_Settings::key( 'openai' ), 'forget saved key' );
echo 'PASS: ' . $checks . " regression checks, no external requests.\n";
