<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_Updater {
    public const HOOK = 'cptai_check_github_update';
    public const STATE = 'cptai_github_update';
    public const REPO = 'https://github.com/Xavileaks/Creative-Pear-Translate-AI';
    private const API = 'https://api.github.com/repos/Xavileaks/Creative-Pear-Translate-AI/releases/latest';
    private const INTERVAL = 300;

    public static function boot(): void {
        if ( wp_installing() ) { return; }
        add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
        add_action( 'init', array( self::class, 'schedule' ) );
        add_action( self::HOOK, array( self::class, 'check' ) );
        add_action( 'admin_init', static function () { if ( current_user_can( 'update_plugins' ) ) { self::check(); } } );
        add_filter( 'update_plugins_github.com', array( self::class, 'native_update' ), 10, 4 );
        // Reads use cached metadata only, including when WordPress.org is down.
        add_filter( 'site_transient_update_plugins', array( self::class, 'inject' ) );
        add_filter( 'plugins_api', array( self::class, 'details' ), 10, 3 );
        add_action( 'upgrader_process_complete', array( self::class, 'completed' ), 10, 2 );
    }

    public static function schedules( array $schedules ): array {
        $schedules['cptai_five_minutes'] = array( 'interval' => self::INTERVAL, 'display' => 'CPT AI · Cada 5 minutos' );
        return $schedules;
    }

    public static function activate(): void {
        add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
        self::schedule();
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + self::INTERVAL, 'cptai_five_minutes', self::HOOK ); }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::HOOK );
    }

    public static function parse( array $release ) {
        $tag = $release['tag_name'] ?? '';
        if ( ! is_string( $tag ) || ! preg_match( '/^v(\d+\.\d+\.\d+)$/D', $tag, $m ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) { return false; }
        $package = self::REPO . '/releases/download/' . $tag . '/creative-pear-translate-ai.zip';
        foreach ( $release['assets'] ?? array() as $asset ) {
            if ( ( $asset['name'] ?? '' ) === 'creative-pear-translate-ai.zip' && ( $asset['browser_download_url'] ?? '' ) === $package && ( $asset['state'] ?? '' ) === 'uploaded' ) {
                return array( 'version' => $m[1], 'package' => $package, 'url' => self::REPO . '/releases/tag/' . $tag, 'notes' => is_string( $release['body'] ?? null ) ? substr( $release['body'], 0, 30000 ) : '' );
            }
        }
        return false;
    }

    public static function check(): void {
        $state = get_option( self::STATE, array() );
        if ( (int) ( $state['checked'] ?? 0 ) > time() - self::INTERVAL ) { return; }
        // A short lease prevents concurrent admin/cron requests to GitHub.
        $lock = 'cptai_github_update_lock';
        global $wpdb;
        $previous = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $lock ) );
        if ( $previous && (int) $previous < time() - 60 ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock, $previous ) );
            wp_cache_delete( $lock, 'options' );
        }
        $lease = time() . ':' . wp_generate_uuid4();
        if ( ! add_option( $lock, $lease, '', false ) ) { return; }
        try {
            $state = get_option( self::STATE, array() );
            if ( (int) ( $state['checked'] ?? 0 ) > time() - self::INTERVAL ) { return; }
            $headers = array( 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28' );
            if ( ! empty( $state['etag'] ) ) { $headers['If-None-Match'] = $state['etag']; }
            $attempt = time();
            $response = wp_safe_remote_get( self::API, array( 'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 131073, 'headers' => $headers, 'user-agent' => 'Creative-Pear-Translate-AI/' . CPTAI_VERSION ) );
            $state['checked'] = $attempt;
            $code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
            $state['error'] = 'GitHub no respondió correctamente. Se volverá a consultar en 5 minutos.';
            if ( 304 === $code && ! empty( $state['release'] ) ) { $state['error'] = ''; $state['verified'] = time(); }
            elseif ( 200 === $code && strlen( wp_remote_retrieve_body( $response ) ) <= 131072 ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                $release = is_array( $body ) ? self::parse( $body ) : false;
                if ( $release ) {
                    $state['release'] = $release; $state['error'] = ''; $state['verified'] = time();
                    $etag = wp_remote_retrieve_header( $response, 'etag' );
                    $state['etag'] = is_string( $etag ) && strlen( $etag ) <= 256 && ! preg_match( '/[\r\n]/', $etag ) ? $etag : '';
                } else { $state['error'] = 'La última publicación todavía no tiene un ZIP instalable válido. Se volverá a consultar en 5 minutos.'; }
            }
            update_option( self::STATE, $state, false );
            // Change only this plugin's entry; leave every other update intact.
            $updates = self::inject( get_site_transient( 'update_plugins' ) );
            set_site_transient( 'update_plugins', $updates );
        } finally {
            if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock, $lease ) ) ) { wp_cache_delete( $lock, 'options' ); }
        }
    }

    public static function payload(): array {
        $state = get_option( self::STATE, array() );
        $release = ( $state['verified'] ?? 0 ) > time() - DAY_IN_SECONDS ? ( $state['release'] ?? array() ) : array();
        return array( 'id' => self::REPO, 'slug' => 'creative-pear-translate-ai', 'plugin' => plugin_basename( CPTAI_FILE ), 'version' => $release['version'] ?? CPTAI_VERSION, 'new_version' => $release['version'] ?? CPTAI_VERSION, 'url' => $release['url'] ?? self::REPO, 'package' => $release['package'] ?? '', 'requires' => '6.4', 'requires_php' => '8.1' );
    }

    public static function native_update( $update, array $data, string $file, array $locales ) {
        if ( $file !== plugin_basename( CPTAI_FILE ) ) { return $update; }
        self::check();
        return self::payload();
    }

    public static function inject( $updates ) {
        if ( ! is_object( $updates ) ) { $updates = new stdClass(); }
        $file = plugin_basename( CPTAI_FILE ); $payload = (object) self::payload();
        $updates->response = (array) ( $updates->response ?? array() );
        $updates->no_update = (array) ( $updates->no_update ?? array() );
        $updates->checked = (array) ( $updates->checked ?? array() );
        $updates->checked[ $file ] = CPTAI_VERSION;
        unset( $updates->response[ $file ], $updates->no_update[ $file ] );
        if ( $payload->package && version_compare( $payload->new_version, CPTAI_VERSION, '>' ) ) { $updates->response[ $file ] = $payload; }
        else { $updates->no_update[ $file ] = $payload; }
        return $updates;
    }

    public static function details( $result, string $action, $args ) {
        if ( 'plugin_information' !== $action || 'creative-pear-translate-ai' !== ( $args->slug ?? '' ) ) { return $result; }
        self::check(); $payload = self::payload(); $state = get_option( self::STATE, array() );
        return (object) array( 'name' => 'Creative Pear Translate AI', 'slug' => $payload['slug'], 'version' => $payload['version'], 'author' => 'Creative Pear', 'homepage' => self::REPO, 'requires' => $payload['requires'], 'requires_php' => $payload['requires_php'], 'download_link' => $payload['package'], 'sections' => array( 'description' => 'Diccionario compartido de textos e imágenes por idioma, con IA opcional.', 'changelog' => wpautop( esc_html( $state['release']['notes'] ?? 'Las versiones estables se publican en GitHub Releases.' ) ) ) );
    }

    public static function completed( $upgrader, array $options ): void {
        if ( 'plugin' === ( $options['type'] ?? '' ) && 'update' === ( $options['action'] ?? '' ) && in_array( plugin_basename( CPTAI_FILE ), (array) ( $options['plugins'] ?? array() ), true ) ) { delete_option( self::STATE ); }
    }
}
