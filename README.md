# Creative Pear Translate AI

Plugin independiente de WordPress para detectar cadenas de texto y editarlas desde el backend. Una misma cadena se guarda una sola vez por idioma: cambiar «Añadir al carrito» aplica esa traducción en todas sus coincidencias exactas, también en otras páginas. La traducción con OpenAI o Claude es opcional; no requiere TranslatePress ni utiliza Google Translate.

Creative Pear se desarrolla por separado y conserva sus propios identificadores, opciones, tablas, claves, acciones y shortcode.

## Panel y rutas de idioma (1.1.0)

Panel rediseñado con 12UI, en blanco, gris y azul. El menú de WordPress muestra **CPT AI**. Cada cadena tiene dos columnas iguales en escritorio: original y traducción. En 1024 px o menos se apilan. Los ajustes de idiomas y API usan el mismo sistema visual.

Las traducciones y los ajustes se guardan por AJAX con un aviso flotante, sin recargar. El botón se activa al editar; muestra guardando y solo confirma después de una respuesta correcta. Si hay un error, conserva la edición. Los cambios posteriores al inicio de una petición siguen pendientes. Una edición concurrente desde otra sesión genera un aviso en lugar de sobrescribirla.

En 1.5.0 la captura permite elegir **Páginas**, **Entradas**, **Productos** o **URL personalizada**. Las listas leen el contenido publicado de ese WordPress, sin borradores ni contraseñas; excluyen carrito, checkout y cuenta. Productos se muestra vacío si no hay un tipo de producto activo o no existen productos públicos.

**Leer todo el sitio** (1.6.0) recorre la portada y todas las páginas, entradas y productos publicados, sin contraseña. Usa el destino de Capturar cadenas y procesa una URL por petición AJAX, mostrando progreso, cadenas nuevas y errores. No necesita IA ni consume su cuota. Excluye carrito, checkout y cuenta; revalida la visibilidad antes de leer, no reenvía cookies y valida las redirecciones. Una URL que falla se registra y las demás continúan; un error al guardar pausa la lectura. Puedes detener después de la URL actual y continuar en esta pestaña durante una hora; si cierras, inicia otra lectura y se conservan las cadenas y traducciones existentes. Solo lee HTML público; mensajes posteriores a una interacción requieren captura en navegador o alta manual.
**Traducir todo** usa el idioma del diccionario y recorre todas sus cadenas pendientes, también las de otras páginas de resultados y aunque haya una búsqueda activa. Prepara una lista de IDs en el orden elegido, traduce **una cadena por petición** y guarda cada respuesta antes de avanzar. No necesita volver a leer HTML. El progreso cuenta las procesadas, incluidas las que se omiten porque otro administrador ya las guardó o porque hay una edición pendiente. Puedes detener después de la cadena actual y continuar en esta pestaña; al reabrir el panel, un nuevo inicio encuentra las pendientes restantes. Los errores y los cambios de proveedor/modelo detienen el proceso; lo guardado se conserva. Mantén el asistente abierto si usas el plan. Las claves API y el modo del plan siguen disponibles.

Los destinos usan carpetas de idioma: **`/es/`**, **`/fr/`**, etc. El idioma original usa siempre la URL sin prefijo: con inglés como original, el widget vuelve de `/es/who-we-are` a `/who-we-are`. Los enlaces anteriores `/en/...` y `?cptai_lang=en` redirigen con 301 al original. Una página mantiene su ruta, por ejemplo `/es/servicios/diseno/`. Funcionan portada, páginas anidadas, entradas, categorías y reglas nativas de paginación. Los enlaces internos y el selector conservan el idioma. Activa enlaces permanentes con nombre de entrada en WordPress si todavía usas URLs simples. Las reglas se actualizan al activar/actualizar el plugin o cambiar idiomas/portada.

## Primera instalación

