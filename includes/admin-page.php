<?php
defined( 'ABSPATH' ) || exit;
$design_url = plugins_url( 'assets/design/', CPTAI_FILE );
$ready = CPTAI_Settings::ready();
$plan_status = CPTAI_Plan::status();
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
                <div class="cptai-capture-toolbar"><div class="cptai-content-tabs" role="group" aria-label="Tipo de contenido para capturar">
                    <button type="button" class="cptai-button" data-cptai-content="page" aria-pressed="false">Páginas</button>
                    <button type="button" class="cptai-button" data-cptai-content="post" aria-pressed="false">Entradas</button>
                    <button type="button" class="cptai-button" data-cptai-content="product" aria-pressed="false">Productos</button>
                    <button type="button" class="cptai-button" data-cptai-content="custom" aria-pressed="true">URL personalizada</button>
                </div><button type="button" class="cptai-button cptai-button-primary" id="cptai-read-site">Leer todo el sitio</button></div>
                <div class="cptai-capture-row" id="cptai-content-choice" hidden>
                    <label for="cptai-content-select" class="cptai-capture-label" id="cptai-content-label">Contenido publicado</label>
                    <select id="cptai-content-select" class="cptai-url"></select>
                </div>
                <p id="cptai-content-status" class="cptai-hint" role="status" hidden></p>
                <div class="cptai-capture-row" id="cptai-custom-url">
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
                <div class="cptai-site-reading" id="cptai-site-reading" hidden>
                    <div class="cptai-site-reading-heading"><p id="cptai-site-status" role="status" aria-live="polite"></p><button type="button" class="cptai-button" id="cptai-site-stop" hidden>Detener después de esta página</button></div>
                    <progress id="cptai-site-progress" value="0" max="1" aria-label="Progreso de lectura de todo el sitio"></progress>
                    <ul id="cptai-site-failures" class="cptai-hint" hidden></ul>
                </div>
                <p class="cptai-hint">No se capturan campos personales ni páginas de carrito, checkout o cuenta. Puedes excluir una zona con <code>translate="no"</code> o <code>data-cptai-ignore</code>.</p>
                <p class="cptai-hint" id="cptai-connection-hint" <?php echo $ready ? 'hidden' : ''; ?>>La edición manual está disponible. Configura una conexión y un modelo en <a href="#cptai-settings" class="cptai-settings-link">Ajustes</a> para usar IA.</p>
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
            <div class="cptai-translate-all">
                <button type="button" class="cptai-button cptai-button-primary" id="cptai-all" <?php disabled( ! $ready ); ?>>Traducir todo</button>
                <button type="button" class="cptai-button" id="cptai-all-stop" hidden>Detener después de esta cadena</button>
                <span class="cptai-hint">Traduce y guarda una por una todas las pendientes de este idioma, incluidas las de otras páginas del diccionario. Conserva las traducciones existentes.</span>
                <progress id="cptai-all-progress" max="1" value="0" hidden aria-label="Progreso de traducción del diccionario"></progress>
                <p id="cptai-all-status" role="status" aria-live="polite" hidden></p>
            </div>
            <details class="cptai-manual" id="cptai-manual">
                <summary>Añadir cadena manual</summary>
                <p class="cptai-hint">Añade el mensaje exacto; usa el idioma seleccionado arriba.</p>
                <form id="cptai-add-form">
                    <div class="cptai-manual-grid">
                        <div><label for="cptai-add-original">Texto original</label><input type="text" id="cptai-add-original" name="original" required placeholder="Your message was sent successfully."></div>
                        <div><label for="cptai-add-translated">Traducción</label><input type="text" id="cptai-add-translated" name="translated" placeholder="Tu mensaje se ha enviado correctamente."></div>
                    </div>
                    <div class="cptai-manual-controls"><button type="submit" class="cptai-button cptai-button-primary">Añadir cadena</button></div>
                    <p class="cptai-hint">Sin traducción queda pendiente. Recarga las páginas abiertas para aplicar la nueva cadena.</p>
                    <p id="cptai-add-status" role="status" aria-live="polite"></p>
                </form>
            </details>
            <details class="cptai-manual" id="cptai-image-panel">
                <summary>Añadir imagen</summary>
                <p class="cptai-hint">Sustituye una imagen en el idioma seleccionado arriba. Conserva sus clases, estilos, tamaño y enlace; el original sigue usando su imagen.</p>
                <form id="cptai-image-form">
                    <div class="cptai-manual-grid">
                        <div><label for="cptai-image-original">URL de la imagen original</label><input type="url" id="cptai-image-original" name="original" required placeholder="https://tuweb.com/wp-content/uploads/imagen-original.jpg"></div>
                        <div><label for="cptai-image-replacement">URL de la imagen nueva</label><input type="url" id="cptai-image-replacement" name="replacement" required placeholder="https://tuweb.com/wp-content/uploads/imagen-traducida.jpg"></div>
                    </div>
                    <div class="cptai-manual-controls"><button type="submit" class="cptai-button cptai-button-primary">Añadir imagen</button><button type="button" class="cptai-button" id="cptai-image-cancel" hidden>Cancelar edición</button></div>
                </form>
                <p class="cptai-hint">Copia la URL de archivo de la imagen nueva desde Medios. Las variantes responsive y la carga diferida usarán esa imagen. Recarga las páginas abiertas para ver el cambio.</p>
                <p id="cptai-image-status" role="status" aria-live="polite"></p>
                <div id="cptai-image-list"></div>
            </details>
            <p id="cptai-filter-status" class="cptai-hint" role="status" aria-live="polite"></p>
            <p id="cptai-draft-status" class="cptai-hint" role="status" hidden></p>
            <div id="cptai-dictionary-results">
                <?php require __DIR__ . '/admin-dictionary.php'; ?>
            </div>
            <p class="cptai-hint">Vacía una traducción y guarda para volver al original. Mayúsculas, puntuación y espacios interiores distinguen cadenas.</p>
            <p class="cptai-hint">Los nombres de meses y días de semana se traducen automáticamente en las fechas, sin API. Se conservan las cifras; una corrección manual de fecha debe mantener el día, el año y la hora.</p>
        </section>

        <details class="cptai-settings" id="cptai-settings" <?php echo $target ? '' : 'open'; ?>>
            <summary>Idiomas y conexiones de IA</summary>
            <form id="cptai-settings-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                <input type="hidden" name="action" value="cptai_settings"><?php wp_nonce_field( 'cptai_settings' ); ?>
                <div class="cptai-settings-grid">
                    <section class="cptai-setting-card">
                        <h3>Idiomas</h3>
                        <div class="cptai-setting-field"><label for="cptai-source">Idioma original</label><select id="cptai-source" name="source"><?php foreach ( $languages as $code => $name ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['source'], $code ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?></select></div>
                        <div class="cptai-setting-field"><label>Idiomas de destino</label><div class="cptai-languages"><?php foreach ( $languages as $code => $name ) : ?><label class="cptai-check"><input type="checkbox" name="targets[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $settings['targets'], true ) ); ?>> <?php echo esc_html( $name ); ?></label><?php endforeach; ?></div><p class="cptai-hint">El idioma original usa la URL sin prefijo. Los destinos usan <code>/es/</code>, <code>/fr/</code>, etc.</p></div>
                        <?php if ( ! get_option( 'permalink_structure' ) ) : ?><p class="cptai-hint">Activa enlaces permanentes con nombre de entrada en <a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">Ajustes → Enlaces permanentes</a> para que tu servidor resuelva las carpetas de idioma.</p><?php endif; ?>
                    </section>
                    <section class="cptai-setting-card">
                        <h3>Selector y captura</h3>
                        <div class="cptai-setting-field"><label class="cptai-check"><input type="checkbox" name="footer" value="1" <?php checked( $settings['footer'] ); ?>> Mostrar el selector al pie de las páginas</label><p class="cptai-hint">En Elementor, arrastra <strong>CPT AI · Selector de idiomas</strong>. Recoge los idiomas activados aquí y permite elegir banderas, nombres o iniciales y configurar su estilo.</p></div>
                        <div class="cptai-setting-field"><label class="cptai-check"><input type="checkbox" name="capture_gettext" value="1" <?php checked( $settings['capture_gettext'] ); ?>> Incluir cadenas internas de WordPress y plugins</label><p class="cptai-hint">Captura mensajes localizados aunque no se muestren en la página. Puede añadir muchas cadenas; actívalo si falta un mensaje con marcadores.</p></div>
                        <div class="cptai-setting-field"><label for="cptai-provider">Conexión para las sugerencias de IA</label><select id="cptai-provider" name="provider"><option value="openai" <?php selected( $settings['provider'], 'openai' ); ?>>OpenAI · API</option><option value="claude" <?php selected( $settings['provider'], 'claude' ); ?>>Claude · API</option><option value="chatgpt" <?php selected( $settings['provider'], 'chatgpt' ); ?>>ChatGPT · Mi plan (experimental)</option></select></div>
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
                    <section class="cptai-setting-card" id="cptai-plan-card">
                        <h3>ChatGPT · Mi plan <span class="cptai-version">Experimental</span></h3>
                        <p class="cptai-hint">Vincula esta web a tu cuenta elegible de ChatGPT Plus o Pro. Las peticiones comparten los límites y créditos disponibles de tu plan. No cambia a la API de pago si el plan falla.</p>
                        <ol class="cptai-plan-steps">
                            <li>Descarga el asistente de esta web y extrae el ZIP.</li>
                            <li>Abre <strong>Conectar-ChatGPT.cmd</strong> en Windows. En macOS/Linux, ejecuta <code>node cptai-companion.cjs</code>. Requiere Node.js 22 o posterior.</li>
                            <li>Continúa con ChatGPT en la página oficial de OpenAI y autoriza el uso del plan. Mantén el asistente abierto mientras traduces.</li>
                            <li>Selecciona esta conexión, consulta los modelos y guarda el elegido.</li>
                        </ol>
                        <div class="cptai-plan-actions">
                            <a class="cptai-button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cptai_plan_download' ), 'cptai_plan_download' ) ); ?>" id="cptai-plan-download">Descargar asistente y vincular</a>
                            <button type="button" class="cptai-button" id="cptai-plan-disconnect" <?php disabled( ! $plan_status['linked'] ); ?>>Desvincular esta web</button>
                        </div>
                        <p class="cptai-hint">Una nueva descarga sustituye la vinculación anterior. Guarda el ZIP en privado: incluye acceso limitado a los trabajos de traducción de esta web. Tu sesión de OpenAI permanece en tu ordenador.</p>
                        <p id="cptai-plan-status" class="cptai-plan-state" role="status" aria-live="polite"><?php echo $plan_status['online'] ? 'Asistente conectado' : ( $plan_status['linked'] ? 'Web vinculada · Abre el asistente' : 'Sin vincular' ); ?></p>
                        <div class="cptai-setting-field">
                            <label for="cptai-chatgpt-model">Modelo del plan de ChatGPT</label>
                            <div class="cptai-model-row"><input type="text" id="cptai-chatgpt-model" name="chatgpt_model" value="<?php echo esc_attr( $settings['chatgpt_model'] ); ?>" list="cptai-chatgpt-models" placeholder="Consulta los modelos con el asistente abierto"><button type="button" class="cptai-button cptai-models" data-provider="chatgpt">Consultar modelos</button></div>
                            <datalist id="cptai-chatgpt-models"></datalist>
                            <p class="cptai-hint">La disponibilidad la decide OpenAI. El estado conectado confirma la sesión; una traducción completada confirma acceso al modelo.</p>
                        </div>
                    </section>
                    <section class="cptai-setting-card">
                        <h3>Cómo se usa esta conexión</h3>
                        <p>Solo se envían las cadenas al pulsar <strong>Sugerir con IA</strong> o <strong>Traducir pendientes con IA</strong>. Los textos ya guardados se muestran sin volver a consultar a ChatGPT.</p>
                        <p class="cptai-hint">En cada WordPress descarga su propio asistente. Puedes conectar la cuenta del responsable de esa web. El asistente no recibe tus claves API ni acceso al resto de WordPress.</p>
                        <p class="cptai-hint">Pulsa Ctrl+C en el asistente para cerrar y revocar la sesión de OpenAI. Si cierras la ventana directamente, puedes revocar el acceso en los ajustes de ChatGPT. Desvincular aquí cancela el acceso de este asistente a esta web.</p>
                        <a class="cptai-button" href="https://chatgpt.com/#settings/Usage" target="_blank" rel="noopener noreferrer">Gestionar uso en ChatGPT</a>
                    </section>
                </div>
                <div class="cptai-settings-footer"><button type="submit" class="cptai-button cptai-button-primary">Guardar ajustes</button><p class="cptai-hint">La IA es opcional. La conexión API tiene facturación propia; la conexión al plan comparte su uso y créditos. Las claves API guardadas se conservan al cambiar de conexión.</p></div>
            </form>
        </details>
    </div>
    <div id="cptai-toast" role="status" aria-live="polite" aria-atomic="true" hidden>
        <img class="cptai-toast-icon" src="<?php echo esc_url( $design_url . 'added-120-check-in-circle.svg' ); ?>" alt="" aria-hidden="true">
        <span id="cptai-toast-text"></span>
        <button type="button" id="cptai-toast-close" aria-label="Cerrar aviso"><img src="<?php echo esc_url( $design_url . 'added-122-close.svg' ); ?>" alt="" aria-hidden="true"></button>
    </div>
</div>
