<?php
defined( 'ABSPATH' ) || exit;

final class CPTAI_Admin {
    public static function boot(): void {
        add_action( 'admin_menu', static function () {
            add_menu_page( 'Creative Pear Translate AI', 'CPT AI', 'manage_options', 'creative-pear-translate-ai', array( self::class, 'page' ), 'dashicons-translation', 81 );
        } );
        add_action( 'admin_enqueue_scripts', static function ( $hook ) {
            if ( 'toplevel_page_creative-pear-translate-ai' === $hook ) {
                wp_enqueue_style( 'cptai-admin', plugins_url( 'assets/admin.css', CPTAI_FILE ), array(), CPTAI_VERSION );
                wp_enqueue_script( 'cptai-admin', plugins_url( 'assets/admin.js', CPTAI_FILE ), array(), CPTAI_VERSION, true );
                $settings = CPTAI_Settings::get();
                wp_add_inline_script( 'cptai-admin', 'window.CPTAIAdmin=' . wp_json_encode( array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'cptai_admin' ), 'languages' => CPTAI_Settings::languages(), 'source' => $settings['source'], 'targets' => $settings['targets'], 'provider' => $settings['provider'], 'ready' => CPTAI_Settings::ready() ) ) . ';', 'before' );
            }
        } );
        add_action( 'admin_post_cptai_settings', array( self::class, 'save_settings' ) );
        add_action( 'admin_post_cptai_edit', array( self::class, 'edit' ) );
        foreach ( array( 'models', 'prepare', 'batch', 'capture', 'scan_link', 'row', 'save_string', 'save_settings_ajax', 'dictionary', 'add_string', 'plan_status', 'plan_disconnect', 'plan_result' ) as $action ) {
            add_action( 'wp_ajax_cptai_' . $action, array( self::class, $action ) );
        }
    }

    private static function permission( string $nonce = 'cptai_admin' ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No tienes permisos.', '', array( 'response' => 403 ) );
        }
        if ( wp_doing_ajax() ) {
            check_ajax_referer( $nonce, 'nonce' );
        } else {
            check_admin_referer( $nonce );
        }
    }

    private static function result( $result ): void {
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }
        wp_send_json_success( $result );
    }

    private static function target(): string {
        $target = isset( $_POST['target'] ) && is_string( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : '';
        if ( ! in_array( $target, CPTAI_Settings::get()['targets'], true ) ) {
            self::result( new WP_Error( 'target', 'Guarda primero el idioma de destino en los ajustes.' ) );
        }
        return $target;
    }

    private static function url() {
        $url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
        $url = CPTAI_Routing::source_url( $url );
        $parts = wp_parse_url( $url );
        parse_str( $parts['query'] ?? '', $query );
        if ( ! CPTAI_Frontend::local_url( $url ) || array_diff( array_keys( $query ), array( 'p', 'page_id' ) ) ) {
            return new WP_Error( 'url', 'Introduce una URL pública de este sitio, sin parámetros de acciones, búsqueda o sesiones.' );
        }
        foreach ( array( 'p', 'page_id' ) as $id_key ) {
            if ( isset( $query[ $id_key ] ) ) {
                $post = get_post( absint( $query[ $id_key ] ) );
                if ( ! $post || 'publish' !== $post->post_status || $post->post_password ) {
                    return new WP_Error( 'private', 'Solo se pueden capturar páginas públicas sin contraseña.' );
                }
            }
        }
        foreach ( array( 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ) as $option ) {
            $id = get_option( $option );
            if ( $id ) {
                $sensitive = wp_parse_url( get_permalink( $id ) );
                $path = rtrim( $parts['path'] ?? '/', '/' );
                $private_path = rtrim( $sensitive['path'] ?? '', '/' );
                if ( $url === get_permalink( $id ) || ( $private_path && ( $path === $private_path || str_starts_with( $path, $private_path . '/' ) ) ) ) {
                    return new WP_Error( 'private', 'Carrito, checkout y cuenta se excluyen para evitar capturar datos personales.' );
                }
            }
        }
        return $url;
    }

    public static function models(): void {
        self::permission();
        $provider = in_array( $_POST['provider'] ?? '', array( 'openai', 'claude', 'chatgpt' ), true ) ? $_POST['provider'] : 'openai';
        self::result( CPTAI_Provider::models( $provider ) );
    }

    public static function plan_status(): void {
        self::permission();
        self::result( array_merge( CPTAI_Plan::status(), array( 'ready' => CPTAI_Settings::ready() ) ) );
    }

    public static function plan_disconnect(): void {
        self::permission();
        CPTAI_Plan::disconnect();
        self::result( array( 'disconnected' => true ) );
    }

    public static function plan_result(): void {
        self::permission();
        self::result( CPTAI_Plan::result( is_string( $_POST['ticket'] ?? null ) ? $_POST['ticket'] : '' ) );
    }

    public static function scan_link(): void {
        self::permission();
        $target = self::target();
        $url = self::url();
        if ( is_wp_error( $url ) ) {
            self::result( $url );
        }
        self::result( array( 'url' => add_query_arg( array( 'cptai_scan' => $target, '_cptai_nonce' => wp_create_nonce( 'cptai_scan' ) ), $url ) ) );
    }

    public static function capture(): void {
        self::permission();
        $target = self::target();
        $url = self::url();
        if ( is_wp_error( $url ) ) {
            self::result( $url );
        }
        $strings = json_decode( wp_unslash( $_POST['strings'] ?? '' ), true );
        if ( ! is_array( $strings ) || count( $strings ) > 200 || strlen( wp_json_encode( $strings ) ) > 100000 ) {
            self::result( new WP_Error( 'size', 'El lote de cadenas es demasiado grande.' ) );
        }
        $strings = array_values( array_filter( $strings, static fn( $s ) => is_string( $s ) && CPTAI_HTML::translatable( trim( $s ) ) ) );
        try {
            $count = CPTAI_Store::capture( array_map( 'trim', $strings ), CPTAI_Settings::get()['source'], $target, $url );
            self::result( array( 'added' => $count ) );
        } catch ( Throwable $e ) {
            self::result( new WP_Error( 'db', 'No se pudieron guardar las cadenas. Comprueba la base de datos.' ) );
        }
    }

    public static function prepare(): void {
        self::permission();
        $target = self::target();
        $url = self::url();
        if ( is_wp_error( $url ) ) {
            self::result( $url );
        }
        if ( ! class_exists( 'DOMDocument' ) ) {
            self::result( new WP_Error( 'dom', 'Activa la extensión PHP DOM en tu servidor.' ) );
        }
        // No cookies or authorization forwarded. No redirects to another origin.
        $response = wp_safe_remote_get( $url, array( 'timeout' => 25, 'redirection' => 0, 'limit_response_size' => 2097153 ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            self::result( new WP_Error( 'fetch', 'No se pudo leer la página pública. Usa su URL final sin redirecciones o abre la captura en navegador.' ) );
        }
        $html = wp_remote_retrieve_body( $response );
        if ( strlen( $html ) > 2097152 || stripos( $html, '<html' ) === false ) {
            self::result( new WP_Error( 'html', 'La página no es HTML o supera los 2 MB.' ) );
        }
        $strings = ( new CPTAI_HTML( $html ) )->strings();
        if ( count( $strings ) > 2000 ) {
            self::result( new WP_Error( 'size', 'La página tiene demasiadas cadenas. Usa la captura en navegador.' ) );
        }
        $settings = CPTAI_Settings::get();
        try {
            $added = CPTAI_Store::capture( $strings, $settings['source'], $target, $url );
        } catch ( Throwable $e ) {
            self::result( new WP_Error( 'db', 'No se pudieron guardar las cadenas.' ) );
        }
        $known = CPTAI_Store::find( $strings, $settings['source'], $target );
        $pending = array_values( array_filter( $strings, static fn( $s ) => ! array_key_exists( $s, $known ) ) );
        $job = wp_generate_uuid4();
        set_transient( 'cptai_job_' . $job, array( 'user' => get_current_user_id(), 'source' => $settings['source'], 'target' => $target, 'url' => $url, 'strings' => $pending, 'offset' => 0 ), HOUR_IN_SECONDS );
        self::result( array( 'job' => $job, 'total' => count( $strings ), 'pending' => count( $pending ), 'added' => $added, 'preview' => CPTAI_Routing::url( $url, $target ) ) );
    }

    public static function batch(): void {
        self::permission();
        $id = sanitize_text_field( wp_unslash( $_POST['job'] ?? '' ) );
        if ( ! preg_match( '/^[a-f0-9-]{36}$/', $id ) ) {
            self::result( new WP_Error( 'job', 'La sesión de traducción no es válida.' ) );
        }
        $job = get_transient( 'cptai_job_' . $id );
        if ( ! $job || (int) $job['user'] !== get_current_user_id() || $job['source'] !== CPTAI_Settings::get()['source'] || ! in_array( $job['target'], CPTAI_Settings::get()['targets'], true ) ) {
            self::result( new WP_Error( 'job', 'La sesión caducó o los idiomas cambiaron. Vuelve a leer la página; las traducciones guardadas se conservan.' ) );
        }
        $lock = 'cptai_lock_' . $id;
        if ( ! add_option( $lock, time(), '', false ) ) {
            self::result( new WP_Error( 'busy', 'Este lote ya está en curso. Espera antes de reintentar.' ) );
        }
        $result = null;
        try {
            $strings = array();
            foreach ( array_slice( $job['strings'], $job['offset'], 10 ) as $string ) {
                $candidate = array_merge( $strings, array( $string ) );
                if ( strlen( wp_json_encode( $candidate ) ) > 12000 ) {
                    break;
                }
                $strings[] = $string;
            }
            if ( $strings ) {
                if ( 'chatgpt' === CPTAI_Settings::get()['provider'] ) {
                    if ( ! empty( $job['plan_ticket'] ) ) {
                        $queued = get_transient( 'cptai_plan_' . $job['plan_ticket'] );
                        if ( ! $queued || $queued['expires'] < time() ) { unset( $job['plan_ticket'] ); }
                    }
                    if ( ! empty( $job['plan_ticket'] ) ) {
                        $result = array( 'plan_ticket' => $job['plan_ticket'] );
                    } else {
                        $result = CPTAI_Plan::enqueue( $strings, $job['source'], $job['target'], array( 'kind' => 'batch', 'batch' => $id, 'offset' => $job['offset'] ) );
                        if ( ! is_wp_error( $result ) ) { $job['plan_ticket'] = $result['plan_ticket']; set_transient( 'cptai_job_' . $id, $job, HOUR_IN_SECONDS ); }
                    }
                } else {
                    $translated = CPTAI_Provider::translate( $strings, $job['source'], $job['target'] );
                    if ( is_wp_error( $translated ) ) {
                        $result = $translated;
                    } else {
                        foreach ( $strings as $i => $original ) {
                            // Preserve manual edits or translations made since the scan.
                            if ( ! CPTAI_Store::fill( $original, $translated[ $i ], $job['source'], $job['target'] ) ) {
                                throw new RuntimeException( 'No se pudo guardar la traducción.' );
                            }
                        }
                        $job['offset'] += count( $strings );
                    }
                }
            }
            if ( ! $result ) {
                set_transient( 'cptai_job_' . $id, $job, HOUR_IN_SECONDS );
                $result = array( 'done' => $job['offset'], 'total' => count( $job['strings'] ), 'complete' => $job['offset'] >= count( $job['strings'] ) );
            }
        } catch ( Throwable $e ) {
            $result = new WP_Error( 'save', 'No se pudo guardar el lote. Vuelve a leer la página para recuperar el progreso.' );
        } finally {
            delete_option( $lock );
        }
        self::result( $result );
    }

    public static function row(): void {
        self::permission();
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CPTAI_Store::table() . ' WHERE id=%d', absint( $_POST['id'] ?? 0 ) ) );
        if ( ! $row || $row->source_lang !== CPTAI_Settings::get()['source'] || ! in_array( $row->target_lang, CPTAI_Settings::get()['targets'], true ) ) {
            self::result( new WP_Error( 'row', 'La cadena ya no está disponible para los idiomas activos.' ) );
        }
        if ( 'chatgpt' === CPTAI_Settings::get()['provider'] ) {
            self::result( CPTAI_Plan::enqueue( array( $row->original ), $row->source_lang, $row->target_lang, array( 'kind' => 'row', 'row' => (int) $row->id ) ) );
        }
        $translated = CPTAI_Provider::translate( array( $row->original ), $row->source_lang, $row->target_lang );
        if ( is_wp_error( $translated ) ) {
            self::result( $translated );
        }
        // The user reviews the suggestion in the textarea before saving it.
        self::result( array( 'text' => $translated[0] ) );
    }

    public static function save_settings(): void {
        self::permission( 'cptai_settings' );
        if ( ( ! empty( $_POST['openai_key'] ) || ! empty( $_POST['claude_key'] ) ) && ! function_exists( 'openssl_encrypt' ) ) {
            wp_die( 'Activa la extensión PHP OpenSSL para proteger tus claves API.' );
        }
        try {
            $result = CPTAI_Settings::save( wp_unslash( $_POST ) );
        } catch ( Throwable $e ) {
            $result = new WP_Error( 'encryption', 'No se pudo proteger la clave API.' );
        }
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=creative-pear-translate-ai&saved=1' ) );
        exit;
    }

    public static function save_settings_ajax(): void {
        self::permission();
        try {
            $result = CPTAI_Settings::save( wp_unslash( $_POST ) );
        } catch ( Throwable $e ) {
            $result = new WP_Error( 'settings', 'No se pudieron guardar los ajustes. Comprueba la conexión y OpenSSL si cambias una clave.' );
        }
        if ( is_wp_error( $result ) ) {
            self::result( $result );
        }
        $settings = CPTAI_Settings::get();
        $public = array_intersect_key( $settings, array_flip( array( 'source', 'targets', 'provider', 'openai_model', 'claude_model', 'chatgpt_model', 'footer', 'capture_gettext' ) ) );
        $public['keys'] = array( 'openai' => '' !== CPTAI_Settings::key( 'openai' ), 'claude' => '' !== CPTAI_Settings::key( 'claude' ) );
        $public['ready'] = CPTAI_Settings::ready();
        self::result( $public );
    }

    public static function save_string(): void {
        self::permission();
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CPTAI_Store::table() . ' WHERE id=%d', absint( $_POST['id'] ?? 0 ) ) );
        $input = wp_unslash( $_POST['translated'] ?? '' );
        if ( ! is_string( $input ) || ! $row || $row->source_lang !== CPTAI_Settings::get()['source']
            || ! in_array( $row->target_lang, CPTAI_Settings::get()['targets'], true ) ) {
            self::result( new WP_Error( 'row', 'Esta cadena no está disponible para los idiomas activos. Actualiza el diccionario.' ) );
        }
        $text = trim( $input );
        if ( '' !== $text && ! CPTAI_Provider::valid( $row->original, $text ) ) {
            self::result( new WP_Error( 'text', 'Conserva los marcadores del texto y no introduzcas HTML.' ) );
        }
        if ( '' !== $text && ! CPTAI_Calendar::preserves_numbers( $row->original, $text, $row->source_lang, $row->target_lang ) ) {
            self::result( new WP_Error( 'date', 'Conserva el día, el año y la hora de la fecha. Los meses y días de semana se traducen automáticamente.' ) );
        }
        $expected = isset( $_POST['expected'] ) && is_string( $_POST['expected'] ) ? wp_unslash( $_POST['expected'] ) : $row->translated;
        // Compare and write atomically so concurrent browser tabs cannot overwrite a newer edit.
        $saved = $wpdb->query( $wpdb->prepare( 'UPDATE ' . CPTAI_Store::table() . ' SET translated=%s,updated_at=%s WHERE id=%d AND BINARY translated=BINARY %s', $text, current_time( 'mysql', true ), $row->id, $expected ) );
        if ( false === $saved ) {
            self::result( new WP_Error( 'db', 'No se pudo guardar la traducción. Tu edición permanece en el campo.' ) );
        }
        $current = $wpdb->get_var( $wpdb->prepare( 'SELECT translated FROM ' . CPTAI_Store::table() . ' WHERE id=%d', $row->id ) );
        if ( 0 === $saved && $current !== $text ) {
            self::result( new WP_Error( 'conflict', 'La cadena se guardó en otra sesión. Recarga para revisar esa versión antes de sustituirla.' ) );
        }
        self::result( array( 'id' => (int) $row->id, 'text' => $text, 'translated' => '' !== $text ) );
    }

    public static function add_string(): void {
        self::permission();
        global $wpdb;
        $target = self::target();
        $source = CPTAI_Settings::get()['source'];
        $original = isset( $_POST['original'] ) && is_string( $_POST['original'] ) ? trim( wp_unslash( $_POST['original'] ) ) : '';
        $translated = isset( $_POST['translated'] ) && is_string( $_POST['translated'] ) ? trim( wp_unslash( $_POST['translated'] ) ) : '';
        if ( ! CPTAI_HTML::translatable( $original ) || ! CPTAI_Provider::valid( $original, $original ) ) {
            self::result( new WP_Error( 'original', 'Introduce el mensaje original exacto, sin HTML y con un máximo de 3.500 bytes.' ) );
        }
        if ( '' !== $translated && ( ! CPTAI_Provider::valid( $original, $translated ) || ! CPTAI_Calendar::preserves_numbers( $original, $translated, $source, $target ) ) ) {
            self::result( new WP_Error( 'translation', 'La traducción debe conservar los marcadores y los números de las fechas, sin HTML.' ) );
        }
        $url = isset( $_POST['url'] ) && is_string( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        $url = $url ? CPTAI_Routing::source_url( $url ) : home_url( '/' );
        if ( ! CPTAI_Frontend::local_url( $url ) ) {
            self::result( new WP_Error( 'url', 'La página de referencia debe ser una URL pública de este sitio.' ) );
        }
        $inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . CPTAI_Store::table() . ' (source_lang,target_lang,source_hash,original,translated,source_url,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s)', $source, $target, hash( 'sha256', $original ), $original, $translated, $url, current_time( 'mysql', true ) ) );
        if ( false === $inserted ) { self::result( new WP_Error( 'db', 'No se pudo añadir la cadena. El texto permanece en los campos.' ) ); }
        if ( 0 === $inserted ) { self::result( new WP_Error( 'duplicate', 'Esta cadena ya existe para ese idioma. Búscala en el diccionario para editarla; su traducción se conserva.' ) ); }
        self::result( array( 'original' => $original, 'target' => $target ) );
    }

    public static function edit(): void {
        self::permission( 'cptai_edit' );
        global $wpdb;
        $id = absint( $_POST['id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CPTAI_Store::table() . ' WHERE id=%d', $id ) );
        $text = trim( wp_unslash( $_POST['translated'] ?? '' ) );
        if ( ! $row || ( '' !== $text && ! CPTAI_Provider::valid( $row->original, $text ) ) ) {
            wp_die( 'El texto no es válido. Conserva los marcadores y no introduzcas HTML.' );
        }
        if ( '' !== $text && ! CPTAI_Calendar::preserves_numbers( $row->original, $text, $row->source_lang, $row->target_lang ) ) {
            wp_die( 'Conserva el día, el año y la hora de la fecha. Los nombres se traducen automáticamente.' );
        }
        if ( ! CPTAI_Store::put( $row->original, $text, $row->source_lang, $row->target_lang, $row->source_url ) ) {
            wp_die( 'No se pudo guardar la traducción.' );
        }
        $return = wp_get_referer();
        wp_safe_redirect( $return ?: admin_url( 'admin.php?page=creative-pear-translate-ai' ) );
        exit;
    }

    private static function dictionary_data( array $input ): array {
        global $wpdb;
        $settings = CPTAI_Settings::get();
        $languages = CPTAI_Settings::languages();
        $target = isset( $input['target'] ) && is_string( $input['target'] ) ? sanitize_key( wp_unslash( $input['target'] ) ) : ( $settings['targets'][0] ?? '' );
        if ( ! in_array( $target, $settings['targets'], true ) ) {
            $target = $settings['targets'][0] ?? '';
        }
        $search = isset( $input['s'] ) && is_string( $input['s'] ) ? sanitize_text_field( wp_unslash( $input['s'] ) ) : '';
        $pending = ! empty( $input['pending'] );
        $sort_options = CPTAI_Store::sort_options();
        $sort = isset( $input['sort'] ) && is_string( $input['sort'] ) ? sanitize_key( wp_unslash( $input['sort'] ) ) : 'newest';
        if ( ! isset( $sort_options[ $sort ] ) ) {
            $sort = 'newest';
        }
        $order = $sort_options[ $sort ]['sql'];
        $page = max( 1, isset( $input['paged'] ) && is_scalar( $input['paged'] ) ? absint( $input['paged'] ) : 1 );
        $where = $wpdb->prepare( 'source_lang=%s AND target_lang=%s', $settings['source'], $target );
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( ' AND (original LIKE %s OR translated LIKE %s)', $like, $like );
        }
        if ( $pending ) {
            $where .= " AND translated=''";
        }
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CPTAI_Store::table() . ' WHERE ' . $where );
        $page = min( $page, max( 1, (int) ceil( $total / 30 ) ) );
        $rows = $wpdb->get_results( 'SELECT * FROM ' . CPTAI_Store::table() . ' WHERE ' . $where . ' ORDER BY ' . $order . $wpdb->prepare( ' LIMIT 30 OFFSET %d', ( $page - 1 ) * 30 ) );
        $ready = CPTAI_Settings::ready();
        $pagination_base = add_query_arg( array( 'page' => 'creative-pear-translate-ai', 'target' => $target, 's' => $search, 'pending' => $pending ? 1 : 0, 'sort' => $sort, 'paged' => '%#%' ), admin_url( 'admin.php' ) );
        return compact( 'settings', 'languages', 'target', 'search', 'pending', 'sort_options', 'sort', 'page', 'total', 'rows', 'ready', 'pagination_base' );
    }

    public static function dictionary(): void {
        self::permission();
        $data = self::dictionary_data( $_POST );
        extract( $data, EXTR_SKIP );
        ob_start();
        require __DIR__ . '/admin-dictionary.php';
        $html = ob_get_clean();
        self::result( array( 'html' => $html, 'total' => $total, 'page' => $page, 'target' => $target, 'sort' => $sort, 'search' => $search, 'pending' => $pending ) );
    }

    public static function page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        extract( self::dictionary_data( $_GET ), EXTR_SKIP );
        require __DIR__ . '/admin-page.php';
    }
}
