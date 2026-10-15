<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_HTML {
    private DOMDocument $document;
    private array $segments = array();
    private array $raw = array();
    private string $original;
    private array $break_groups = array();
    private array $break_nodes = array();

    public static function preserves_markup(): bool {
        return method_exists( 'WP_HTML_Tag_Processor', 'set_modifiable_text' );
    }

    public function __construct( string $html ) {
        $this->original = $html;
        if ( self::preserves_markup() ) {
            $this->collect_breaks();
            $this->process();
            return;
        }
        // libxml can entity-encode non-ASCII text in script/style when serializing.
        // Keep executable contents byte-for-byte, including JSON and CSS strings.
        $prefix = 'CPTAI_RAW_' . bin2hex( random_bytes( 12 ) ) . '_';
        $html = preg_replace_callback( '~(<(script|style)\b(?:[^>\x22\x27]|\x22[^\x22]*\x22|\x27[^\x27]*\x27)*>)(.*?)(</\2\s*>)~is', function ( $match ) use ( $prefix ) {
            $token = $prefix . count( $this->raw );
            $this->raw[ $token ] = $match[3];
            return $match[1] . $token . $match[4];
        }, $html );
        $this->document = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        try {
            $this->document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );
        }
        foreach ( iterator_to_array( $this->document->childNodes ) as $child ) {
            if ( XML_PI_NODE === $child->nodeType ) {
                $this->document->removeChild( $child );
            }
        }
        $xpath = new DOMXPath( $this->document );
        $this->collect_breaks( $this->document );
        $captured_groups = array();
        foreach ( $xpath->query( '//text() | //@alt | //@title | //@placeholder | //@aria-label | //input[@type="submit" or @type="button" or @type="reset"]/@value | //meta[@name="description"]/@content | //meta[@property="og:title" or @property="og:description"]/@content' ) as $node ) {
            if ( ! $this->excluded( $node ) ) {
                $group_id = $this->break_nodes[ spl_object_id( $node ) ] ?? null;
                if ( null !== $group_id ) {
                    if ( ! isset( $captured_groups[ $group_id ] ) ) { $this->segments[] = array( null, $this->break_groups[ $group_id ]['original'] ); $captured_groups[ $group_id ] = true; }
                    continue;
                }
                $text = trim( $node->nodeValue );
                if ( $this->translatable( $text ) ) {
                    $this->segments[] = array( $node, $text );
                }
            }
        }
    }

    public static function translatable( string $text ): bool {
        return strlen( $text ) <= 3500 && ! preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text ) && (bool) preg_match( '/\p{L}/u', $text )
            && ! preg_match( '~^(https?://|www\.|[^\s@]+@[^\s@]+\.[^\s@]+$)~iu', $text );
    }

    private static function ignored_tags(): array {
        return array( 'SCRIPT', 'STYLE', 'NOSCRIPT', 'CODE', 'PRE', 'TEXTAREA', 'SVG', 'MATH', 'TEMPLATE', 'IFRAME', 'NOEMBED', 'NOFRAMES', 'XMP' );
    }

    private function collect_breaks( ?DOMDocument $document = null ): void {
        $run = array();
        $flush = function () use ( &$run ): void {
            $group = CPTAI_Breaks::build( $run ); $run = array();
            if ( ! $group || ! self::translatable( $group['original'] ) ) { return; }
            $id = count( $this->break_groups ); $this->break_groups[] = $group;
            foreach ( $group['slots'] as $slot ) { foreach ( $slot as $token ) { $this->break_nodes[ $token['id'] ] = $id; } }
        };
        if ( $document ) {
            $walk = function ( DOMNode $node ) use ( &$walk, &$run, $flush ): void {
                if ( $node instanceof DOMText ) { $run[] = array( 'kind' => 'text', 'id' => spl_object_id( $node ), 'raw' => $node->nodeValue, 'node' => $node ); return; }
                if ( $node instanceof DOMComment ) { return; }
                if ( $node instanceof DOMElement ) {
                    $tag = strtoupper( $node->tagName );
                    if ( in_array( $tag, self::ignored_tags(), true ) || 'no' === strtolower( $node->getAttribute( 'translate' ) ) || $node->hasAttribute( 'data-cptai-ignore' ) || 'wpadminbar' === $node->getAttribute( 'id' ) || preg_match( '/(?:^|\s)(notranslate|cptai-switcher)(?:\s|$)/', $node->getAttribute( 'class' ) ) ) { $flush(); return; }
                    if ( 'BR' === $tag ) { $run[] = array( 'kind' => 'br' ); return; }
                    $inline = in_array( $tag, CPTAI_Breaks::INLINE, true );
                    if ( $inline ) { $run[] = array( 'kind' => 'tag', 'tag' => $tag, 'close' => false ); } else { $flush(); }
                    foreach ( $node->childNodes as $child ) { $walk( $child ); }
                    if ( $inline ) { $run[] = array( 'kind' => 'tag', 'tag' => $tag, 'close' => true ); } else { $flush(); }
                    return;
                }
                $flush(); foreach ( $node->childNodes as $child ) { $walk( $child ); } $flush();
            };
            $walk( $document ); $flush(); return;
        }
        $p = new WP_HTML_Tag_Processor( $this->original ); $stack = array(); $index = 0;
        $void = array( 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR' );
        while ( $p->next_token() ) {
            $index++; $tag = $p->get_tag(); $excluded = $stack ? $stack[ count( $stack ) - 1 ][1] : false;
            if ( '#tag' === $p->get_token_type() ) {
                if ( $p->is_tag_closer() ) {
                    if ( ! $excluded && in_array( $tag, CPTAI_Breaks::INLINE, true ) ) { $run[] = array( 'kind' => 'tag', 'tag' => $tag, 'close' => true ); } else { $flush(); }
                    for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) { if ( $stack[ $i ][0] === $tag ) { $stack = array_slice( $stack, 0, $i ); break; } }
                    continue;
                }
                $excluded = $excluded || in_array( $tag, self::ignored_tags(), true ) || 'no' === strtolower( (string) $p->get_attribute( 'translate' ) ) || null !== $p->get_attribute( 'data-cptai-ignore' ) || 'wpadminbar' === $p->get_attribute( 'id' ) || preg_match( '/(?:^|\s)(notranslate|cptai-switcher)(?:\s|$)/', (string) $p->get_attribute( 'class' ) );
                if ( ! $excluded && 'BR' === $tag ) { $run[] = array( 'kind' => 'br' ); }
                elseif ( ! $excluded && in_array( $tag, CPTAI_Breaks::INLINE, true ) ) { $run[] = array( 'kind' => 'tag', 'tag' => $tag, 'close' => false ); }
                else { $flush(); }
                if ( ! in_array( $tag, $void, true ) && ! in_array( $tag, array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'IFRAME', 'NOEMBED', 'NOFRAMES', 'XMP' ), true ) && ! ( $p->has_self_closing_flag() && in_array( $tag, array( 'SVG', 'MATH' ), true ) ) ) { $stack[] = array( $tag, (bool) $excluded ); }
            } elseif ( '#text' === $p->get_token_type() && ! $excluded ) { $run[] = array( 'kind' => 'text', 'id' => $index, 'raw' => $p->get_modifiable_text() ); }
            elseif ( '#comment' !== $p->get_token_type() ) { $flush(); }
        }
        $flush();
    }

    private function excluded( DOMNode $node ): bool {
        $parent = $node instanceof DOMAttr ? $node->ownerElement : $node->parentNode;
        while ( $parent instanceof DOMElement ) {
            if ( in_array( strtolower( $parent->tagName ), array( 'script', 'style', 'noscript', 'code', 'pre', 'textarea', 'svg', 'math', 'template', 'iframe', 'xmp', 'noembed', 'noframes' ), true )
                || 'no' === strtolower( $parent->getAttribute( 'translate' ) )
                || $parent->hasAttribute( 'data-cptai-ignore' )
                || 'wpadminbar' === $parent->getAttribute( 'id' )
                || preg_match( '/(?:^|\s)(notranslate|cptai-switcher)(?:\s|$)/', $parent->getAttribute( 'class' ) ) ) {
                return true;
            }
            $parent = $parent->parentNode;
        }
        return false;
    }

    public function strings(): array {
        return array_values( array_unique( array_column( $this->segments, 1 ) ) );
    }

    public function render( array $dictionary, string $target ): string {
        // Never serialize a repaired DOM: libxml can move Elementor containers,
        // headings, inline SVG and HTML5 elements even when no text is changed.
        // Older WordPress versions keep the original bytes and use the DOM script.
        return self::preserves_markup() ? $this->process( $dictionary, $target ) : $this->original;
    }

    private function process( ?array $dictionary = null, string $target = '' ): string {
        $processor = new WP_HTML_Tag_Processor( $this->original );
        $stack = array();
        $render = null !== $dictionary;
        $source = CPTAI_Settings::get()['source'];
        $group_values = array(); $captured_groups = array(); $index = 0;
        foreach ( $this->break_groups as $group ) {
            $translated = $dictionary[ $group['original'] ] ?? '';
            if ( $render && $translated && CPTAI_Provider::valid( $group['original'], $translated ) ) { $group_values += CPTAI_Breaks::replacements( $group, $translated ); }
            elseif ( $render ) {
                foreach ( $group['slots'] as $slot ) { foreach ( $slot as $token ) { $group_values[ $token['id'] ] = CPTAI_Calendar::translate( $token['raw'], $source, $target ); } }
            }
        }
        $value = function ( string $raw ) use ( $dictionary, $target, $source, $render ): string {
            $text = trim( $raw );
            if ( ! self::translatable( $text ) ) { return $raw; }
            if ( ! $render ) { $this->segments[] = array( null, $text ); return $raw; }
            $translated = CPTAI_Calendar::translate( $text, $source, $target, $dictionary );
            return $translated === $text ? $raw : preg_replace_callback( '/^(\s*)(.*?)(\s*)$/su', static fn( $m ) => $m[1] . $translated . $m[3], $raw );
        };
        $atomic = array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'IFRAME', 'NOEMBED', 'NOFRAMES', 'XMP' );
        $void = array( 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR' );
        $excluded_tags = array( 'SCRIPT', 'STYLE', 'NOSCRIPT', 'CODE', 'PRE', 'TEXTAREA', 'SVG', 'MATH', 'TEMPLATE', 'IFRAME', 'NOEMBED', 'NOFRAMES', 'XMP' );
        while ( $processor->next_token() ) {
            $index++;
            $tag = $processor->get_tag();
            if ( '#tag' === $processor->get_token_type() && $processor->is_tag_closer() ) {
                for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
                    if ( $stack[ $i ][0] === $tag ) { $stack = array_slice( $stack, 0, $i ); break; }
                }
                continue;
            }
            $excluded = $stack ? $stack[ count( $stack ) - 1 ][1] : false;
            if ( '#tag' === $processor->get_token_type() ) {
                $excluded = $excluded || in_array( $tag, $excluded_tags, true )
                    || 'no' === strtolower( (string) $processor->get_attribute( 'translate' ) )
                    || null !== $processor->get_attribute( 'data-cptai-ignore' )
                    || 'wpadminbar' === $processor->get_attribute( 'id' )
                    || preg_match( '/(?:^|\s)(notranslate|cptai-switcher)(?:\s|$)/', (string) $processor->get_attribute( 'class' ) );
                if ( ! $excluded ) {
                    $attributes = array( 'alt', 'title', 'placeholder', 'aria-label' );
                    if ( 'INPUT' === $tag && in_array( strtolower( (string) $processor->get_attribute( 'type' ) ), array( 'submit', 'button', 'reset' ), true ) ) { $attributes[] = 'value'; }
                    if ( 'META' === $tag && ( 'description' === strtolower( (string) $processor->get_attribute( 'name' ) ) || in_array( strtolower( (string) $processor->get_attribute( 'property' ) ), array( 'og:title', 'og:description' ), true ) ) ) { $attributes[] = 'content'; }
                    foreach ( $attributes as $attribute ) {
                        $raw = $processor->get_attribute( $attribute );
                        if ( ! is_string( $raw ) ) { continue; }
                        $translated = $value( $raw );
                        if ( $render && $raw !== $translated ) { $processor->set_attribute( $attribute, $translated ); }
                    }
                    if ( $render ) {
                        if ( 'HTML' === $tag ) {
                            $processor->set_attribute( 'lang', $target );
                            $processor->set_attribute( 'dir', 'ar' === $target ? 'rtl' : 'ltr' );
                        }
                        if ( 'A' === $tag && is_string( $processor->get_attribute( 'href' ) ) ) {
                            $raw = $processor->get_attribute( 'href' );
                            $link = CPTAI_Frontend::link( $raw, $target );
                            if ( $link !== $raw ) { $processor->set_attribute( 'href', $link ); }
                        }
                        if ( 'META' === $tag && 'robots' === strtolower( (string) $processor->get_attribute( 'name' ) ) && $target !== $source ) { $processor->set_attribute( 'content', 'noindex,follow' ); }
                        $canonical = 'LINK' === $tag && 'canonical' === strtolower( (string) $processor->get_attribute( 'rel' ) );
                        $og_url = 'META' === $tag && 'og:url' === strtolower( (string) $processor->get_attribute( 'property' ) );
                        if ( $canonical || $og_url ) {
                            $attribute = $canonical ? 'href' : 'content';
                            $raw = $processor->get_attribute( $attribute );
                            if ( is_string( $raw ) ) { $processor->set_attribute( $attribute, CPTAI_Routing::url( $raw, $target ) ); }
                        }
                    }
                    // TITLE is an atomic RCDATA token; script/style remain byte-for-byte.
                    if ( 'TITLE' === $tag ) {
                        $raw = $processor->get_modifiable_text();
                        $translated = $value( $raw );
                        if ( $render && $raw !== $translated ) { $processor->set_modifiable_text( $translated ); }
                    }
                }
                if ( ! in_array( $tag, $void, true ) && ! in_array( $tag, $atomic, true )
                    && ! ( $processor->has_self_closing_flag() && in_array( $tag, array( 'SVG', 'MATH' ), true ) ) ) {
                    $stack[] = array( $tag, (bool) $excluded );
                }
            } elseif ( '#text' === $processor->get_token_type() && ! $excluded ) {
                $raw = $processor->get_modifiable_text();
                if ( isset( $this->break_nodes[ $index ] ) ) {
                    $group_id = $this->break_nodes[ $index ];
                    if ( ! $render && ! isset( $captured_groups[ $group_id ] ) ) { $this->segments[] = array( null, $this->break_groups[ $group_id ]['original'] ); $captured_groups[ $group_id ] = true; }
                    if ( $render && isset( $group_values[ $index ] ) && $group_values[ $index ] !== $raw ) { $processor->set_modifiable_text( $group_values[ $index ] ); }
                    continue;
                }
                $translated = $value( $raw );
                if ( $render && $raw !== $translated ) { $processor->set_modifiable_text( $translated ); }
            }
        }
        return $processor->get_updated_html();
    }
}