1. Instala **creative-pear-translate-ai.zip** desde **Plugins → Añadir plugin → Subir plugin** y actívalo.
2. Abre **CPT AI → Ajustes**, elige el idioma original y los destinos, y guarda.
3. En **Capturar cadenas de una página**, introduce su URL pública y el destino. **Leer HTML** detecta el HTML generado por WordPress. No necesita clave API.
4. **Captura en navegador** abre la página con una sesión autorizada. Detecta texto, atributos y nuevas cadenas que aparezcan con JavaScript/AJAX; abre los menús o desplegables que quieras capturar. Regresa al backend y pulsa **Filtrar / actualizar**.
5. Busca una cadena, escribe su traducción y pulsa **Guardar**. Se aplica a todas sus apariciones para ese idioma. Para volver al original, vacía la traducción y guarda.
6. En Elementor, arrastra **CPT AI · Selector de idiomas**. También puedes abrir una página de destino en `/es/`, `/fr/`, etc.; el original conserva su URL sin prefijo.

El buscador actualiza el diccionario por AJAX mientras escribes, con una pausa de 250 ms para agrupar pulsaciones. Busca en el original y en la traducción de todas las cadenas del idioma seleccionado. El selector **Ordenar** permite A–Z/Z–A, vacías/traducidas primero, más/menos caracteres del original o de la traducción, primeras/últimas detectadas y primeras/últimas actualizadas. La longitud cuenta caracteres Unicode, no bytes. El orden se aplica antes de dividir las páginas y se combina con búsqueda y **Solo pendientes**.

Los filtros y la paginación funcionan sin recargar. Las ediciones sin guardar se mantienen en esta pestaña al buscar, ordenar o cambiar de página; vuelven a aparecer al mostrar su fila. Se indica cuántas hay y se avisa al salir. Solo **Guardar** las escribe en WordPress; no se conservan al cerrar la pestaña. Los filtros se reflejan en la URL para poder volver a abrirlos. Sin JavaScript, el botón **Filtrar / actualizar** y los enlaces siguen funcionando con recarga.

## Fechas y mensajes de formularios

Una frase que contiene saltos `<br>` dentro del mismo bloque se guarda como una sola cadena. Desde 1.6.3, el editor muestra únicamente el texto normal con sus saltos de línea: los códigos de formato son internos y no hay que escribirlos. La IA recibe la frase completa. Puedes escribir la traducción en una línea o con los saltos del original; se mantienen automáticamente las etiquetas originales, clases, estilos, negritas y enlaces. Si escribes una sola línea, los saltos se distribuyen entre palabras según el original o la traducción anterior. Una sugerencia de IA conserva su distribución de formato al guardarla sin cambios. La búsqueda y el orden por longitud usan el texto visible, sin contar códigos técnicos.

Se aplica a Leer HTML, Leer todo el sitio y Captura en navegador, y a contenido añadido o actualizado por JavaScript. Tras actualizar, usa **Leer todo el sitio** para actualizar las referencias de todas las páginas. Los fragmentos antiguos que ya solo forman parte de una frase completa se ocultan en el diccionario y en Traducir todo; sus traducciones no se borran. Si siguen apareciendo como texto independiente en alguna página leída, se mantienen visibles. La captura en navegador añade referencias sin borrar las de otros mensajes dinámicos. La frase agrupada necesita su propia traducción y no reutiliza traducciones parciales. Los bloques distintos siguen separados. Los textos sin BR conservan sus claves. Los bloques excluidos y los formatos internos sin cerrar se dejan fuera de la agrupación; se limita a 32 saltos, 150 tokens y 3.500 bytes por cadena. En WordPress 6.4–6.6, la captura usa DOM y la traducción conserva el HTML original y se aplica en el navegador.

Los nombres de meses y días de semana se traducen automáticamente entre los 13 idiomas disponibles, sin IA ni guardar una entrada para cada fecha. Se conservan mayúsculas/minúsculas, cifras, separadores y orden de la fecha. Por ejemplo, `MARCH 5, 2026` se muestra como `MARZO 5, 2026`; no se convierte en `MARZO`. También se aplica a fechas añadidas o modificadas por JavaScript. El atributo técnico `datetime` no se modifica.

