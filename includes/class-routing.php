<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_Routing {
    private const VERSION = '2';

    public static function boot(): void {
        add_filter( 'query_vars', static function ( $vars ) { $vars[] = 'cptai_lang'; return $vars; } );
        add_filter( 'rewrite_rules_array', array( self::class, 'rules' ), 90 );
        add_filter( 'option_rewrite_rules', array( self::class, 'runtime_rules' ), PHP_INT_MAX );
        add_filter( 'redirect_canonical', array( self::class, 'canonical' ), 20 );
        add_action( 'template_redirect', array( self::class, 'legacy_redirect' ), -1 );
        add_action( 'update_option_page_on_front', array( self::class, 'flush' ), 10, 0 );
        add_action( 'update_option_show_on_front', array( self::class, 'flush' ), 10, 0 );
        add_action( 'init', static function () {
            if ( get_option( 'cptai_routing_version' ) !== self::VERSION ) {
                self::flush();
            }
        }, 99 );
    }

    public static function activate(): void {
        CPTAI_Store::install();
        // Activation happens after plugins_loaded, so explicitly register the
        // rule generator before rebuilding WordPress's stored rules.
        add_filter( 'rewrite_rules_array', array( self::class, 'rules' ), 90 );
        self::flush();
    }

    public static function runtime_rules( $rules ) {
        // A migration flag cannot guarantee that stored rules still contain our
        // routes: cache restores or other plugins can rebuild them later. Supply
        // the language variants when WordPress reads rules for a language URL.
        // Leave missing/empty rules alone so core regenerates its native rules.
        if ( ! is_array( $rules ) || ! $rules || ! self::language() ) {
            return $rules;
        }
        return self::rules( $rules );
    }

    public static function languages(): array {
        $settings = CPTAI_Settings::get();
        return array_values( array_unique( array_merge( array( $settings['source'] ), $settings['targets'] ) ) );
    }

    public static function rules( array $rules ): array {
        $localized = array();
        // A custom query variable otherwise prevents WP_Query from recognizing a
        // static front page. Resolve the configured front page explicitly.
        $front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
        foreach ( self::languages() as $language ) {
            $prefix = preg_quote( $language, '#' );
            $localized[ '^' . $prefix . '/?$' ] = 'index.php?' . ( $front ? 'page_id=' . $front . '&' : '' ) . 'cptai_lang=' . $language;
            foreach ( $rules as $pattern => $query ) {
                if ( ! is_string( $query ) || ! str_starts_with( $query, 'index.php?' )
                    || str_contains( $query, 'cptai_lang=' ) || str_contains( $query, 'rest_route=' ) ) {
                    continue;
                }
                // Literal language prefixes add no capture groups: native $matches indices
                // for nested pages, taxonomies, products and pagination stay unchanged.
                $localized[ '^' . $prefix . '/' . ltrim( $pattern, '^' ) ] = $query . '&cptai_lang=' . $language;
            }
        }
        return $localized + $rules;
    }

    public static function flush(): void {
        flush_rewrite_rules( false );
        update_option( 'cptai_routing_version', self::VERSION, false );
    }

    public static function deactivate(): void {
        remove_filter( 'rewrite_rules_array', array( self::class, 'rules' ), 90 );
        remove_filter( 'option_rewrite_rules', array( self::class, 'runtime_rules' ), PHP_INT_MAX );
        delete_option( 'cptai_routing_version' );
        flush_rewrite_rules( false );
    }

    public static function language( ?string $url = null ): string {
        $url = $url ?? CPTAI_Frontend::current_url();
        $home_path = rtrim( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?? '', '/' );
        $path = wp_parse_url( $url, PHP_URL_PATH ) ?? '/';
        if ( ! str_starts_with( $path, $home_path . '/' ) ) {
            return '';
        }
        $relative = substr( $path, strlen( $home_path ) + 1 );
        $language = strtolower( explode( '/', $relative, 2 )[0] );
        return in_array( $language, self::languages(), true ) ? $language : '';
    }

    public static function source_url( string $url ): string {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ! CPTAI_Frontend::local_url( $url ) ) {
            return $url;
        }
        $language = self::language( $url );
        $home_path = rtrim( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?? '', '/' );
        if ( $language ) {
            $relative = substr( $parts['path'] ?? '/', strlen( $home_path ) + strlen( $language ) + 1 );
            $parts['path'] = $home_path . '/' . ltrim( $relative, '/' );
        }
        return remove_query_arg( array( 'cptai_lang', 'cptai_scan', '_cptai_nonce' ), self::assemble( $parts ) );
    }

    public static function url( string $url, string $language ): string {
        if ( ! in_array( $language, self::languages(), true ) || ! CPTAI_Frontend::local_url( $url ) ) {
            return $url;
        }
        $parts = wp_parse_url( self::source_url( $url ) );
        $home_path = rtrim( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?? '', '/' );
        $relative = substr( $parts['path'] ?? '/', strlen( $home_path ) );
        $parts['path'] = $home_path . '/' . $language . '/' . ltrim( $relative, '/' );
        return self::assemble( $parts );
    }

    private static function assemble( array $parts ): string {
        return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
            . ( $parts['path'] ?? '/' ) . ( ! empty( $parts['query'] ) ? '?' . $parts['query'] : '' )
            . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
    }

    public static function canonical( $redirect ) {
        $language = self::language();
        if ( $language && is_string( $redirect ) && CPTAI_Frontend::local_url( $redirect ) ) {
            return self::url( $redirect, $language );
        }
        return $redirect;
    }

    public static function legacy_redirect(): void {
        if ( ! is_admin() && ! wp_doing_ajax() && isset( $_GET['cptai_lang'] ) && is_string( $_GET['cptai_lang'] )
            && in_array( $_GET['cptai_lang'], self::languages(), true ) && ! CPTAI_Frontend::scan_target()
            && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
            wp_safe_redirect( self::url( CPTAI_Frontend::current_url(), sanitize_key( $_GET['cptai_lang'] ) ), 301 );
            exit;
        }
    }
}
