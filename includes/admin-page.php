<?php
defined( 'ABSPATH' ) || exit;
$design_url = plugins_url( 'assets/design/', CPTAI_FILE );
$ready = '' !== CPTAI_Settings::key( $settings['provider'] ) && '' !== $settings[ $settings['provider'] . '_model' ];
?>
<div id="cptai-app" class="wrap">
    <header class="cptai-app-header">
        <img class="cptai-emblem" src="<?php echo esc_url( $design_url . 'cutout-71-fe00c9aa3e07.png' ); ?>" alt="" width="24" height="24">
        <h1>Creative Pear Translate AI</h1>
        <span class="cptai-version">v<?php echo esc_html( CPTAI_VERSION ); ?></span>
        <a class="cptai-button" href="#cptai-settings" id="cptai-open-settings">Ajustes</a>
    </header>
    <div class="cptai-body">
        <section aria-labelledby="cptai-capture-heading">
            <h2 id="cptai-capture-heading">Capturar cadenas de una página</h2>
            <p class="cptai-lead">Lee el HTML de WordPress o abre la captura en navegador para detectar también los textos de JavaScript y AJAX.</p>
            <div class="cptai-panel">
                <div class="cptai-capture-row">
                    <label for="cptai-url" class="cptai-capture-label">URL pública</label>
                    <input type="url" id="cptai-url" class="cptai-url" value="<?php echo esc_url( home_url( '/' ) ); ?>" placeholder="https://tuweb.com/pagina/">
                </div>
                <div class="cptai-capture-row">
                    <label for="cptai-target" class="cptai-capture-label">Idioma de destino</label>
                    <select id="cptai-target"><?php foreach ( $settings['targets'] as $code ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $target, $code ); ?>><?php echo esc_html( $languages[ $code ] ); ?></option><?php endforeach; ?></select>
                    <button type="button" class="cptai-button cptai-button-primary" id="cptai-read">Leer HTML</button>
                    <button type="button" class="cptai-button" id="cptai-scan">Captura en navegador</button>
                    <button type="button" class="cptai-button" id="cptai-ai" disabled>Traducir pendientes con IA</button>
                    <button type="button" class="cptai-button" id="cptai-stop" hidden>Detener tras este lote</button>
                </div>
                <p class="cptai-hint">No se capturan campos personales ni páginas de carrito, checkout o cuenta. Puedes excluir una zona con <code>translate="no"</code> o <code>data-cptai-ignore</code>.</p>
                <p class="cptai-hint" id="cptai-connection-hint" <?php echo $ready ? 'hidden' : ''; ?>>La edición manual está disponible. Para usar IA, guarda una clave y un modelo en <a href="#cptai-settings" class="cptai-settings-link">Ajustes</a>.</p>
                <p id="cptai-status" role="status" aria-live="polite"></p>
            </div>
        </section>

        <section class="cptai-panel cptai-dictionary" aria-labelledby="cptai-dictionary-heading">
            <h2 id="cptai-dictionary-heading">Diccionario compartido</h2>
            <form method="get" class="cptai-filters">
                <input type="hidden" name="page" value="creative-pear-translate-ai">
                <div class="cptai-filter-language"><label for="cptai-filter-target">Idioma</label><select name="target" id="cptai-filter-target"><?php foreach ( $settings['targets'] as $code ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $target, $code ); ?>><?php echo esc_html( $languages[ $code ] ); ?></option><?php endforeach; ?></select></div>
                <div class="cptai-search-wrap">
                    <img src="<?php echo esc_url( $design_url . 'added-115-search.svg' ); ?>" alt="" aria-hidden="true">
                    <label for="cptai-search" class="screen-reader-text">Buscar cadena</label>
                    <input type="search" id="cptai-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Buscar original o traducción">
                </div>
                <label class="cptai-check"><input type="checkbox" name="pending" value="1" <?php checked( $pending ); ?>> Solo pendientes</label>
                <button class="cptai-button">Filtrar / actualizar</button>
                <div class="cptai-filter-sort"><label for="cptai-sort">Ordenar</label><select name="sort" id="cptai-sort"><?php foreach ( $sort_options as $key => $option ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $sort, $key ); ?>><?php echo esc_html( $option['label'] ); ?></option><?php endforeach; ?></select></div>
            </form>
            <p id="cptai-filter-status" class="cptai-hint" role="status" aria-live="polite"></p>
            <p id="cptai-draft-status" class="cptai-hint" role="status" hidden></p>
            <div id="cptai-dictionary-results">
                <?php require __DIR__ . '/admin-dictionary.php'; ?>
            </div>
            <p class="cptai-hint">Vacía una traducción y guarda para volver al original. Mayúsculas, puntuación y espacios interiores distinguen cadenas.</p>
        </section>

        <details class="cptai-settings" id="cptai-settings" <?php echo $target ? '' : 'open'; ?>>
            <summary>Idiomas y conexión API</summary>
            <form id="cptai-settings-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                <input type="hidden" name="action" value="cptai_settings"><?php wp_nonce_field( 'cptai_settings' ); ?>
                <div class="cptai-settings-grid">
                    <section class="cptai-setting-card">
                        <h3>Idiomas</h3>
                        <div class="cptai-setting-field"><label for="cptai-source">Idioma original</label><select id="cptai-source" name="source"><?php foreach ( $languages as $code => $name ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['source'], $code ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></div>
                        <div class="cptai-setting-field"><label>Idiomas de destino</label><div class="cptai-languages"><?php foreach ( $languages as $code => $name ) : ?><label class="cptai-check"><input type="checkbox" name="targets[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $settings['targets'], true ) ); ?>> <?php echo esc_html( $name ); ?></label><?php endforeach; ?></div><p class="cptai-hint">El idioma original se excluye de los destinos. Los enlaces usan <code>/es/</code>, <code>/en/</code>, etc., también para el original.</p></div>
                        <?php if ( ! get_option( 'permalink_structure' ) ) : ?><p class="cptai-hint">Activa enlaces permanentes con nombre de entrada en <a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">Ajustes → Enlaces permanentes</a> para que tu servidor resuelva las carpetas de idioma.</p><?php endif; ?>
                    </section>
                    <section class="cptai-setting-card">
                        <h3>Selector y captura</h3>
                        <div class="cptai-setting-field"><label class="cptai-check"><input type="checkbox" name="footer" value="1" <?php checked( $settings['footer'] ); ?>> Mostrar el selector al pie de las páginas</label><p class="cptai-hint">También puedes insertar <code>[creative_pear_language_switcher]</code> en un bloque o widget.</p></div>
                        <div class="cptai-setting-field"><label class="cptai-check"><input type="checkbox" name="capture_gettext" value="1" <?php checked( $settings['capture_gettext'] ); ?>> Incluir cadenas internas de WordPress y plugins</label><p class="cptai-hint">Captura mensajes localizados aunque no se muestren en la página. Puede añadir muchas cadenas; actívalo si falta un mensaje con marcadores.</p></div>
                        <div class="cptai-setting-field"><label for="cptai-provider">Proveedor para las sugerencias de IA</label><select id="cptai-provider" name="provider"><option value="openai" <?php selected( $settings['provider'], 'openai' ); ?>>OpenAI (ChatGPT)</option><option value="claude" <?php selected( $settings['provider'], 'claude' ); ?>>Anthropic (Claude)</option></select></div>
                    </section>
                    <?php foreach ( array( 'openai' => 'OpenAI', 'claude' => 'Claude' ) as $provider => $label ) : ?>
                    <section class="cptai-setting-card">
                        <h3><?php echo esc_html( $label ); ?></h3>
                        <div class="cptai-setting-field">
                            <label for="cptai-<?php echo esc_attr( $provider ); ?>-key">Clave API de <?php echo esc_html( $label ); ?></label>
                            <input type="password" id="cptai-<?php echo esc_attr( $provider ); ?>-key" name="<?php echo esc_attr( $provider ); ?>_key" value="" autocomplete="new-password" placeholder="<?php echo CPTAI_Settings::key( $provider ) ? 'Clave guardada' : 'Sin configurar'; ?>">
                            <label class="cptai-check cptai-key-clear"><input type="checkbox" name="forget_<?php echo esc_attr( $provider ); ?>" value="1"> Borrar clave guardada</label>
                            <p class="cptai-hint">Déjala vacía para conservarla. Se guarda cifrada y nunca se muestra en el navegador.</p>
                        </div>
                        <div class="cptai-setting-field">
                            <label for="cptai-<?php echo esc_attr( $provider ); ?>-model">Modelo de <?php echo esc_html( $label ); ?></label>
                            <div class="cptai-model-row"><input type="text" id="cptai-<?php echo esc_attr( $provider ); ?>-model" name="<?php echo esc_attr( $provider ); ?>_model" value="<?php echo esc_attr( $settings[ $provider . '_model' ] ); ?>" list="cptai-<?php echo esc_attr( $provider ); ?>-models" placeholder="ID de un modelo de texto"><button type="button" class="cptai-button cptai-models" data-provider="<?php echo esc_attr( $provider ); ?>">Consultar modelos</button></div>
                            <datalist id="cptai-<?php echo esc_attr( $provider ); ?>-models"></datalist>
                            <p class="cptai-hint">Guarda la clave primero y elige un modelo que genere texto.</p>
                        </div>
                    </section>
                    <?php endforeach; ?>
                </div>
                <div class="cptai-settings-footer"><button type="submit" class="cptai-button cptai-button-primary">Guardar ajustes</button><p class="cptai-hint">La IA es opcional. Solo envía cadenas al proveedor al pulsar sus botones y puede generar costes en tu cuenta API.</p></div>
            </form>
        </details>
    </div>
    <div id="cptai-toast" role="status" aria-live="polite" aria-atomic="true" hidden>
        <img class="cptai-toast-icon" src="<?php echo esc_url( $design_url . 'added-120-check-in-circle.svg' ); ?>" alt="" aria-hidden="true">
        <span id="cptai-toast-text"></span>
        <button type="button" id="cptai-toast-close" aria-label="Cerrar aviso"><img src="<?php echo esc_url( $design_url . 'added-122-close.svg' ); ?>" alt="" aria-hidden="true"></button>
    </div>
</div>