La detección automática se limita a nombres de calendario aislados y textos con aspecto de fecha; no cambia frases como `May I help you?`. No reformatea el orden día/mes/año, no interpreta fechas numéricas ni cambia zonas horarias. Una traducción manual completa tiene prioridad si conserva las cifras. Las correcciones antiguas que borraban día/año se conservan en el diccionario, pero al mostrar la fecha se usa la traducción automática; las ediciones nuevas que pierdan esos datos se rechazan.

Abre **Añadir cadena manual** en el diccionario, copia el texto original exacto y escribe la traducción en los dos campos de una línea. Toma el idioma seleccionado arriba; no pide una URL ni un segundo selector de idioma. Puedes añadirla sin traducción para dejarla pendiente. Se comprueban los marcadores y el texto plano; si ya existe, se conserva su traducción y debes editar la fila correspondiente.

Esta opción sirve para mensajes de éxito/error que solo aparecen después de enviar un formulario. Al cargarse la página en un idioma de destino, el diccionario incluye las cadenas manuales y traduce el texto cuando se inserta o cambia en el DOM. **Recarga una página ya abierta después de añadir una cadena nueva** para cargar el diccionario actualizado. Los mensajes dentro de iframes, Shadow DOM o imágenes siguen fuera del alcance; copia el texto exacto que aparecerá, conservando puntuación y marcadores. No se alteran los datos enviados por el formulario.

## Imágenes por idioma (1.6.0)

En el diccionario abre **Añadir imagen**, pega la URL original y la URL de archivo de la imagen nueva de **Medios**. Usa el idioma seleccionado en el diccionario; el original y los demás idiomas no cambian. Guarda por AJAX sin IA. La lista permite **Editar** y **Restablecer original**, manteniendo la fila para poder volver a activarla. Se comprueban las URLs y la pertenencia de la nueva imagen a Medios; no se descargan imágenes externas ni se reescribe el contenido de Elementor.

La sustitución conserva clases, estilos, dimensiones HTML, `sizes`, texto alternativo y el enlace que rodea la imagen (el texto alternativo puede traducirse con el diccionario). Reconoce variantes registradas de la imagen original en Medios. Actualiza `src`, `srcset`, `<picture>`, atributos habituales de carga diferida, fondos en línea y fondos `background-image` calculados desde CSS externo. También se aplica a nodos añadidos por JavaScript. Para evitar que el navegador elija una imagen anterior o descriptores de tamaño incorrectos, las variantes usan una sola candidata: la URL nueva. Usa un archivo optimizado y proporciones adecuadas al diseño. Recarga las páginas abiertas después de guardar.

Las URLs coinciden exactamente, incluidos los parámetros de un CDN. Si una variante del original no está registrada en Medios, añade su URL como otra sustitución. No cambia archivos CSS/JS, imágenes dentro de canvas, iframes, Shadow DOM ni fondos de pseudoelementos; tampoco modifica el enlace de una caja de luz. El navegador necesita JavaScript para fondos de CSS externo y cambios dinámicos. Se permiten hasta 500 filas por idioma, sin sustituciones encadenadas.

## Widget de Elementor

Con Elementor activo, busca **CPT AI · Selector de idiomas** en el panel de widgets y arrástralo a la cabecera, pie o contenido. Recoge el idioma original y los destinos activados en CPT AI. El aviso de idiomas del panel se actualiza al volver a abrir el editor después de cambiar los ajustes.

En **Contenido**, elige solo banderas, banderas e iniciales, banderas y nombre completo, solo nombre completo o solo iniciales. Para inglés puedes elegir EE. UU., Reino Unido o Inglaterra; para portugués, Portugal o Brasil. La bandera es una elección visual, no cambia la variante regional del idioma de traducción.

