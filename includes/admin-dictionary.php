<?php
defined( 'ABSPATH' ) || exit;
?>
            <p class="cptai-dictionary-count"><strong><?php echo esc_html( $total ); ?></strong> <?php echo 1 === $total ? 'cadena' : 'cadenas'; ?> · Una corrección se aplica a todas las coincidencias exactas para ese idioma.</p>
            <?php if ( $rows ) : ?>
            <div class="cptai-column-headings" aria-hidden="true"><span>Texto original</span><span>Traducción / sustitución global</span></div>
            <div class="cptai-strings">
                <?php foreach ( $rows as $row ) : ?>
                <article class="cptai-string-row" aria-label="<?php echo esc_attr( 'Cadena ' . $row->id ); ?>">
                    <div class="cptai-original">
                        <span class="cptai-field-label">Texto original</span>
                        <div class="cptai-original-text"><?php echo esc_html( $row->original ); ?></div>
                        <p class="cptai-hint">Referencia: <a href="<?php echo esc_url( $row->source_url ); ?>" target="_blank" rel="noopener noreferrer">esta página</a> · <a href="<?php echo esc_url( CPTAI_Routing::url( $row->source_url, $target ) ); ?>" target="_blank" rel="noopener noreferrer">Ver traducción</a></p>
                    </div>
                    <form class="cptai-string-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                        <input type="hidden" name="action" value="cptai_edit"><input type="hidden" name="id" value="<?php echo esc_attr( $row->id ); ?>"><?php wp_nonce_field( 'cptai_edit' ); ?>
                        <span class="cptai-field-label" aria-hidden="true">Traducción</span>
                        <label for="cptai-string-<?php echo esc_attr( $row->id ); ?>" class="screen-reader-text">Traducción de <?php echo esc_html( $row->original ); ?></label>
                        <div class="cptai-textarea-wrap"><textarea id="cptai-string-<?php echo esc_attr( $row->id ); ?>" name="translated" rows="2"><?php echo esc_textarea( $row->translated ); ?></textarea></div>
                        <div class="cptai-row-actions">
                            <button type="submit" class="cptai-button cptai-button-primary" title="Guardar para todas las apariciones">Guardar</button>
                            <button type="button" class="cptai-button cptai-row" data-id="<?php echo esc_attr( $row->id ); ?>" <?php disabled( ! $ready ); ?>>Sugerir con IA</button>
                            <span class="cptai-row-status" aria-live="polite"><?php echo $row->translated ? 'Guardada' : 'Pendiente'; ?></span>
                        </div>
                    </form>
                </article>
                <?php endforeach; ?>
            </div>
            <?php else : ?><p class="cptai-empty">No hay cadenas con estos filtros. Captura una página o cambia el idioma para comenzar.</p><?php endif; ?>
            <?php if ( $total > 30 ) : ?><nav class="cptai-pagination" aria-label="Páginas del diccionario"><?php echo wp_kses_post( paginate_links( array( 'base' => $pagination_base, 'format' => '', 'current' => $page, 'total' => (int) ceil( $total / 30 ) ) ) ); ?></nav><?php endif; ?>
