<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_Store {
    public static function sort_options(): array {
        // Only these fixed expressions may enter ORDER BY; request values are keys.
        return array(
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
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'cptai_strings';
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_lang varchar(12) NOT NULL,
            target_lang varchar(12) NOT NULL,
            source_hash char(64) NOT NULL,
            original longtext NOT NULL,
            translated longtext NOT NULL,
            source_url text NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY language_string (source_lang,target_lang,source_hash)
        ) $charset;" );
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

    public static function capture( array $strings, string $source, string $target, string $url ): int {
        global $wpdb;
        $count = 0;
        foreach ( array_unique( $strings ) as $original ) {
            $sql = $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (source_lang,target_lang,source_hash,original,translated,source_url,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s)', $source, $target, hash( 'sha256', $original ), $original, '', $url, current_time( 'mysql', true ) );
            $result = $wpdb->query( $sql );
            if ( false === $result ) {
                throw new RuntimeException( 'No se pudieron guardar las cadenas.' );
            }
            $count += (int) $result;
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
        $sql = $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (source_lang,target_lang,source_hash,original,translated,source_url,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE translated=VALUES(translated),source_url=VALUES(source_url),updated_at=VALUES(updated_at)', $source, $target, hash( 'sha256', $original ), $original, $translated, $url, current_time( 'mysql', true ) );
        return false !== $wpdb->query( $sql );
    }

    public static function fill( string $original, string $translated, string $source, string $target ): bool {
        global $wpdb;
        // Atomic conditional update: a manual edit always wins, even during an API request.
        return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET translated=%s,updated_at=%s WHERE source_lang=%s AND target_lang=%s AND source_hash=%s AND translated=''", $translated, current_time( 'mysql', true ), $source, $target, hash( 'sha256', $original ) ) );
    }
}