En **Estilo**, ajusta la separación entre idiomas, tamaño y esquinas de las banderas, separación entre bandera y texto y alineación. Los controles de tipografía de Elementor incluyen familia, tamaño, peso, mayúsculas, cursiva, decoración, interlineado y espaciado; hay sombra y colores normal, hover e idioma activo. Separación, tamaño y alineación admiten ajustes responsive. Los enlaces conservan la página actual y la carpeta de idioma; el widget se renderiza como contenido dinámico para no reutilizar enlaces o selecciones de otra página.

El widget usa banderas SVG locales de flag-icons 7.5.0, bajo licencia MIT, incluida en `assets/flags/LICENSE`. No depende de emojis ni de peticiones a un CDN de banderas. La integración es opcional; la traducción funciona sin Elementor. El selector simple al pie y el shortcode anterior se mantienen por compatibilidad con instalaciones existentes; no son necesarios para usar el widget.

## IA opcional

### ChatGPT · Mi plan (experimental, 1.4.0)

La conexión al plan se añade a las conexiones API existentes. El inicio de sesión oficial para clientes abiertos utiliza una recepción local en `127.0.0.1`; por eso esta versión incluye un asistente que corre en el ordenador del administrador. No necesita copiar una clave API ni leer sesiones de Codex/ChatGPT. La elegibilidad del usuario y los modelos la decide OpenAI. **Conectado** significa sesión autorizada y catálogo recibido; solo una traducción completada demuestra acceso al modelo para esa petición.

1. En **CPT AI → Ajustes → ChatGPT · Mi plan**, pulsa **Descargar asistente y vincular**. Cada web descarga un ZIP diferente; una nueva descarga invalida su vinculación anterior. WordPress necesita HTTPS y la extensión PHP Zip, salvo en instalaciones locales HTTP.
2. Extrae el ZIP en una carpeta privada de tu ordenador. Incluye `cptai-connection.json`: no lo compartas. Es una vinculación limitada a recibir/responder trabajos de traducción de esa web, no una sesión de OpenAI ni una cuenta de administrador.
3. Requiere **Node.js 22 o posterior**. En Windows abre **Conectar-ChatGPT.cmd**; detecta Node instalado o el runtime local de Codex. En macOS/Linux ejecuta `node cptai-companion.cjs --launch`. No se instala Node automáticamente.
4. Inicia sesión y autoriza el plan en la página oficial de OpenAI. El asistente comprueba firma, emisor, destinatario, caducidad, nonce y permisos. Si ya hay registros locales, permite elegir una cuenta previa o conectar otra; cada registro conserva su cuenta/espacio de trabajo y su identificador de cliente.
5. Desde 1.7.0 el asistente queda en segundo plano: puedes cerrar la ventana o Codex. En WordPress selecciona **ChatGPT · Mi plan (experimental)** como conexión, consulta los modelos, elige uno y guarda. El ordenador y el proceso deben seguir activos para traducir. Tras reiniciar el equipo, abre de nuevo el asistente para recuperar la sesión protegida.
6. Usa **Sugerir con IA** o **Traducir pendientes con IA**. El primero devuelve una propuesta para revisar y guardar; el segundo guarda únicamente pendientes y conserva las ediciones manuales hechas durante la petición. No hay inferencia por visitas ni por conectar/consultar modelos.
7. **Detener-asistente.cmd** pausa el proceso conservando la sesión; **Cerrar-sesion-ChatGPT.cmd** solicita la revocación y elimina las credenciales locales. En macOS/Linux usa `--stop` o `--logout`. El modo sin `--launch` se mantiene en primer plano y Ctrl+C lo detiene sin borrar su sesión. No se instala un servicio ni inicio automático. **Desvincular esta web** cancela los trabajos y la credencial del asistente de esa web, sin borrar las claves API ni revocar por sí mismo la autorización de OpenAI.

Las traducciones comparten el uso y los créditos disponibles del plan autorizado; no crean una cuota nueva por web. Ante un límite, falta de acceso, modelo incompatible o sesión caducada, se muestra el error y **no se cambia automáticamente a una API de pago**. Puedes elegir manualmente cualquiera de las conexiones API que ya tenías.

