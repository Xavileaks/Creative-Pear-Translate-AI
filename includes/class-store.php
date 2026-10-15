<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_Store {
    public static function sort_options(): array {
        // Only these fixed expressions may enter ORDER BY; request values are keys.
        $options = array(
            'newest' => array( 'label' => 'Últimas detectadas primero', 'sql' => 'id DESC' ),
            'oldest' => array( 'label' => 'Primeras detectadas primero', 'sql' => 'id ASC' ),
            'az' => array( 'label' => 'Original: A → Z', 'sql' => 'original ASC, id ASC' ),
            'za' => array( 'label' => 'Original: Z → A', 'sql' => 'original DESC, id ASC' ),
            'empty_first' => array( 'label' => 'Vacías primero', 'sql' => "CASE WHEN translated='' THEN 0 ELSE 1 END ASC, original ASC, id ASC" ),
            'filled_first' => array( 'label' => 'Traducidas primero', 'sql' => "CASE WHEN translated='' THEN 1 ELSE 0 END ASC, original ASC, id ASC" ),
            'longest' => array( 'label' => 'Original: más caracteres primero', 'sql' => 'CHAR_LENGTH(original) DESC, original ASC, id ASC' ),
            'shortest' => array( 'label' => 'Original: menos caracteres primero', 'sql' => 'CHAR_LENGTH(original) ASC, original ASC, id ASC' ),
            'translation_longest' => array( 'label' => 'Traducción: más caracteres primero', 'sql' => 'CHAR_LENGTH(translated) DESC, original ASC, id ASC' ),
            'translation_shortest' => array( 'label' => 'Traducción: menos caracteres primero', 'sql' => 'CHAR_LENGTH(translated) ASC, original ASC, id ASC' ),
            'updated_newest' => array( 'label' => 'Últimas actualizadas primero', 'sql' => 'updated_at DESC, id DESC' ),
            'updated_oldest' => array( 'label' => 'Menos recientes en actualizarse', 'sql' => 'updated_at ASC, id ASC' ),
        );
        foreach ( $options as &$option ) {
            $option['sql'] = preg_replace( '/\boriginal\b/', self::display_sql( 'original' ), $option['sql'] );
            $option['sql'] = str_replace( 'CHAR_LENGTH(translated)', 'CHAR_LENGTH(' . self::display_sql( 'translated' ) . ')', $option['sql'] );
        }
        return $options;
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'cptai_strings';
    }

    public static function locations(): string {
        global $wpdb;
        return $wpdb->prefix . 'cptai_locations';
    }

    public static function visible_sql(): string {
        $table = self::table(); $locations = self::locations();
        // 2 pins an explicitly added manual message, even if it also occurs in a group.
        return "(legacy_fragment<>1 OR EXISTS (SELECT 1 FROM $locations l WHERE l.source_lang=$table.source_lang AND l.target_lang=$table.target_lang AND l.source_hash=$table.source_hash))";
    }

    public static function display_sql( string $column ): string {
        return "COALESCE(NULLIF({$column}_display,''),$column)";
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $locations = self::locations();
        $migrate = '1' !== get_option( 'cptai_plain_editor_schema' );
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_lang varchar(12) NOT NULL,
            target_lang varchar(12) NOT NULL,
            source_hash char(64) NOT NULL,
            original longtext NOT NULL,
            translated longtext NOT NULL,
            original_display longtext NULL,
            translated_display longtext NULL,
            legacy_fragment tinyint(1) NOT NULL DEFAULT 0,
            source_url text NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY language_string (source_lang,target_lang,source_hash)
        ) $charset;" );
        dbDelta( "CREATE TABLE $locations (
            source_lang varchar(12) NOT NULL,
            target_lang varchar(12) NOT NULL,
            source_hash char(64) NOT NULL,
            url_hash char(64) NOT NULL,
            PRIMARY KEY  (source_lang,target_lang,source_hash,url_hash),
            KEY page (url_hash)
        ) $charset;" );
        if ( $migrate ) {
            // Preserve references until each page is reread. Never delete a translation.
            $wpdb->query( "UPDATE $table SET original_display=original,translated_display=translated" );
            $last = 0;
            do {
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,source_lang,target_lang,source_hash,original,translated,source_url FROM $table WHERE id>%d ORDER BY id LIMIT 250", $last ) );
                $values = array();
                foreach ( $rows as $row ) {
                    $last = (int) $row->id;
                    if ( CPTAI_Breaks::markers( $row->original ) ) { $wpdb->update( $table, array( 'original_display' => self::search_text( $row->original ), 'translated_display' => self::search_text( $row->translated ) ), array( 'id' => $last ) ); }
                    if ( $row->source_url ) { $values[] = $wpdb->prepare( '(%s,%s,%s,%s)', $row->source_lang, $row->target_lang, $row->source_hash, self::url_hash( $row->source_url ) ); }
                }
                if ( $values ) { $wpdb->query( "INSERT IGNORE INTO $locations (source_lang,target_lang,source_hash,url_hash) VALUES " . implode( ',', $values ) ); }
            } while ( count( $rows ) === 250 );
            update_option( 'cptai_plain_editor_schema', '1', false );
        }
        CPTAI_Images::install();
        update_option( 'cptai_db_version', CPTAI_VERSION, false );
    }

    public static function find( array $strings, string $source, string $target ): array {
        global $wpdb;
        $found = array();
        foreach ( array_chunk( array_unique( $strings ), 100 ) as $chunk ) {
            $hashes = array_map( static fn( $s ) => hash( 'sha256', $s ), $chunk );
            $placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
            $sql = $wpdb->prepare( 'SELECT original, translated FROM ' . self::table() . " WHERE source_lang=%s AND target_lang=%s AND translated<>'' AND source_hash IN ($placeholders)", array_merge( array( $source, $target ), $hashes ) );
            foreach ( $wpdb->get_results( $sql ) as $row ) {
                $found[ $row->original ] = $row->translated;
            }
        }
        return $found;
    }

    public static function search_text( string $text ): string {
        return preg_replace( '/\s+/u', ' ', CPTAI_Breaks::display( $text ) );
    }

    private static function url_hash( string $url ): string {
        return hash( 'sha256', rtrim( $url, '/' ) );
    }

    private static function locate( string $hash, string $source, string $target, string $url, bool $dynamic = false ): void {
        global $wpdb;
        // A later HTML scan cannot disprove a message seen after a browser interaction.
        $url_hash = $dynamic ? hash( 'sha256', 'dynamic:' . self::url_hash( $url ) ) : self::url_hash( $url );
        if ( false === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::locations() . ' (source_lang,target_lang,source_hash,url_hash) VALUES (%s,%s,%s,%s)', $source, $target, $hash, $url_hash ) ) ) { throw new RuntimeException( 'No se pudo guardar la referencia.' ); }
    }

    public static function capture( array $strings, string $source, string $target, string $url, bool $complete = false, array $fragments = array() ): int {
        global $wpdb;
        $count = 0;
        foreach ( array_unique( $strings ) as $original ) {
            $sql = $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (source_lang,target_lang,source_hash,original,translated,original_display,translated_display,source_url,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)', $source, $target, hash( 'sha256', $original ), $original, '', self::search_text( $original ), '', $url, current_time( 'mysql', true ) );
            $result = $wpdb->query( $sql );
            if ( false === $result ) {
                throw new RuntimeException( 'No se pudieron guardar las cadenas.' );
            }
            $count += (int) $result;
            $fragments = array_merge( $fragments, CPTAI_Breaks::fragments( $original ) );
        }
        if ( $complete && false === $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::locations() . ' WHERE source_lang=%s AND target_lang=%s AND url_hash=%s', $source, $target, self::url_hash( $url ) ) ) ) { throw new RuntimeException( 'No se pudieron actualizar las referencias.' ); }
        foreach ( array_unique( $strings ) as $original ) { self::locate( hash( 'sha256', $original ), $source, $target, $url, ! $complete ); }
        foreach ( array_unique( $fragments ) as $fragment ) {
            if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET legacy_fragment=1 WHERE source_lang=%s AND target_lang=%s AND source_hash=%s AND legacy_fragment<>2', $source, $target, hash( 'sha256', $fragment ) ) ) ) { throw new RuntimeException( 'No se pudieron agrupar las cadenas antiguas.' ); }
        }
        return $count;
    }

    public static function dictionary( string $source, string $target ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT original, translated FROM ' . self::table() . " WHERE source_lang=%s AND target_lang=%s AND translated<>''", $source, $target ) );
        $dictionary = array();
        foreach ( $rows as $row ) {
            $dictionary[ $row->original ] = $row->translated;
        }
        return $dictionary;
    }

    public static function put( string $original, string $translated, string $source, string $target, string $url ): bool {
        global $wpdb;
        // Upsert retains IDs, so a correction form never points at a replacement row.
        $sql = $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (source_lang,target_lang,source_hash,original,translated,original_display,translated_display,source_url,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE translated=VALUES(translated),translated_display=VALUES(translated_display),source_url=VALUES(source_url),updated_at=VALUES(updated_at)', $source, $target, hash( 'sha256', $original ), $original, $translated, self::search_text( $original ), self::search_text( $translated ), $url, current_time( 'mysql', true ) );
        return false !== $wpdb->query( $sql );
    }

    public static function fill( string $original, string $translated, string $source, string $target ): bool {
        global $wpdb;
        // Atomic conditional update: a manual edit always wins, even during an API request.
        return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET translated=%s,translated_display=%s,updated_at=%s WHERE source_lang=%s AND target_lang=%s AND source_hash=%s AND translated=''", $translated, self::search_text( $translated ), current_time( 'mysql', true ), $source, $target, hash( 'sha256', $original ) ) );
    }
}
