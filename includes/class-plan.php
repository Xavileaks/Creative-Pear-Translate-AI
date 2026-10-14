<?php
defined( 'ABSPATH' ) || exit;

/** Local companion: WordPress never receives OpenAI OAuth credentials. */
final class CPTAI_Plan {
    private const OPTION = 'cptai_plan_link';
    private const QUEUE = 'cptai_plan_queue';

    public static function boot(): void {
        add_action( 'rest_api_init', static function () {
            foreach ( array( 'poll', 'complete' ) as $operation ) {
                register_rest_route( 'cptai/v1', '/plan/' . $operation, array(
                    'methods' => 'POST', 'callback' => array( self::class, $operation ),
                    'permission_callback' => array( self::class, 'authorize' ),
                ) );
            }
        } );
        add_action( 'admin_post_cptai_plan_download', array( self::class, 'download' ) );
    }

    public static function status(): array {
        $link = get_option( self::OPTION, array() );
        return array(
            'linked' => ! empty( $link['hash'] ),
            'online' => ! empty( $link['hash'] ) && ( $link['seen'] ?? 0 ) > time() - 30,
            'account' => $link['account'] ?? '', 'models' => $link['models'] ?? array(),
        );
    }

    public static function disconnect(): void {
        delete_option( self::OPTION );
        $id = get_option( self::QUEUE );
        if ( is_string( $id ) ) { self::release( $id ); delete_transient( 'cptai_plan_' . $id ); }
        delete_option( self::QUEUE );
    }

    public static function connection() {
        $endpoint = rest_url( 'cptai/v1/plan/' );
        $parts = wp_parse_url( $endpoint );
        if ( 'https' !== ( $parts['scheme'] ?? '' ) && ! in_array( $parts['host'] ?? '', array( '127.0.0.1', 'localhost', '::1' ), true ) ) {
            return new WP_Error( 'https', 'Esta conexión necesita HTTPS en WordPress.' );
        }
        self::disconnect();
        $secret = bin2hex( random_bytes( 32 ) );
        $installation = wp_generate_uuid4();
        update_option( self::OPTION, array( 'hash' => hash( 'sha256', $secret ), 'installation' => $installation, 'seen' => 0 ), false );
        return array( 'version' => 1, 'endpoint' => $endpoint, 'secret' => $secret, 'installation' => $installation, 'site' => home_url( '/' ) );
    }

