<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_Calendar {
    private static ?array $data = null;
    private static array $maps = array();

    public static function data(): array {
        return self::$data ??= json_decode( file_get_contents( __DIR__ . '/calendar-data.json' ), true, 512, JSON_THROW_ON_ERROR );
    }

    public static function mapping( string $source, string $target ): array {
        $key = $source . ':' . $target;
        if ( isset( self::$maps[ $key ] ) ) { return self::$maps[ $key ]; }
        $data = self::data();
        $map = array();
        if ( ! isset( $data[ $source ], $data[ $target ] ) || $source === $target ) { return $map; }
        foreach ( array( 'months', 'days' ) as $group ) {
            foreach ( $data[ $source ][ $group ] as $i => $aliases ) {
                foreach ( $aliases as $j => $alias ) {
                    $lower = mb_strtolower( $alias, 'UTF-8' );
                    if ( isset( $map[ $lower ] ) ) { continue; }
                    $short = ( 'months' === $group ? 2 : 1 ) === $j;
                    $destination = $data[ $target ][ $group ][ $i ];
                    $map[ $lower ] = array( 'plain' => $destination[ $short ? count( $destination ) - 1 : 0 ], 'dated' => $destination[ $short ? count( $destination ) - 1 : ( 'months' === $group ? 1 : 0 ) ], 'alias' => $alias );
                }
            }
        }
        return self::$maps[ $key ] = $map;
    }

    private static function pattern( array $map, string $source ): string {
        $keys = array_keys( $map );
        usort( $keys, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
        $words = implode( '|', array_map( static fn( $key ) => preg_quote( $key, '~' ), $keys ) );
        return in_array( $source, array( 'ja', 'zh' ), true ) ? '~(?:' . $words . ')~iu' : '~(?<![\p{L}\p{M}])(?:' . $words . ')(?![\p{L}\p{M}])~iu';
    }

    private static function casing( string $word, string $replacement ): string {
        if ( mb_strtoupper( $word, 'UTF-8' ) === mb_strtolower( $word, 'UTF-8' ) ) { return $replacement; }
        if ( mb_strtoupper( $word, 'UTF-8' ) === $word && mb_strtolower( $word, 'UTF-8' ) !== $word ) { return mb_strtoupper( $replacement, 'UTF-8' ); }
        if ( mb_strtolower( $word, 'UTF-8' ) === $word ) { return mb_strtolower( $replacement, 'UTF-8' ); }
        if ( preg_match( '/^\p{Lu}/u', $word ) ) { return mb_strtoupper( mb_substr( $replacement, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_strtolower( mb_substr( $replacement, 1, null, 'UTF-8' ), 'UTF-8' ); }
        return $replacement;
    }

    private static function numbers( string $text, string $source, string $target ): array {
        foreach ( array( $source, $target ) as $language ) {
            $other = $language === $source ? $target : $source;
            $map = self::mapping( $language, $other );
            if ( $map ) { $text = preg_replace( self::pattern( $map, $language ), '', $text ); }
        }
        preg_match_all( '/\p{N}+/u', $text, $matches );
        $numbers = array_map( static fn( $number ) => ltrim( $number, '0' ) ?: '0', $matches[0] );
        sort( $numbers, SORT_STRING );
        return $numbers;
    }

    private static function date_context( string $text, string $pattern ): ?bool {
        $rest = preg_replace( $pattern, '', $text, -1, $count );
        // Restrict automatic replacement to calendar words and date-like strings.
        // "May I help?" and "March forward" are ordinary prose, not dates.
        $rest = preg_replace( '~(?<![\p{L}\p{M}])(?:de|del|of|on|at|a|las|às|um|den|le|el|am|pm|d)(?![\p{L}\p{M}])|[年月日号時分]~iu', '', $rest );
        if ( ! $count || ! preg_match( '/^[\p{N}\p{P}\p{Z}\s]*$/u', $rest ) ) { return null; }
        return (bool) preg_match( '/\p{N}/u', $rest );
    }

    public static function preserves_numbers( string $original, string $translated, string $source, string $target ): bool {
        $map = self::mapping( $source, $target );
        return ! $map || null === self::date_context( $original, self::pattern( $map, $source ) )
            || self::numbers( $original, $source, $target ) === self::numbers( $translated, $source, $target );
    }

    public static function translate( string $text, string $source, string $target, array $dictionary = array() ): string {
        $manual = $dictionary[ $text ] ?? null;
        $map = self::mapping( $source, $target );
        if ( ! $map ) { return $manual ?? $text; }
        $pattern = self::pattern( $map, $source );
        $dated = self::date_context( $text, $pattern );
        if ( null === $dated ) { return $manual ?? $text; }
        if ( null !== $manual && self::numbers( $text, $source, $target ) === self::numbers( $manual, $source, $target ) ) { return $manual; }
        return preg_replace_callback( $pattern, static function ( $match ) use ( $map, $dated, $dictionary, $source, $target ) {
            $item = $map[ mb_strtolower( $match[0], 'UTF-8' ) ];
            $replacement = $dictionary[ $match[0] ] ?? $dictionary[ $item['alias'] ] ?? $dictionary[ mb_strtolower( $item['alias'], 'UTF-8' ) ] ?? $item[ $dated ? 'dated' : 'plain' ];
            if ( self::numbers( $match[0], $source, $target ) !== self::numbers( $replacement, $source, $target ) ) { $replacement = $item[ $dated ? 'dated' : 'plain' ]; }
            // A word-level correction cannot remove the rest of the date.
            return self::casing( $match[0], $replacement );
        }, $text );
    }
}
