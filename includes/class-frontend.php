<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_Frontend {
    private static array $gettext_strings = array();

    public static function boot(): void {
        add_action( 'template_redirect', array( self::class, 'start' ), 0 );
        add_action( 'wp_enqueue_scripts', array( self::class, 'scripts' ) );
        if ( self::scan_target() && CPTAI_Settings::get()['capture_gettext'] ) {
            add_filter( 'gettext', static function ( $translated ) {
                if ( CPTAI_HTML::translatable( $translated ) ) {
                    self::$gettext_strings[ $translated ] = true;
                }
                return $translated;
            }, 999 );
        }
        if ( self::target() && ! self::scan_target() && ! is_admin() && ! wp_doing_ajax() ) {
            $dictionary = CPTAI_Store::dictionary( CPTAI_Settings::get()['source'], self::target() );
            foreach ( array( 'gettext', 'gettext_with_context', 'ngettext', 'ngettext_with_context' ) as $hook ) {
                // Plain strings are translated in HTML once. Formats must be translated
                // before sprintf substitutes variables, which the DOM cannot reconstruct.
                add_filter( $hook, static fn( $translated ) => CPTAI_Provider::placeholders( $translated ) ? ( $dictionary[ $translated ] ?? $translated ) : $translated, 999 );
            }
        }
        add_shortcode( 'creative_pear_language_switcher', array( self::class, 'switcher' ) );
        add_action( 'wp_footer', static function () {
            if ( CPTAI_Settings::get()['footer'] && ! self::sensitive() ) {
                echo '<div style="padding:12px;text-align:center">' . self::switcher() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }
        } );
    }

    public static function scan_target(): string {
        $target = isset( $_GET['cptai_scan'] ) && is_string( $_GET['cptai_scan'] ) ? sanitize_key( wp_unslash( $_GET['cptai_scan'] ) ) : '';
        $nonce = isset( $_GET['_cptai_nonce'] ) && is_string( $_GET['_cptai_nonce'] ) ? wp_unslash( $_GET['_cptai_nonce'] ) : '';
        return current_user_can( 'manage_options' ) && wp_verify_nonce( $nonce, 'cptai_scan' )
            && in_array( $target, CPTAI_Settings::get()['targets'], true ) ? $target : '';
    }

    public static function scripts(): void {
        $scan = self::scan_target();
        $target = CPTAI_Routing::language() ?: self::target();
        if ( ( ! $scan && ! $target ) || self::sensitive() || is_preview() ) {
            return;
        }
        wp_enqueue_script( 'cptai-calendar', plugins_url( 'assets/calendar.js', CPTAI_FILE ), array(), CPTAI_VERSION, true );
        wp_enqueue_script( 'cptai-breaks', plugins_url( 'assets/breaks.js', CPTAI_FILE ), array(), CPTAI_VERSION, true );
        wp_enqueue_script( 'cptai-frontend', plugins_url( 'assets/frontend.js', CPTAI_FILE ), array( 'cptai-calendar', 'cptai-breaks' ), CPTAI_VERSION, true );
        if ( ! $scan && self::target() ) {
            $images = CPTAI_Images::map( CPTAI_Settings::get()['source'], $target );
            if ( $images ) {
                wp_enqueue_script( 'cptai-images', plugins_url( 'assets/images.js', CPTAI_FILE ), array(), CPTAI_VERSION, true );
                wp_add_inline_script( 'cptai-images', 'window.CPTAIImages=' . wp_json_encode( (object) $images, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
            }
        }
        $config = array( 'dictionary' => (object) ( self::target() ? CPTAI_Store::dictionary( CPTAI_Settings::get()['source'], $target ) : array() ), 'language' => $target, 'languages' => CPTAI_Routing::languages(), 'home' => home_url( '/' ) );
        $source = CPTAI_Settings::get()['source'];
        $data = CPTAI_Calendar::data();
        $config['calendar'] = array_intersect_key( $data, array_flip( array( $source, $target ) ) );
        $config['source'] = $source;
        $config['initial'] = ! CPTAI_HTML::preserves_markup();
        if ( $scan ) {
            $config['scan'] = array( 'target' => $scan, 'nonce' => wp_create_nonce( 'cptai_admin' ), 'ajax' => admin_url( 'admin-ajax.php' ), 'url' => CPTAI_Routing::source_url( self::current_url() ), 'backend' => admin_url( 'admin.php?page=creative-pear-translate-ai&target=' . $scan ), 'gettext' => array_keys( self::$gettext_strings ) );
            nocache_headers();
        }
        wp_add_inline_script( 'cptai-frontend', 'window.CPTAIFrontend=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
    }

    private static function sensitive(): bool {
        return ( function_exists( 'is_cart' ) && is_cart() )
            || ( function_exists( 'is_checkout' ) && is_checkout() )
            || ( function_exists( 'is_account_page' ) && is_account_page() );
    }

    public static function target(): string {
        $settings = CPTAI_Settings::get();
        $target = CPTAI_Routing::language();
        if ( ! $target ) {
            $target = isset( $_GET['cptai_lang'] ) && is_string( $_GET['cptai_lang'] ) ? sanitize_key( wp_unslash( $_GET['cptai_lang'] ) ) : '';
        }
        return in_array( $target, $settings['targets'], true ) ? $target : '';
    }

    public static function start(): void {
        if ( self::scan_target() ) {
            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }
            nocache_headers();
            return;
        }
        if ( '' === ( CPTAI_Routing::language() ?: self::target() ) || is_admin() || wp_doing_ajax() || is_feed() || is_robots() || is_trackback()
            || is_preview() || self::sensitive() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
            return;
        }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
        if ( ( CPTAI_Routing::language() ?: self::target() ) !== CPTAI_Settings::get()['source'] && ! headers_sent() ) {
            header( 'X-Robots-Tag: noindex, follow', true );
        }
        ob_start( array( self::class, 'translate' ) );
    }

    public static function translate( string $html ): string {
        if ( ( ! CPTAI_HTML::preserves_markup() && ! class_exists( 'DOMDocument' ) ) || strlen( $html ) > 2097152 || stripos( $html, '<html' ) === false ) {
            return $html;
        }
        $target = CPTAI_Routing::language() ?: self::target();
        if ( '' === $target ) {
            return $html;
        }
        try {
            $document = new CPTAI_HTML( $html );
            $dictionary = self::target() ? CPTAI_Store::find( $document->strings(), CPTAI_Settings::get()['source'], $target ) : array();
            $rendered = $document->render( $dictionary, $target );
            return self::target() ? CPTAI_Images::render( $rendered, CPTAI_Images::map( CPTAI_Settings::get()['source'], $target ) ) : $rendered;
        } catch ( Throwable $e ) {
            return $html;
        }
    }

    public static function local_url( string $url ): bool {
        $home = wp_parse_url( home_url( '/' ) );
        $parts = wp_parse_url( $url );
        return is_array( $parts ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
            && strtolower( $parts['host'] ?? '' ) === strtolower( $home['host'] ?? '' )
            && ( $parts['port'] ?? ( 'https' === ( $parts['scheme'] ?? '' ) ? 443 : 80 ) ) === ( $home['port'] ?? ( 'https' === $home['scheme'] ? 443 : 80 ) )
            && ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
            && str_starts_with( rawurldecode( $parts['path'] ?? '/' ), rtrim( $home['path'] ?? '', '/' ) . '/' )
            && ! preg_match( '~/(wp-admin|wp-json|wp-login\.php|wp-cron\.php)(/|$)~i', rawurldecode( $parts['path'] ?? '/' ) );
    }

    public static function link( string $url, string $target ): string {
        if ( '' === $url || str_starts_with( $url, '#' ) || preg_match( '/^(mailto:|tel:|javascript:|data:)/i', $url ) ) {
            return $url;
        }
        $absolute = WP_Http::make_absolute_url( $url, self::current_url() );
        if ( ! self::local_url( $absolute ) ) {
            return $url;
        }
        $parts = wp_parse_url( $absolute );
        parse_str( $parts['query'] ?? '', $query );
        if ( array_diff( array_keys( $query ), array( 'cptai_lang', 'page', 'paged', 's' ) )
            || preg_match( '/\.(?:pdf|zip|jpg|jpeg|png|gif|webp|svg|mp4|mp3|css|js|xml|json)$/i', $parts['path'] ?? '' ) ) {
            return $url;
        }
        return CPTAI_Routing::url( $absolute, $target );
    }

    public static function current_url(): string {
        $request = wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' );
        $home = wp_parse_url( home_url( '/' ) );
        $origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
        return $origin . '/' . ltrim( $request, '/' );
    }

    public static function switcher(): string {
        $settings = CPTAI_Settings::get();
        $names = CPTAI_Settings::languages();
        $url = CPTAI_Routing::source_url( self::current_url() );
        $current = CPTAI_Routing::language() ?: ( self::target() ?: $settings['source'] );
        $links = array();
        foreach ( array_unique( array_merge( array( $settings['source'] ), $settings['targets'] ) ) as $language ) {
            $destination = CPTAI_Routing::url( $url, $language );
            $links[] = '<a href="' . esc_url( $destination ) . '" lang="' . esc_attr( $language ) . '" hreflang="' . esc_attr( $language ) . '"' . ( $language === $current ? ' aria-current="true"' : '' ) . ' style="display:inline-block;padding:8px 12px">' . esc_html( $names[ $language ] ) . '</a>';
        }
        return '<nav class="cptai-switcher notranslate" translate="no" aria-label="Idiomas">' . implode( ' ', $links ) . '</nav>';
    }
}
