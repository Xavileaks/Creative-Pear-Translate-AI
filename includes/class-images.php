<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_Images {
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'cptai_images'; }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table(); $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_lang varchar(12) NOT NULL,
            target_lang varchar(12) NOT NULL,
            source_hash char(64) NOT NULL,
            original text NOT NULL,
            replacement text NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY language_image (source_lang,target_lang,source_hash)
        ) $charset;" );
    }

    public static function valid_url( string $url ): bool {
        $parts = wp_parse_url( $url );
        return strlen( $url ) <= 2048 && ! preg_match( '/[\x00-\x20\x7f<>"\x27\\\\]/', $url )
            && is_array( $parts ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
            && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['fragment'] );
    }

    public static function rows( string $source, string $target ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT original,replacement FROM ' . self::table() . ' WHERE source_lang=%s AND target_lang=%s ORDER BY id DESC', $source, $target ), ARRAY_A );
    }

    public static function save( string $original, string $replacement, string $target, ?string $expected = null ) {
        global $wpdb;
        $settings = CPTAI_Settings::get();
        if ( ! in_array( $target, $settings['targets'], true ) ) { return new WP_Error( 'target', 'Selecciona un idioma de destino activo.' ); }
        if ( ! self::valid_url( $original ) || ( $replacement && ! self::valid_url( $replacement ) ) ) { return new WP_Error( 'image_url', 'Introduce URLs completas de imagen, con http o https y sin credenciales.' ); }
        if ( $replacement ) {
            $attachment = attachment_url_to_postid( $replacement );
            if ( ! $attachment || ! wp_attachment_is_image( $attachment ) ) { return new WP_Error( 'image_media', 'La imagen nueva debe ser una imagen de la biblioteca de medios de este WordPress. Copia su URL de archivo.' ); }
            if ( $original === $replacement ) { return new WP_Error( 'image_same', 'Las URLs original y nueva deben ser diferentes.' ); }
            $map = self::map( $settings['source'], $target );
            if ( isset( $map[ $replacement ] ) || in_array( $original, $map, true ) ) { return new WP_Error( 'image_chain', 'Usa una imagen nueva que no sea el original de otra sustitución. Así se evitan cambios encadenados.' ); }
        }
        $where = array( $settings['source'], $target, hash( 'sha256', $original ) );
        $table = self::table();
        if ( null === $expected ) {
            if ( ! $replacement ) { return new WP_Error( 'image_url', 'Introduce la URL de la imagen nueva.' ); }
            if ( count( self::rows( $settings['source'], $target ) ) >= 500 ) { return new WP_Error( 'image_limit', 'Se permiten hasta 500 sustituciones de imagen por idioma.' ); }
            $result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (source_lang,target_lang,source_hash,original,replacement) VALUES (%s,%s,%s,%s,%s)", array_merge( $where, array( $original, $replacement ) ) ) );
        } else {
            // Concurrent edits must not overwrite a replacement silently.
            $result = $wpdb->query( $wpdb->prepare( "UPDATE $table SET replacement=%s WHERE source_lang=%s AND target_lang=%s AND source_hash=%s AND replacement=%s", array_merge( array( $replacement ), $where, array( $expected ) ) ) );
            if ( 0 === $result && $replacement === $expected ) { return true; }
        }
        if ( false === $result ) { return new WP_Error( 'db', 'No se pudo guardar la sustitución.' ); }
        return $result ? true : new WP_Error( 'image_conflict', 'Esta imagen ya existe o cambió en otra sesión. Actualiza la lista y usa Editar.' );
    }

    public static function map( string $source, string $target ): array {
        $map = array(); $explicit = array();
        foreach ( self::rows( $source, $target ) as $row ) {
            if ( ! $row['replacement'] ) { continue; }
            $explicit[ $row['original'] ] = $row['replacement'];
            $id = attachment_url_to_postid( $row['original'] );
            if ( ! $id ) { continue; }
            $meta = wp_get_attachment_metadata( $id );
            $base = substr( $row['original'], 0, strrpos( $row['original'], '/' ) + 1 );
            foreach ( $meta['sizes'] ?? array() as $size ) { $map[ $base . $size['file'] ] = $row['replacement']; }
            if ( ! empty( $meta['original_image'] ) ) { $map[ $base . $meta['original_image'] ] = $row['replacement']; }
        }
        // An explicitly configured size takes precedence over generated aliases.
        return array_replace( $map, $explicit );
    }

    public static function css( string $css, array $map ): string {
        return preg_replace_callback( '~url\(\s*(["\x27]?)([^)"\x27]+)\1\s*\)~i', static function ( $m ) use ( $map ) {
            $raw = trim( $m[2] ); $absolute = WP_Http::make_absolute_url( $raw, CPTAI_Frontend::current_url() );
            return isset( $map[ $absolute ] ) ? 'url("' . $map[ $absolute ] . '")' : $m[0];
        }, $css );
    }

    public static function render( string $html, array $map ): string {
        if ( ! $map || ! class_exists( 'WP_HTML_Tag_Processor' ) ) { return $html; }
        $p = new WP_HTML_Tag_Processor( $html );
        while ( $p->next_tag() ) {
            $tag = $p->get_tag();
            if ( in_array( $tag, array( 'IMG', 'SOURCE', 'INPUT' ), true ) && ( 'INPUT' !== $tag || 'image' === $p->get_attribute( 'type' ) ) ) {
                $found = null;
                foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original', 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $attr ) {
                    $raw = $p->get_attribute( $attr );
                    if ( ! is_string( $raw ) ) { continue; }
                    foreach ( explode( ',', $raw ) as $candidate ) {
                        $url = preg_split( '/\s+/', trim( $candidate ) )[0];
                        $absolute = WP_Http::make_absolute_url( $url, CPTAI_Frontend::current_url() );
                        if ( isset( $map[ $absolute ] ) ) { $found = $map[ $absolute ]; break 2; }
                    }
                }
                if ( $found ) {
                    // One candidate avoids selecting an old image or false width descriptors.
                    foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original', 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $attr ) {
                        if ( is_string( $p->get_attribute( $attr ) ) ) { $p->set_attribute( $attr, $found ); }
                    }
                }
            }
            foreach ( array( 'style', 'data-bg', 'data-background', 'data-background-image' ) as $attr ) {
                $raw = $p->get_attribute( $attr );
                if ( ! is_string( $raw ) ) { continue; }
                $value = 'style' === $attr ? self::css( $raw, $map ) : ( $map[ WP_Http::make_absolute_url( $raw, CPTAI_Frontend::current_url() ) ] ?? $raw );
                if ( $raw !== $value ) { $p->set_attribute( $attr, $value ); }
            }
        }
        return $p->get_updated_html();
    }
}
