<?php
defined( 'ABSPATH' ) || exit;

// These tokens describe existing markup. They never become HTML supplied by AI.
final class CPTAI_Breaks {
    public const INLINE = array( 'A', 'ABBR', 'B', 'BDI', 'BDO', 'CITE', 'DEL', 'EM', 'I', 'INS', 'MARK', 'Q', 'S', 'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP', 'U' );
    public const PATTERN = '/\[cptai_(?:br|tag)_\d+\]/';

    public static function markers( string $text ): array {
        preg_match_all( self::PATTERN, $text, $matches );
        return $matches[0];
    }

    public static function valid( string $original, string $translated ): bool {
        $markers = self::markers( $original );
        if ( $markers !== self::markers( $translated ) ) { return false; }
        if ( ! $markers ) { return true; }
        $from = preg_split( self::PATTERN, $original ); $to = preg_split( self::PATTERN, $translated );
        foreach ( $from as $i => $part ) {
            // There is no text node into which words can be inserted here.
            if ( '' === trim( $part ) && '' !== trim( $to[ $i ] ) ) { return false; }
        }
        return true;
    }

    public static function build( array $tokens ): ?array {
        if ( count( $tokens ) > 150 ) { return null; }
        $balance = array(); $brs = 0; $human = ''; $lines = array( '' );
        foreach ( $tokens as $token ) {
            if ( 'br' === $token['kind'] ) { $brs++; $human .= "\n"; $lines[] = ''; }
            elseif ( 'text' === $token['kind'] ) {
                if ( str_contains( $token['raw'], '[cptai_' ) ) { return null; }
                $human .= $token['raw'];
                $lines[ count( $lines ) - 1 ] .= $token['raw'];
            } elseif ( $token['close'] ) {
                if ( array_pop( $balance ) !== $token['tag'] ) { return null; }
            } else { $balance[] = $token['tag']; }
        }
        if ( ! $brs || $brs > 32 || $balance || count( array_filter( array_map( 'trim', $lines ), 'strlen' ) ) < 2 || ! preg_match( '/\p{L}/u', $human ) ) { return null; }
        // An outer span/emphasis is not a meaningful difference between strings.
        while ( count( $tokens ) >= 2 && 'tag' === $tokens[0]['kind'] && ! $tokens[0]['close'] && 'tag' === $tokens[ count( $tokens ) - 1 ]['kind'] ) {
            $depth = 0; $end = 0;
            foreach ( $tokens as $i => $token ) {
                if ( 'tag' !== $token['kind'] ) { continue; }
                $depth += $token['close'] ? -1 : 1;
                if ( 0 === $depth ) { $end = $i; break; }
            }
            if ( $end !== count( $tokens ) - 1 ) { break; }
            array_shift( $tokens ); array_pop( $tokens );
        }
        $original = ''; $slots = array( array() ); $markers = array(); $br = 0; $tag = 0;
        foreach ( $tokens as $token ) {
            if ( 'text' === $token['kind'] ) {
                $original .= $token['raw']; $slots[ count( $slots ) - 1 ][] = $token;
            } else {
                $marker = 'br' === $token['kind'] ? '[cptai_br_' . ++$br . ']' : '[cptai_tag_' . ++$tag . ']';
                $original .= 'br' === $token['kind'] ? ' ' . $marker . ' ' : $marker;
                $markers[] = $marker; $slots[] = array();
            }
        }
        $original = trim( preg_replace( '/[ \t\r\n\f]+/', ' ', $original ) );
        if ( strlen( $original ) > 3500 ) { return null; }
        return array( 'original' => $original, 'slots' => $slots, 'markers' => $markers );
    }

    public static function replacements( array $group, string $translated ): array {
        if ( ! self::valid( $group['original'], $translated ) ) { return array(); }
        $parts = preg_split( self::PATTERN, $translated ); $result = array();
        foreach ( $group['slots'] as $i => $slot ) {
            $text = $parts[ $i ];
            if ( 0 === $i || str_starts_with( $group['markers'][ $i - 1 ] ?? '', '[cptai_br_' ) ) { $text = ltrim( $text ); }
            if ( $i === count( $group['slots'] ) - 1 || str_starts_with( $group['markers'][ $i ] ?? '', '[cptai_br_' ) ) { $text = rtrim( $text ); }
            foreach ( $slot as $j => $token ) { $result[ $token['id'] ] = 0 === $j ? $text : ''; }
        }
        return $result;
    }
}
