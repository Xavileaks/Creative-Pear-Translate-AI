<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_Elementor {
    public static function boot(): void {
        add_action( 'elementor/widgets/register', static function ( $manager ) {
            require_once __DIR__ . '/elementor-language-widget.php';
            $manager->register( new CPTAI_Elementor_Language_Widget() );
        } );
        foreach ( array( 'wp_enqueue_scripts', 'elementor/frontend/after_register_styles', 'elementor/editor/after_enqueue_styles' ) as $hook ) {
            add_action( $hook, array( self::class, 'styles' ) );
        }
    }

    public static function styles(): void {
        wp_register_style( 'cptai-switcher', plugins_url( 'assets/switcher.css', CPTAI_FILE ), array(), CPTAI_VERSION );
    }

    public static function render( array $options ): string {
        $mode = $options['display'] ?? 'flags_name';
        if ( ! in_array( $mode, array( 'flags', 'flags_code', 'flags_name', 'name', 'code' ), true ) ) { $mode = 'flags_name'; }
        $english_flag = in_array( $options['en_flag'] ?? '', array( 'us', 'gb', 'gb-eng' ), true ) ? $options['en_flag'] : 'us';
        $flag_codes = array( 'es' => 'es', 'en' => $english_flag, 'ca' => 'es-ct', 'fr' => 'fr', 'de' => 'de', 'it' => 'it', 'pt' => 'br' === ( $options['pt_flag'] ?? '' ) ? 'br' : 'pt', 'nl' => 'nl', 'pl' => 'pl', 'uk' => 'ua', 'ar' => 'sa', 'ja' => 'jp', 'zh' => 'cn' );
        $settings = CPTAI_Settings::get();
        $names = CPTAI_Settings::languages();
        $current = CPTAI_Routing::language() ?: $settings['source'];
        $url = CPTAI_Routing::source_url( CPTAI_Frontend::current_url() );
        // Editor AJAX renders run under wp-admin; links must still point to the
        // document's public permalink, never to the editor request URL.
        if ( is_admin() || wp_doing_ajax() || isset( $_GET['elementor-preview'] ) ) {
            $document = \Elementor\Plugin::instance()->documents->get_current();
            if ( $document ) {
                $permalink = get_permalink( $document->get_main_id() );
                if ( $permalink ) { $url = CPTAI_Routing::source_url( $permalink ); }
            }
        }
        $html = '<nav class="cptai-switcher cptai-elementor-switcher notranslate" translate="no" aria-label="Idiomas">';
        foreach ( CPTAI_Routing::languages() as $language ) {
            $name = $names[ $language ];
            $html .= '<a class="cptai-language' . ( $current === $language ? ' is-active' : '' ) . '" href="' . esc_url( CPTAI_Routing::url( $url, $language ) ) . '" lang="' . esc_attr( $language ) . '" hreflang="' . esc_attr( $language ) . '" aria-label="' . esc_attr( $name ) . '" title="' . esc_attr( $name ) . '"' . ( $current === $language ? ' aria-current="true"' : '' ) . '>';
            if ( str_starts_with( $mode, 'flags' ) ) {
                $html .= '<img class="cptai-flag" src="' . esc_url( plugins_url( 'assets/flags/' . $flag_codes[ $language ] . '.svg', CPTAI_FILE ) ) . '" alt="" aria-hidden="true" width="32" height="24">';
            }
            if ( 'flags' !== $mode ) {
                $label = in_array( $mode, array( 'code', 'flags_code' ), true ) ? strtoupper( $language ) : $name;
                $html .= '<span class="cptai-language-label">' . esc_html( $label ) . '</span>';
            }
            $html .= '</a>';
        }
        return $html . '</nav>';
    }
}
