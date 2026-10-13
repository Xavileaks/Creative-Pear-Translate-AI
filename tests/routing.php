<?php
// Resolve native WordPress queries through the language folders in an isolated local install.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) {
    fwrite( STDERR, "Usage: php tests/routing.php /path/to/isolated/wordpress\n" ); exit( 1 );
}
define( 'WP_INSTALLING', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['PHP_SELF'] = '/index.php';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Refusing to run outside local WordPress.' ); }
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
CPTAI_Routing::boot();
CPTAI_Settings::save( array( 'source' => 'en', 'targets' => array( 'es', 'fr' ) ) );
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
$wp_rewrite->init();
get_taxonomy( 'category' )->add_rewrite_rules();
$users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $users[0]->ID );
$parent = wp_insert_post( array( 'post_title' => 'CPTAI routing parent', 'post_name' => 'cptai-routing-parent', 'post_type' => 'page', 'post_status' => 'publish' ) );
$child = wp_insert_post( array( 'post_title' => 'CPTAI routing child', 'post_name' => 'child', 'post_type' => 'page', 'post_parent' => $parent, 'post_status' => 'publish' ) );
$post = wp_insert_post( array( 'post_title' => 'CPTAI routing post', 'post_name' => 'cptai-routing-post', 'post_type' => 'post', 'post_status' => 'publish' ) );
$term = wp_insert_term( 'CPTAI routing category', 'category', array( 'slug' => 'cptai-routing-category' ) );
$category = is_wp_error( $term ) ? get_term_by( 'slug', 'cptai-routing-category', 'category' ) : get_term( $term['term_id'], 'category' );
CPTAI_Routing::flush();
$checks = 0;
function cptai_route_assert( bool $ok, string $name ): void {
    $GLOBALS['checks']++; if ( ! $ok ) { throw new RuntimeException( $name ); }
}
function cptai_resolve( string $url ): array {
    $_GET = array(); $_SERVER['REQUEST_URI'] = wp_parse_url( $url, PHP_URL_PATH );
    $wp = new WP(); $wp->parse_request();
    return $wp->query_vars;
}
foreach ( array( 'es', 'fr', 'en' ) as $language ) {
    $root = cptai_resolve( home_url( '/' . $language . '/' ) );
    cptai_route_assert( ( $root['cptai_lang'] ?? '' ) === $language && ! isset( $root['error'] ), 'language root resolves: ' . $language );
    if ( 'page' === get_option( 'show_on_front' ) ) {
        $root_query = new WP_Query( $root );
        cptai_route_assert( (int) get_option( 'page_on_front' ) === $root_query->get_queried_object_id(), 'static home page resolves: ' . $language );
    }
    $nested = cptai_resolve( CPTAI_Routing::url( get_permalink( $child ), $language ) );
    $query = new WP_Query( $nested );
    cptai_route_assert( $child === $query->get_queried_object_id(), 'nested page resolves: ' . $language );
    $post_vars = cptai_resolve( CPTAI_Routing::url( get_permalink( $post ), $language ) );
    $query = new WP_Query( $post_vars );
    cptai_route_assert( $post === $query->get_queried_object_id(), 'post resolves: ' . $language );
    $category_url = CPTAI_Routing::url( get_term_link( $category ), $language );
    $category_vars = cptai_resolve( $category_url );
    cptai_route_assert( ( $category_vars['category_name'] ?? '' ) === $category->slug, 'category resolves: ' . $language . ' ' . $category_url . ' ' . wp_json_encode( $category_vars ) );
}
$source = cptai_resolve( get_permalink( $child ) );
cptai_route_assert( ! isset( $source['cptai_lang'] ), 'unprefixed original routing preserved' );
// Reproduce an update/cache rebuild that leaves native rules but loses the plugin
// prefixes, even though the old routing migration is already marked complete.
$stored_rules = get_option( 'rewrite_rules' );
$native_rules = array_filter( $stored_rules, static fn( $query ) => ! str_contains( $query, 'cptai_lang=' ) );
update_option( 'rewrite_rules', $native_rules );
update_option( 'cptai_routing_version', '1' );
foreach ( array( 'es', 'en', 'fr' ) as $language ) {
    $root = cptai_resolve( home_url( '/' . $language . '/' ) );
    $query = new WP_Query( $root );
    cptai_route_assert( ! isset( $root['error'] ) && ( $root['cptai_lang'] ?? '' ) === $language, 'missing stored root rule recovers: ' . $language );
    cptai_route_assert( ! $query->is_404() && (int) get_option( 'page_on_front' ) === $query->get_queried_object_id(), 'missing stored rule loads actual homepage: ' . $language );
    $nested = cptai_resolve( CPTAI_Routing::url( get_permalink( $child ), $language ) );
    $query = new WP_Query( $nested );
    cptai_route_assert( $child === $query->get_queried_object_id(), 'missing stored nested rule recovers: ' . $language );
    $post_vars = cptai_resolve( CPTAI_Routing::url( get_permalink( $post ), $language ) );
    $query = new WP_Query( $post_vars );
    cptai_route_assert( $post === $query->get_queried_object_id(), 'missing stored post rule recovers: ' . $language );
}
$_SERVER['REQUEST_URI'] = '/';
cptai_route_assert( get_option( 'rewrite_rules' ) === $native_rules, 'runtime recovery does not write rules on every visit' );
update_option( 'rewrite_rules', $stored_rules );
$_SERVER['REQUEST_URI'] = '/es/cptai-routing-parent/child/';
cptai_route_assert( str_contains( CPTAI_Routing::canonical( get_permalink( $child ) ), '/es/' ), 'canonical redirect keeps prefix' );
$removed = CPTAI_Settings::get();
$removed['targets'] = array( 'es' );
CPTAI_Settings::save( $removed );
$rules = get_option( 'rewrite_rules' );
cptai_route_assert( ! isset( $rules['^fr/?$'] ), 'saving languages flushes removed routes' );
$removed['targets'] = array( 'es', 'fr' );
CPTAI_Settings::save( $removed );
$front_mode = get_option( 'show_on_front' );
update_option( 'show_on_front', 'posts' );
$native_rules = array_filter( get_option( 'rewrite_rules' ), static fn( $query ) => ! str_contains( $query, 'cptai_lang=' ) );
update_option( 'rewrite_rules', $native_rules );
foreach ( array( 'es', 'en' ) as $language ) {
    $root = cptai_resolve( home_url( '/' . $language . '/' ) );
    $query = new WP_Query( $root );
    cptai_route_assert( $query->is_home() && ! $query->is_404() && ( $root['cptai_lang'] ?? '' ) === $language, 'posts homepage recovers missing rules: ' . $language );
}
update_option( 'show_on_front', $front_mode );
CPTAI_Routing::deactivate();
$_SERVER['REQUEST_URI'] = '/es/';
cptai_route_assert( ! isset( get_option( 'rewrite_rules' )['^es/?$'] ), 'deactivation removes stored and runtime routes' );
CPTAI_Routing::activate();
$_SERVER['REQUEST_URI'] = '/';
cptai_route_assert( isset( get_option( 'rewrite_rules' )['^es/?$'] ), 'activation registers and stores language routes' );
cptai_route_assert( '2' === get_option( 'cptai_routing_version' ), 'activation marks current migration complete' );
// Delete only the disposable fixtures created by this test.
wp_delete_post( $child, true ); wp_delete_post( $parent, true ); wp_delete_post( $post, true );
echo 'PASS: ' . $checks . " native routing checks.\n";