Las credenciales OAuth se guardan en `CreativePearTranslateAI` dentro de los datos locales del usuario, separadas de los metadatos públicos de cuenta/cliente/equipo. En Windows se cifran con **DPAPI CurrentUser**; en macOS/Linux se usan archivos privados **0600** dentro de un directorio **0700**. La escritura es atómica y la renovación del token se serializa y guarda antes de seguir. Un canal local privado evita dos asistentes para la misma web y permite detener o cerrar sesión. No se guardan tokens en WordPress, el navegador, el repositorio ni los logs. WordPress conserva únicamente un hash de la vinculación limitada, nombre de cuenta, catálogo y trabajos temporales. Las respuestas deben preservar marcadores, cifras de fechas y texto plano.

En 1.4.1 el asistente espera hasta 15 minutos para recibir la autorización y lee el catálogo específico del plan (`models`/`slug`), con su orden y un límite de tamaño suficiente. En 1.4.2 recoge las partes del texto de los eventos delta/done y confirma el evento final, aunque este no repita el texto. Se verificó una traducción real de `Send message` a `Enviar mensaje` con el plan autorizado y `gpt-6.1-sol`, sin usar una clave API.

Los cortes de red y las respuestas de mantenimiento de WordPress provocan reintentos a los 2, 4, 8, 16 y después 30 segundos. Desde 1.7.0 un cierre del proceso tampoco revoca ni borra su sesión protegida: se recupera al abrirlo. Los fallos temporales al renovar mantienen las credenciales; una revocación o caducidad definitiva confirmada requiere autorizar de nuevo. Una actualización normal del plugin conserva la vinculación y el modelo. Durante un corte largo, los botones pueden desactivarse temporalmente y se habilitan al recibir el siguiente estado. Las entregas fallidas reutilizan la respuesta ya generada, sin repetir inferencia. Si el trabajo caducó se descarta la entrega y se sigue escuchando. Credenciales revocadas y respuestas 400/401/403 de WordPress no se reintentan.

**Actualizar asistente** descarga solo los archivos públicos: detén el proceso, reemplázalos y vuelve a abrirlo, conservando `cptai-connection.json` y la sesión local. **Descargar asistente y vincular** sigue creando una vinculación nueva y sustituyendo la anterior; úsalo para conectar una web, no para actualizar. La primera migración desde las versiones que guardaban los tokens solo en memoria exige una autorización si ese proceso ya se cerró.

El asistente escucha el inicio de sesión exclusivamente en un puerto temporal de `127.0.0.1`. Luego consulta WordPress por HTTPS. La cola se reclama una sola vez, expira y vincula resultados al administrador, idioma y conexión original; las entregas repetidas no generan otra traducción ni avanzan dos veces un lote. Heartbeats mantienen el estado mientras se procesa un lote. El asistente usa Responses con streaming y confirma `response.completed` antes de aceptar el resultado.

