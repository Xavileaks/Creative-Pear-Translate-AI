<?php
// Exercise actual database ordering, search, pagination and AJAX permissions.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-load.php' ) ) {
    fwrite( STDERR, "Usage: php tests/dictionary.php /path/to/isolated/wordpress\n" ); exit( 1 );
}
define( 'WP_INSTALLING', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8764';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
require $argv[1] . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Refusing to run outside local WordPress.' ); }
require_once WP_PLUGIN_DIR . '/creative-pear-translate-ai/creative-pear-translate-ai.php';
CPTAI_Store::install();
$users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $users[0]->ID );
CPTAI_Settings::save( array( 'source' => 'en', 'targets' => array( 'es', 'fr' ) ) );
class CPTAI_Dictionary_Response extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', static fn() => static function () { throw new CPTAI_Dictionary_Response(); } );
$checks = 0;
function dictionary_check( bool $pass, string $name ): void {
    $GLOBALS['checks']++;
    if ( ! $pass ) { throw new RuntimeException( $name ); }
}
function dictionary_request( array $input ): array {
    $_POST = wp_slash( array_merge( array( 'target' => 'es', 's' => 'cptai-sort-fixture-' ), $input ) );
    $_REQUEST = array_merge( $_POST, array( 'nonce' => wp_create_nonce( 'cptai_admin' ) ) );
    ob_start();
    try { CPTAI_Admin::dictionary(); } catch ( CPTAI_Dictionary_Response $e ) {}
    $body = ob_get_clean();
    $response = json_decode( $body, true );
    if ( ! is_array( $response ) ) { throw new RuntimeException( 'Malformed dictionary response.' ); }
    dictionary_check( $response['success'], 'dictionary AJAX succeeded' );
    return $response['data'];
}
function dictionary_ids( string $html ): array {
    preg_match_all( '/<article[^>]+aria-label="Cadena ([0-9]+)"/', $html, $matches );
    return array_map( 'intval', $matches[1] );
}
global $wpdb;
$table = CPTAI_Store::table();
$prefix = 'cptai-sort-fixture-';
$ids = array();
try {
    for ( $step = 0; $step < 36; $step++ ) {
        $i = ( $step * 7 ) % 36;
        $original = $prefix . sprintf( '%02d', $i ) . str_repeat( 'x', ( $i * 11 ) % 17 );
        $translated = $i % 2 ? 'Traducción ' . str_repeat( 'ñ', $i % 9 ) : '';
        CPTAI_Store::put( $original, $translated, 'en', 'es', home_url( '/' ) );
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE source_lang='en' AND target_lang='es' AND original=%s", $original ) );
        $ids[] = $id;
        $wpdb->update( $table, array( 'updated_at' => sprintf( '2026-01-%02d 12:00:00', 1 + $i % 27 ) ), array( 'id' => $id ) );
    }
    $fixtures = $wpdb->get_results( "SELECT * FROM $table WHERE id IN (" . implode( ',', $ids ) . ')' );
    foreach ( array_keys( CPTAI_Store::sort_options() ) as $sort ) {
        $expected = $fixtures;
        usort( $expected, static function ( $a, $b ) use ( $sort ) {
            $az = strcmp( $a->original, $b->original );
            $id = (int) $a->id <=> (int) $b->id;
            return match ( $sort ) {
                'newest' => -$id, 'oldest' => $id,
                'az' => $az ?: $id, 'za' => -$az ?: $id,
                'empty_first' => ( (int) ( '' !== $a->translated ) <=> (int) ( '' !== $b->translated ) ) ?: $az ?: $id,
                'filled_first' => ( (int) ( '' === $a->translated ) <=> (int) ( '' === $b->translated ) ) ?: $az ?: $id,
                'longest' => ( mb_strlen( $b->original ) <=> mb_strlen( $a->original ) ) ?: $az ?: $id,
                'shortest' => ( mb_strlen( $a->original ) <=> mb_strlen( $b->original ) ) ?: $az ?: $id,
                'translation_longest' => ( mb_strlen( $b->translated ) <=> mb_strlen( $a->translated ) ) ?: $az ?: $id,
                'translation_shortest' => ( mb_strlen( $a->translated ) <=> mb_strlen( $b->translated ) ) ?: $az ?: $id,
                'updated_newest' => strcmp( $b->updated_at, $a->updated_at ) ?: -$id,
                'updated_oldest' => strcmp( $a->updated_at, $b->updated_at ) ?: $id,
            };
        } );
        $first = dictionary_request( array( 'sort' => $sort ) );
        $second = dictionary_request( array( 'sort' => $sort, 'paged' => 2 ) );
        $actual = array_merge( dictionary_ids( $first['html'] ), dictionary_ids( $second['html'] ) );
        dictionary_check( $actual === array_map( static fn( $row ) => (int) $row->id, $expected ), 'global ordering and pagination: ' . $sort );
        dictionary_check( 36 === $first['total'] && 30 === count( dictionary_ids( $first['html'] ) ) && 6 === count( dictionary_ids( $second['html'] ) ), 'all results paginated: ' . $sort );
        dictionary_check( str_contains( $first['html'], 'sort=' . $sort ) && ! str_contains( $first['html'], 'admin-ajax.php?' ), 'pagination keeps sort and admin URL: ' . $sort );
    }
    $pending = dictionary_request( array( 'pending' => 1, 'sort' => 'az' ) );
    dictionary_check( 18 === $pending['total'], 'pending combines with ordering and search' );
    $translated = dictionary_request( array( 's' => 'Traducción ññññññññ', 'sort' => 'az' ) );
    dictionary_check( 2 === $translated['total'], 'search finds translations outside first page' );
    $zero = dictionary_request( array( 's' => $prefix . '00' ) );
    dictionary_check( 1 === $zero['total'], 'search selects a single original' );
    $none = dictionary_request( array( 's' => $prefix . 'missing <img src=x>' ) );
    dictionary_check( 0 === $none['total'] && str_contains( $none['html'], 'No hay cadenas' ) && ! str_contains( $none['html'], '<script>' ), 'empty results and HTML escaping' );
    $invalid = dictionary_request( array( 'sort' => 'id DESC; DROP TABLE strings', 'paged' => 100 ) );
    dictionary_check( 'newest' === $invalid['sort'] && 2 === $invalid['page'], 'unknown sort rejected and page clamped' );
    $array_input = dictionary_request( array( 'sort' => array( 'az' ), 's' => array( 'bad' ), 'target' => array( 'es' ), 'paged' => array( 2 ) ) );
    dictionary_check( 'newest' === $array_input['sort'] && '' === $array_input['search'], 'malformed scalar parameters handled safely' );
    wp_set_current_user( 0 );
    ob_start();
    try { CPTAI_Admin::dictionary(); } catch ( CPTAI_Dictionary_Response $e ) {}
    $denied = ob_get_clean();
    dictionary_check( ! str_contains( $denied, $prefix ), 'visitor cannot read dictionary via AJAX' );
} finally {
    if ( $ids ) { $wpdb->query( "DELETE FROM $table WHERE id IN (" . implode( ',', $ids ) . ')' ); }
}
echo 'PASS: ' . $checks . " dictionary checks.\n";