    public static function download(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'No tienes permisos.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'cptai_plan_download' );
        if ( ! class_exists( 'ZipArchive' ) ) { wp_die( 'Activa la extensión PHP Zip para descargar el asistente.' ); }
        $path = wp_tempnam( 'cptai-assistant.zip' );
        $zip = new ZipArchive();
        if ( ! $path || true !== $zip->open( $path, ZipArchive::OVERWRITE ) ) { wp_die( 'No se pudo preparar la descarga.' ); }
        $connection = self::connection();
        if ( is_wp_error( $connection ) ) { $zip->close(); wp_delete_file( $path ); wp_die( esc_html( $connection->get_error_message() ) ); }
        $ok = $zip->addFromString( 'cptai-connection.json', wp_json_encode( $connection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        foreach ( array( 'cptai-companion.cjs', 'Conectar-ChatGPT.cmd', 'LEEME.txt' ) as $file ) {
            $ok = $zip->addFile( dirname( CPTAI_FILE ) . '/companion/' . $file, $file ) && $ok;
        }
        $ok = $zip->close() && $ok;
        if ( ! $ok ) { wp_delete_file( $path ); self::disconnect(); wp_die( 'No se pudo crear el asistente.' ); }
        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="cptai-conectar-chatgpt.zip"' );
        header( 'Content-Length: ' . filesize( $path ) );
        readfile( $path );
        wp_delete_file( $path );
        exit;
    }

    public static function authorize( WP_REST_Request $request ) {
        $link = get_option( self::OPTION, array() );
        $secret = $request->get_header( 'x-cptai-companion' );
        if ( ! is_string( $secret ) || ! preg_match( '/^[a-f0-9]{64}$/D', $secret ) || empty( $link['hash'] )
            || ! hash_equals( $link['hash'], hash( 'sha256', $secret ) ) ) {
            return new WP_Error( 'cptai_link', 'La vinculación no es válida. Descarga un asistente nuevo desde CPT AI.', array( 'status' => 401 ) );
        }
        return true;
    }

    private static function no_cache( array $data ): WP_REST_Response {
        return new WP_REST_Response( $data, 200, array( 'Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache' ) );
    }

    public static function poll( WP_REST_Request $request ) {
        $authorized = self::authorize( $request );
        if ( is_wp_error( $authorized ) ) { return $authorized; }
        $input = $request->get_json_params();
        if ( ! is_array( $input ) || ! is_string( $input['account'] ?? '' ) || ! is_array( $input['models'] ?? array() ) || strlen( $request->get_body() ) > 24000 ) { return new WP_Error( 'input', 'Petición no válida.', array( 'status' => 400 ) ); }
        $link = get_option( self::OPTION, array() );
        $link['seen'] = time();
        $link['account'] = sanitize_text_field( substr( (string) ( $input['account'] ?? '' ), 0, 120 ) );
        $models = array_slice( (array) ( $input['models'] ?? array() ), 0, 150 );
        $link['models'] = array_values( array_filter( $models, static fn( $id ) => is_string( $id ) && preg_match( '/^[a-zA-Z0-9_.:\/-]{1,120}$/D', $id ) ) );
        update_option( self::OPTION, $link, false );
        if ( ! empty( $input['heartbeat'] ) ) { return self::no_cache( array( 'job' => null ) ); }
        $id = get_option( self::QUEUE );
        $job = is_string( $id ) ? get_transient( 'cptai_plan_' . $id ) : false;
        if ( $id && ( ! $job || $job['expires'] < time() ) ) { self::release( $id ); $job = false; }
        if ( $job && 'queued' === $job['status'] ) {
            // Atomic claim: never dispatch the same inference twice to two companions.
            $claim = 'cptai_plan_claim_' . $id;
            if ( add_option( $claim, time(), '', false ) ) {
                $job = get_transient( 'cptai_plan_' . $id );
                if ( ! $job || 'queued' !== $job['status'] ) { delete_option( $claim ); return self::no_cache( array( 'job' => null ) ); }
                $job['status'] = 'processing';
                set_transient( 'cptai_plan_' . $id, $job, 180 );
                delete_option( $claim );
                return self::no_cache( array( 'job' => array( 'id' => $id, 'model' => $job['model'], 'source' => $job['source'], 'target' => $job['target'], 'strings' => $job['strings'] ) ) );
            }
        }
        return self::no_cache( array( 'job' => null ) );
    }

    public static function complete( WP_REST_Request $request ) {
        $authorized = self::authorize( $request );
        if ( is_wp_error( $authorized ) ) { return $authorized; }
        $input = $request->get_json_params();
        $id = is_array( $input ) && is_string( $input['id'] ?? null ) ? $input['id'] : '';
        if ( ! preg_match( '/^[a-f0-9]{32}$/D', $id ) || strlen( $request->get_body() ) > 180000 ) {
            return new WP_Error( 'input', 'Petición no válida.', array( 'status' => 400 ) );
        }
        $job = get_transient( 'cptai_plan_' . $id );
        $link = get_option( self::OPTION, array() );
        if ( ! $job || $job['installation'] !== ( $link['installation'] ?? '' ) || $job['expires'] < time() || ! in_array( $job['status'], array( 'processing', 'complete' ), true ) ) {
            return new WP_Error( 'expired', 'Este trabajo caducó o ya se completó.', array( 'status' => 409 ) );
        }
        if ( 'complete' === $job['status'] ) { return self::no_cache( array( 'accepted' => true ) ); }
        $translated = $input['translations'] ?? null;
        $error = $input['error'] ?? '';
        if ( $error ) {
            // Only known codes cross the bridge; upstream messages may contain credentials.
            $job['error'] = self::error_message( is_string( $error ) ? $error : '' );
        } elseif ( ! is_array( $translated ) || ! array_is_list( $translated ) || count( $translated ) !== count( $job['strings'] ) ) {
            $job['error'] = 'ChatGPT no devolvió todas las traducciones. No se ha guardado este lote.';
        } else {
            foreach ( $job['strings'] as $i => $original ) {
                if ( ! is_string( $translated[ $i ] ) || ! CPTAI_Provider::valid( $original, $translated[ $i ] )
                    || ! CPTAI_Calendar::preserves_numbers( $original, $translated[ $i ], $job['source'], $job['target'] ) ) {
                    $job['error'] = 'La respuesta alteró marcadores o cifras de fechas, o contiene HTML. No se ha guardado este lote.';
                    break;
                }
            }
            if ( empty( $job['error'] ) ) { $job['translations'] = $translated; }
        }
        $job['status'] = 'complete';
        set_transient( 'cptai_plan_' . $id, $job, 180 );
        // Keep the queue until the administrator applies/acknowledges the result.
        return self::no_cache( array( 'accepted' => true ) );
    }

    public static function error_message( string $code ): string {
        $messages = array(
            'subscription_sharing_usage_limit_exceeded' => 'Se alcanzó un límite de tu plan o de esta aplicación. Revisa el uso en ChatGPT. No se ha utilizado la API de pago.',
            'subscription_sharing_user_not_eligible' => 'OpenAI no habilita el uso del plan para esta cuenta o espacio de trabajo.',
            'subscription_sharing_usage_unavailable' => 'OpenAI no puede comprobar el uso de tu plan. Inténtalo más tarde.',
            'auth' => 'La sesión de ChatGPT caducó o fue revocada. Vuelve a abrir el asistente e inicia sesión.',
            'model' => 'El modelo no está disponible para esta conexión. Consulta los modelos y elige otro.',
            'timeout' => 'ChatGPT tardó demasiado. No se ha guardado este lote.',
            'incomplete' => 'ChatGPT no terminó la respuesta. No se ha guardado este lote.',
            'format' => 'ChatGPT no devolvió el formato esperado. No se ha guardado este lote.',
        );
        return $messages[ $code ] ?? 'No se pudo completar la traducción con el plan. No se ha utilizado la API de pago.';
    }

    public static function enqueue( array $strings, string $source, string $target, array $context ) {
        $status = self::status();
        $settings = CPTAI_Settings::get();
        if ( ! $status['online'] ) { return new WP_Error( 'offline', 'Abre el asistente en tu ordenador e inicia sesión en ChatGPT antes de traducir.' ); }
        if ( ! $settings['chatgpt_model'] || ! in_array( $settings['chatgpt_model'], $status['models'], true ) ) {
            return new WP_Error( 'model', 'Consulta los modelos del plan, selecciona uno y guarda los ajustes.' );
        }
        if ( ! $strings || count( $strings ) > 10 || strlen( wp_json_encode( $strings ) ) > 12000 ) { return new WP_Error( 'size', 'El lote supera el tamaño permitido.' ); }
        $id = bin2hex( random_bytes( 16 ) );
        $existing = get_option( self::QUEUE );
        $previous = is_string( $existing ) ? get_transient( 'cptai_plan_' . $existing ) : false;
        if ( $existing && ( ! $previous || $previous['expires'] < time() ) ) { self::release( $existing ); }
        if ( ! add_option( self::QUEUE, $id, '', false ) ) { return new WP_Error( 'busy', 'Ya hay una petición al plan en curso en esta web. Espera a que termine.' ); }
        $link = get_option( self::OPTION );
        $job = array( 'status' => 'queued', 'expires' => time() + 150, 'user' => get_current_user_id(), 'installation' => $link['installation'],
            'strings' => array_values( $strings ), 'source' => $source, 'target' => $target, 'model' => $settings['chatgpt_model'], 'context' => $context );
        set_transient( 'cptai_plan_' . $id, $job, 180 );
        return array( 'plan_ticket' => $id );
    }

    public static function result( string $id ) {
        if ( ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) { return new WP_Error( 'job', 'Petición no válida.' ); }
        $job = get_transient( 'cptai_plan_' . $id );
        if ( ! $job || (int) $job['user'] !== get_current_user_id() ) { return new WP_Error( 'job', 'La petición caducó o pertenece a otra sesión.' ); }
        $link = get_option( self::OPTION, array() );
        $settings = CPTAI_Settings::get();
        if ( $job['installation'] !== ( $link['installation'] ?? '' ) || 'chatgpt' !== $settings['provider'] || $job['model'] !== $settings['chatgpt_model'] || $job['source'] !== $settings['source'] || ! in_array( $job['target'], $settings['targets'], true ) ) {
            self::release( $id );
            return new WP_Error( 'changed', 'La conexión, el proveedor o los idiomas cambiaron. Vuelve a preparar la traducción.' );
        }
        if ( isset( $job['result'] ) ) { return $job['result']; }
        if ( ! empty( $job['error'] ) ) { self::release( $id ); return new WP_Error( 'plan', $job['error'] ); }
        if ( $job['expires'] < time() ) { self::release( $id ); return new WP_Error( 'timeout', 'La petición caducó. Comprueba que el asistente sigue abierto. No se ha usado la API de pago.' ); }
        if ( 'complete' !== $job['status'] ) { return array( 'plan_waiting' => true ); }
        $lock = 'cptai_plan_apply_' . $id;
        if ( ! add_option( $lock, time(), '', false ) ) { return array( 'plan_waiting' => true ); }
        try {
            // Refresh after acquiring the lock: another poll may have applied the result.
            $job = get_transient( 'cptai_plan_' . $id );
            if ( isset( $job['result'] ) ) { return $job['result']; }
            if ( 'row' === $job['context']['kind'] ) {
                $job['result'] = array( 'text' => $job['translations'][0] );
            } else {
                $batch_id = $job['context']['batch'];
                $batch = get_transient( 'cptai_job_' . $batch_id );
                if ( ! $batch || $batch['offset'] !== $job['context']['offset'] || (int) $batch['user'] !== get_current_user_id() ) {
                    self::release( $id );
                    return new WP_Error( 'batch', 'El lote cambió o caducó. Vuelve a leer la página.' );
                }
                foreach ( $job['strings'] as $i => $original ) {
                    if ( ! CPTAI_Store::fill( $original, $job['translations'][ $i ], $job['source'], $job['target'] ) ) { throw new RuntimeException( 'save' ); }
                }
                $batch['offset'] += count( $job['strings'] );
                unset( $batch['plan_ticket'] );
                set_transient( 'cptai_job_' . $batch_id, $batch, HOUR_IN_SECONDS );
                $job['result'] = CPTAI_Admin::batch_result( $batch );
            }
            set_transient( 'cptai_plan_' . $id, $job, 180 );
            self::release( $id );
            return $job['result'];
        } catch ( Throwable $e ) {
            return new WP_Error( 'save', 'No se pudo guardar el resultado. La respuesta se conserva unos minutos para reintentar.' );
        } finally { delete_option( $lock ); }
    }

    private static function release( string $id ): void {
        $job = get_transient( 'cptai_plan_' . $id );
        if ( $job && 'batch' === $job['context']['kind'] ) {
            $batch_id = $job['context']['batch'];
            $batch = get_transient( 'cptai_job_' . $batch_id );
            if ( $batch && ( $batch['plan_ticket'] ?? '' ) === $id ) { unset( $batch['plan_ticket'] ); set_transient( 'cptai_job_' . $batch_id, $batch, HOUR_IN_SECONDS ); }
        }
        if ( get_option( self::QUEUE ) === $id ) { delete_option( self::QUEUE ); }
    }
}
