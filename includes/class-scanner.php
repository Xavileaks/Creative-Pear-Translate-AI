<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_Scanner {
    public static function read( string $url, string $source, string $target ) {
        if ( ! CPTAI_HTML::preserves_markup() && ! class_exists( 'DOMDocument' ) ) { return new WP_Error( 'dom', 'Activa la extensión PHP DOM en tu servidor.' ); }
        $visited = array();
        for ( $hop = 0; $hop <= 3; $hop++ ) {
            $url = CPTAI_Admin::validate_capture_url( $url );
            if ( is_wp_error( $url ) ) { return $url; }
            if ( isset( $visited[ $url ] ) ) { return new WP_Error( 'redirect', 'La URL tiene un bucle de redirección.' ); }
            $visited[ $url ] = true;
            // Anonymous GETs only. Validate every redirect before issuing it.
            $response = wp_safe_remote_get( $url, array( 'timeout' => 25, 'redirection' => 0, 'limit_response_size' => 2097153 ) );
            if ( is_wp_error( $response ) ) { return new WP_Error( 'fetch', 'No se pudo contactar con esta página pública.' ); }
            $code = wp_remote_retrieve_response_code( $response );
            if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
                $location = wp_remote_retrieve_header( $response, 'location' );
                if ( ! is_string( $location ) || ! $location || 3 === $hop ) { return new WP_Error( 'redirect', 'No se pudo resolver la redirección de esta página.' ); }
                $url = WP_Http::make_absolute_url( $location, $url );
                continue;
            }
            if ( 200 !== $code ) { return new WP_Error( 'fetch', 'Esta página devolvió HTTP ' . (int) $code . '.' ); }
            $html = wp_remote_retrieve_body( $response );
            if ( strlen( $html ) > 2097152 || stripos( $html, '<html' ) === false ) { return new WP_Error( 'html', 'La página no es HTML o supera los 2 MB.' ); }
            try { $document = new CPTAI_HTML( $html ); $strings = $document->strings(); }
            catch ( Throwable $e ) { return new WP_Error( 'html', 'No se pudieron leer las cadenas de esta página.' ); }
            if ( count( $strings ) > 2000 ) { return new WP_Error( 'size', 'La página tiene más de 2.000 cadenas. Usa la captura en navegador.' ); }
            $settings = CPTAI_Settings::get();
            if ( $source !== $settings['source'] || ! in_array( $target, $settings['targets'], true ) ) { return new WP_Error( 'changed', 'Los idiomas cambiaron. Inicia de nuevo la lectura; las cadenas guardadas se conservan.' ); }
            try { $added = CPTAI_Store::capture( $strings, $source, $target, $url, true, $document->fragments() ); }
            catch ( Throwable $e ) { return new WP_Error( 'db', 'No se pudieron guardar las cadenas. La lectura se ha pausado.' ); }
            return array( 'strings' => $strings, 'added' => $added, 'url' => $url );
        }
        return new WP_Error( 'redirect', 'No se pudo resolver la URL pública.' );
    }

    public static function prepare( string $target ) {
        $settings = CPTAI_Settings::get();
        if ( ! in_array( $target, $settings['targets'], true ) ) { return new WP_Error( 'target', 'Guarda primero el idioma de destino.' ); }
        $types = array_values( array_filter( array( 'page', 'post', 'product' ), 'post_type_exists' ) );
        $excluded = array_filter( array_map( 'intval', array( get_option( 'woocommerce_cart_page_id' ), get_option( 'woocommerce_checkout_page_id' ), get_option( 'woocommerce_myaccount_page_id' ) ) ) );
        $ids = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'has_password' => false, 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'post__not_in' => $excluded ) );
        $items = array(); $seen = array();
        foreach ( array_merge( array( 0 ), $ids ) as $post_id ) {
            if ( ! $post_id && 'page' === get_option( 'show_on_front' ) ) {
                $front = get_post( (int) get_option( 'page_on_front' ) );
                if ( $front && ( 'publish' !== $front->post_status || $front->post_password ) ) { continue; }
            }
            $url = $post_id ? get_permalink( $post_id ) : home_url( '/' );
            if ( ! $url ) { continue; }
            $url = CPTAI_Admin::validate_capture_url( $url );
            if ( is_wp_error( $url ) || isset( $seen[ $url ] ) ) { continue; }
            $seen[ $url ] = true;
            $items[] = array( 'id' => (int) $post_id, 'url' => $url );
        }
        $id = wp_generate_uuid4();
        $job = array( 'user' => get_current_user_id(), 'source' => $settings['source'], 'target' => $target, 'items' => $items, 'offset' => 0, 'added' => 0, 'read' => 0, 'skipped' => 0, 'failed' => 0, 'failures' => array() );
        set_transient( 'cptai_site_scan_' . $id, $job, HOUR_IN_SECONDS );
        return self::status( $id, $job );
    }

    private static function status( string $id, array $job ): array {
        return array( 'job' => $id, 'target' => $job['target'], 'done' => $job['offset'], 'total' => count( $job['items'] ), 'added' => $job['added'], 'read' => $job['read'], 'skipped' => $job['skipped'], 'failed' => $job['failed'], 'failures' => $job['failures'], 'complete' => $job['offset'] >= count( $job['items'] ) );
    }

    private static function lock( string $name ) {
        global $wpdb;
        $previous = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
        if ( $previous && (int) $previous < time() - 180 ) { self::unlock( $name, $previous ); }
        $lease = time() . ':' . wp_generate_uuid4();
        return add_option( $name, $lease, '', false ) ? $lease : false;
    }

    private static function unlock( string $name, string $lease ): void {
        global $wpdb;
        if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, $lease ) ) ) { wp_cache_delete( $name, 'options' ); }
    }

    public static function step( string $id, int $offset ) {
        if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) || $offset < 0 ) { return new WP_Error( 'job', 'La sesión de lectura no es válida.' ); }
        $job = get_transient( 'cptai_site_scan_' . $id );
        $settings = CPTAI_Settings::get();
        if ( ! $job || (int) $job['user'] !== get_current_user_id() ) { return new WP_Error( 'job', 'La sesión de lectura caducó o pertenece a otro administrador.' ); }
        if ( $job['source'] !== $settings['source'] || ! in_array( $job['target'], $settings['targets'], true ) ) { return new WP_Error( 'changed', 'Los idiomas cambiaron. Inicia de nuevo la lectura; lo guardado se conserva.' ); }
        $lock = 'cptai_site_scan_lock_' . $id;
        $lease = self::lock( $lock );
        if ( ! $lease ) { return new WP_Error( 'busy', 'Esta página ya se está leyendo. Espera antes de continuar.' ); }
        try {
            $job = get_transient( 'cptai_site_scan_' . $id );
            if ( $offset > $job['offset'] ) { return new WP_Error( 'offset', 'La posición de lectura no es válida.' ); }
            // A lost AJAX response must not read the same URL or count it twice.
            if ( $offset < $job['offset'] || $job['offset'] >= count( $job['items'] ) ) { return self::status( $id, $job ); }
            $item = $job['items'][ $job['offset'] ];
            $post_id = $item['id'] ?: ( 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0 );
            $post = $post_id ? get_post( $post_id ) : null;
            $url = $item['id'] && $post ? get_permalink( $post ) : $item['url'];
            if ( $post_id && ( ! $post || 'publish' !== $post->post_status || $post->post_password || ! in_array( $post->post_type, array( 'page', 'post', 'product' ), true ) ) ) {
                $job['skipped']++;
            } else {
                $read = self::read( $url, $job['source'], $job['target'] );
                if ( is_wp_error( $read ) ) {
                    if ( in_array( $read->get_error_code(), array( 'db', 'changed', 'dom' ), true ) ) { return $read; }
                    if ( 'private' === $read->get_error_code() ) { $job['skipped']++; }
                    else {
                        $job['failed']++;
                        if ( count( $job['failures'] ) < 20 ) { $job['failures'][] = array( 'url' => $url, 'message' => $read->get_error_message() ); }
                    }
                } else {
                    $job['read']++; $job['added'] += $read['added'];
                }
            }
            global $wpdb;
            if ( $lease !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $lock ) ) ) { return new WP_Error( 'busy', 'Esta lectura cambió de sesión. Vuelve a consultar el progreso.' ); }
            $job['offset']++;
            set_transient( 'cptai_site_scan_' . $id, $job, HOUR_IN_SECONDS );
            return self::status( $id, $job );
        } catch ( Throwable $e ) {
            return new WP_Error( 'scan', 'No se pudo completar la lectura de esta página. Puedes continuar; las cadenas guardadas se conservan.' );
        } finally { self::unlock( $lock, $lease ); }
    }
}