Documentación oficial: [registro y autorización](https://developers.openai.com/siwc/token-sharing-open-source/sign-in), [modelos e inferencia](https://developers.openai.com/siwc/token-sharing-open-source/models-and-inference), [límites actuales](https://developers.openai.com/siwc/token-sharing-open-source/preview-limitations).

### Claves API de OpenAI y Claude

Introduce una clave de OpenAI o Anthropic en WordPress, guarda y pulsa **Consultar modelos**. Selecciona un modelo con generación de texto y guarda de nuevo. No se usan credenciales de ChatGPT/Claude en el navegador: son las APIs de cada proveedor, con su propia cuenta y facturación.

**Sugerir con IA** prepara una propuesta para una sola cadena; revísala y guarda. **Traducir pendientes con IA** procesa las cadenas sin traducción de la última página leída, en lotes de hasta diez. No sobrescribe las traducciones existentes. Puedes detenerlo entre lotes; lo ya guardado se conserva. Un fallo no guarda la respuesta inválida y permite reintentar. Si la sesión caduca, vuelve a leer la página para recuperar los pendientes.

Los errores de API se muestran sin exponer claves ni respuestas completas. Los resultados deben ser un array JSON completo, sin HTML y con los marcadores conservados. No hay llamadas de IA por visitas ni traducción automática que genere gasto sin pulsar un botón.

## Detección y alcance

- Textos del HTML, títulos de pestaña, etiquetas `alt`, `title`, `placeholder`, `aria-label`, botones `input` y metadescripciones. La captura recoge nuevos nodos/atributos tras JavaScript o AJAX. La opción avanzada **Incluir también cadenas internas** captura además mensajes localizados de WordPress/plugins, incluso si no están renderizados; puede añadir muchas filas. Las traducciones de mensajes PHP se aplican también mediante los filtros gettext y plurales.
- Diccionario compartido por idioma original + destino + hash del texto exacto. Ignora espacios al principio y al final; mayúsculas, puntuación y espacios interiores distinguen cadenas. Un texto actualizado queda pendiente, sin heredar una traducción antigua.
- Traducción del HTML con el procesador léxico de WordPress 6.7+, que modifica solo textos y atributos sin reconstruir ni reparar el árbol. Conserva el HTML de Elementor, SVG, scripts, JSON y estilos; una etiqueta original sin cerrar no puede desplazar otros bloques por una serialización de libxml. En WordPress 6.4–6.6 el HTML se conserva y la traducción inicial se aplica en el navegador. Los cambios posteriores se traducen en el DOM. Los originales de entradas, páginas y productos no se reescriben ni duplican.
- Los enlaces internos de navegación conservan el destino; se excluyen enlaces administrativos, archivos, acciones de carrito y otros enlaces con parámetros ajenos a la navegación.
- Se omiten scripts, estilos, código, SVG, plantillas sin renderizar, campos de usuario y zonas con `translate="no"`, `.notranslate` o `data-cptai-ignore`. Los textos dentro de imágenes, canvas, iframes o Shadow DOM no se detectan en esta versión.
- La lectura HTML obtiene la versión pública anónima, sin reenviar cookies ni autorizaciones. La captura en navegador requiere un administrador; úsala en páginas públicas. No captures contenidos personales ni zonas privadas. Se excluyen carrito, checkout y cuenta de WooCommerce.
- La lectura limita cada documento a 2 MB y cada cadena a 3.500 bytes. Las zonas dinámicas necesitan abrirse durante la captura; no se extraen mensajes que todavía no se hayan renderizado.
- La misma cadena usa una sola traducción por idioma, incluso si aparece en contextos con significados diferentes. Esta versión no incorpora excepciones por contexto.
- Las variantes traducidas usan carpetas de idioma y conservan `noindex` hasta incorporar una configuración de SEO multilingüe. **No incluye slugs traducidos, sitemaps multilingües ni un editor visual equivalente al de TranslatePress.** Las carpetas de los idiomas configurados quedan reservadas para las variantes.
- Configura tu caché/CDN para diferenciar las carpetas de idioma. El plugin desactiva la caché de WordPress cuando procesa una variante, pero no controla capas que responden antes de cargar WordPress.

## Claves y datos

Las claves se cifran con AES-256-GCM usando las sales de WordPress y se guardan en una opción sin autoload. Nunca se devuelven a los campos del navegador. Cambiar las sales requiere introducirlas otra vez. El cifrado no protege frente a un administrador con acceso simultáneo a la base de datos y a `wp-config.php`.

Opcionalmente puedes definir `CPTAI_OPENAI_API_KEY` y `CPTAI_CLAUDE_API_KEY` en `wp-config.php`; esas constantes tienen prioridad sobre las claves guardadas. La casilla «Borrar clave guardada» solo elimina la copia de la base de datos.

La tabla `{prefijo}cptai_strings` guarda original, traducción, idiomas y primera/última URL de captura. Las cadenas publicadas del diccionario se incluyen en páginas traducidas para aplicar cambios dinámicos. La tabla `{prefijo}cptai_images` guarda las sustituciones por idioma. Las traducciones, imágenes y ajustes se conservan al desactivar o desinstalar el plugin, para evitar perder el trabajo.

## Requisitos y desarrollo

WordPress 6.4+, PHP 8.1+ con DOM, OpenSSL y mbstring. Para IA, conexiones HTTPS salientes a `api.openai.com` o `api.anthropic.com`.

Pruebas sin servicios externos: `php tests/run.php`, `php tests/calendar.php` y `node tests/calendar.cjs`. Pruebas en WordPress aislado: `php tests/wordpress.php /ruta/a/wordpress`, `php tests/ajax.php /ruta/a/wordpress`, `php tests/routing.php /ruta/a/wordpress`, `php tests/dictionary.php /ruta/a/wordpress` y `php tests/elementor.php /ruta/a/wordpress` (Elementor debe estar activo); el entorno debe ser `local`. `tests/coexistence.php` comprueba identificadores independientes con XLeon instalado en ese entorno. Los fixtures usan respuestas simuladas y no llaman a APIs de IA. `tests/scanner.php` comprueba lectura completa, exclusiones, redirecciones y reintentos; `tests/images.php` valida medios, variantes, conservación de estilos y restauración. Las pruebas y herramientas de desarrollo se excluyen del ZIP.

Se consultaron las interfaces oficiales: [OpenAI Responses](https://developers.openai.com/api/docs/guides/text) y [Anthropic Messages](https://platform.claude.com/docs/en/api/messages/create).

El ZIP TranslatePress 3.3.8 facilitado por el usuario se revisó como referencia de alcance; este repositorio contiene una implementación propia, sin código ni recursos copiados de ese ZIP.

## Publicación

Las etiquetas `v*` validan PHP y JavaScript, ejecutan las pruebas y generan `creative-pear-translate-ai.zip` como archivo instalable de GitHub Releases. Instala ese archivo, no el ZIP automático del código fuente. Versión actual: **1.7.2**. La actualización conserva las traducciones y claves anteriores.

Desde 1.6.1 se consulta GitHub Releases cada **5 minutos** con un evento propio de WP-Cron. También se comprueba al abrir la administración, reutilizando la misma caché de cinco minutos, sin peticiones por cada visita pública. Usa HTTPS sin claves ni cookies, consultas condicionales con ETag y un bloqueo breve para evitar consultas simultáneas. Los errores se reintentan en el siguiente intervalo; los metadatos verificados pueden mantenerse hasta 24 horas durante un corte. Solo acepta versiones estables `vX.Y.Z` y el archivo `creative-pear-translate-ai.zip` de este repositorio; el ZIP automático del código fuente no se usa.

Las novedades aparecen en **Plugins → Plugins instalados → Creative Pear Translate AI → Actualizar ahora**. **Activar actualizaciones automáticas** usa la preferencia nativa de esa instalación; el plugin no la cambia ni fuerza instalaciones. La consulta cada cinco minutos y el momento de instalación automática son distintos: WordPress decide cuándo ejecutar sus actualizaciones. WP-Cron depende de las visitas o de un cron del servidor que llame a WordPress; sin tráfico ni cron externo no garantiza una consulta puntual. La desactivación elimina únicamente el evento de CPT AI.

`php tests/updater.php /ruta/a/wordpress` comprueba cron, caché, fallos, versiones, paquetes y actualizaciones nativas en un WordPress aislado, con respuestas simuladas y sin descargar código remoto.

La versión 1.1.1 corrige rutas de idioma que devolvían 404 al faltar sus reglas guardadas. Registra las reglas en la activación, las regenera una vez al actualizar y proporciona las rutas durante la resolución de una URL de idioma aunque otro proceso haya eliminado las reglas del plugin. No regenera ni escribe reglas en cada visita. Después de actualizar, elimina los 404 guardados en la caché del sitio/CDN. Si persiste un error, guarda una vez Ajustes → Enlaces permanentes y verifica que las peticiones lleguen a WordPress. La indexación multilingüe sigue pendiente; esta corrección de rutas no habilita el SEO de las variantes traducidas.
