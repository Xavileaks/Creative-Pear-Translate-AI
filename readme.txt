=== Creative Pear Translate AI ===
Contributors: xavileaks
Tags: translation, multilingual, openai, claude
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 1.6.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Diccionario compartido de cadenas con edición desde WordPress, APIs opcionales de OpenAI/Claude y conexión experimental al plan de ChatGPT mediante un asistente local.

== Installation ==
1. Instala creative-pear-translate-ai.zip desde Plugins > Añadir plugin > Subir plugin.
2. Activa el plugin y abre CPT AI > Ajustes.
3. Guarda el idioma original y los destinos.
4. Lee el HTML de una URL pública o abre la captura en navegador.
5. Filtra el diccionario, edita una traducción y guarda por AJAX: se aplica a todas las coincidencias exactas para ese idioma y muestra un aviso flotante.
6. En Elementor, arrastra CPT AI · Selector de idiomas. Elige su presentación y estilo; recoge automáticamente los idiomas activos de CPT AI.
7. Añadir cadena manual permite registrar mensajes de éxito/error antes de que aparezcan en un formulario. Recarga las páginas abiertas después de guardar una cadena nueva.

== External services ==
Las conexiones de IA son opcionales y se realizan solo al pulsar Consultar modelos, Sugerir con IA, Traducir pendientes con IA o Traducir todo. Se envía la clave API únicamente al proveedor correspondiente; al traducir se envían las cadenas, idiomas y modelo. No se envía el HTML completo, cookies, contraseñas ni información de pedidos. Las visitas normales y la captura no llaman a APIs de IA. Cada proveedor puede aplicar cargos y sus propias condiciones.

ChatGPT - Mi plan (experimental): un asistente local de código abierto completa OAuth en auth.openai.com, valida identidad y permisos, consulta modelos y procesa las traducciones solicitadas desde WordPress. Requiere Node.js 22+ y una cuenta elegible autorizada por OpenAI. Debe permanecer abierto mientras se traduce. Los tokens de OpenAI se mantienen solo en memoria en el ordenador; WordPress recibe únicamente el nombre de cuenta, catálogo de modelos y respuestas de los trabajos solicitados. El plan comparte sus límites y créditos. No cambia automáticamente a una API de pago. Las dos conexiones API se conservan.

Descarga el asistente desde CPT AI > Ajustes > ChatGPT - Mi plan, extrae el ZIP y abre Conectar-ChatGPT.cmd (Windows) o ejecuta node cptai-companion.cjs (macOS/Linux). Cada descarga incluye una vinculación privada limitada a los trabajos de esta web y revoca la vinculación anterior. La web debe usar HTTPS salvo en localhost. Ctrl+C intenta revocar la sesión de OpenAI; si cierras la ventana directamente, revócala en ChatGPT. Desvincular en WordPress cancela solo el acceso del asistente a esta web.

OpenAI OAuth: https://auth.openai.com
Documentación: https://developers.openai.com/siwc/token-sharing-open-source

OpenAI: https://api.openai.com/v1/responses y https://api.openai.com/v1/models
Condiciones: https://openai.com/policies/terms-of-use/
Privacidad: https://openai.com/policies/privacy-policy/
Anthropic: https://api.anthropic.com/v1/messages y https://api.anthropic.com/v1/models
Condiciones: https://www.anthropic.com/legal/commercial-terms
Privacidad: https://www.anthropic.com/legal/privacy

== Changelog ==

= 1.6.5 =
* Normaliza los saltos enviados por formularios para guardar repetidamente una frase sin falsos conflictos de edición.

= 1.6.4 =
* La limpieza de fragmentos conserva los mensajes independientes añadidos manualmente o detectados tras una interacción en navegador.

= 1.6.3 =
* El diccionario muestra frases normales, sin códigos internos, también al sugerir con IA y guardar.
* Los saltos y el formato se conservan automáticamente al editar; la búsqueda y el orden usan el texto visible.
* Al releer las páginas se ocultan los fragmentos antiguos sustituidos por frases completas, conservando sus traducciones y sus usos independientes.

= 1.6.2 =
* Las frases separadas con BR se capturan y traducen como una sola cadena, conservando los saltos originales.
* Marcadores numerados protegen los saltos y el formato de spans, negritas y enlaces, sin reconstruir HTML.
* La captura en navegador y los textos añadidos o modificados por JavaScript usan la misma agrupación.
* Las traducciones completas tienen prioridad; los fragmentos antiguos se conservan para sus usos independientes.

= 1.6.1 =
* Consulta GitHub Releases cada 5 minutos mediante WP-Cron, con caché compartida y peticiones condicionales.
* Integración con Actualizar ahora y la opción nativa de actualizaciones automáticas de WordPress.
* Solo ofrece versiones estables con el ZIP instalable del repositorio correcto; conserva las preferencias de cada web.

= 1.6.0 =
* Leer todo el sitio recorre portada, páginas, entradas y productos públicos, una URL por petición, con progreso y detener/continuar.
* Conserva las traducciones existentes y continúa si una URL falla, sin usar IA.
* Añadir imagen permite sustituir imágenes por idioma usando URLs de Medios, editar y restablecer la original por AJAX.
* Conserva clases y estilos; actualiza imágenes responsive, lazy loading y fondos CSS en el navegador.

= 1.5.1 =
* El asistente conserva la sesión y reintenta con pausa progresiva ante cortes de conexión o mantenimiento de WordPress.
* Las entregas se reintentan con el mismo resultado, sin repetir la inferencia ni su consumo.
* Una vinculación revocada detiene la conexión; un trabajo caducado permite seguir escuchando nuevos trabajos.

= 1.5.0 =
* Sustitución de textos sin reconstruir el HTML: conserva contenedores, etiquetas de Elementor, SVG, scripts y estilos.
* El idioma original usa sus URLs sin prefijo; las variantes antiguas del original redirigen con 301.
* Traducir todo procesa las pendientes del diccionario una por una, guarda cada resultado y permite detener/continuar.
* Alta manual con dos campos de una línea y el idioma del diccionario, sin URL ni selector redundante.
* Captura por Páginas, Entradas, Productos o URL personalizada; listas de contenido publicado sin contraseña.
* Actualización inmediata de los botones de IA al guardar la conexión.

= 1.4.2 =
* Se recoge el texto de los eventos delta/done de Responses, también cuando el evento final no lo repite.
* Se aceptan respuestas completas en un bloque JSON, manteniendo la validación del lote y sus marcadores.
* Se facilita volver a iniciar sesión con la cuenta previamente seleccionada.

= 1.4.1 =
* El asistente espera 15 minutos para completar el inicio de sesión en OpenAI y lo indica antes de abrir el navegador.
* Se rechaza una devolución de autorización ya consumida.
* Se lee el catálogo específico del plan (models/slug), incluidos catálogos grandes, y se conserva el orden indicado por OpenAI.

= 1.4.0 =
* Conexión experimental al plan de ChatGPT mediante un asistente local de código abierto, con OAuth oficial, PKCE y validación de identidad.
* Cada web se vincula de forma independiente; las peticiones se resuelven por AJAX sin bloquear WordPress ni enviar tokens de OpenAI al servidor.
* Se mantienen las conexiones por API de OpenAI y Claude. Sin cambio automático a facturación API cuando el plan falla.
= 1.3.0 =
* Meses y días de semana automáticos, mayúsculas/minúsculas y fechas dinámicas, sin llamadas a IA.
* Conservación de día/año/hora y protección frente a correcciones antiguas que eliminaban cifras.
* Alta manual por AJAX de mensajes aún no detectados, sin sobrescribir cadenas existentes.
* Widget nativo Elementor: cinco presentaciones, banderas EE. UU./Reino Unido/Inglaterra y Portugal/Brasil, tamaños, separación, alineación y tipografía completa.
= 1.2.0 =
* Búsqueda en vivo por AJAX sobre originales y traducciones de todo el diccionario.
* Doce órdenes: alfabético, vacías/traducidas, longitud del original/traducción, detección y actualización.
* Orden, idioma y pendientes combinables; paginación sin recarga y conservación de ediciones sin guardar.
= 1.1.1 =
* Corrección de 404 en /es/, /en/ y otras carpetas cuando faltan las reglas guardadas de idioma.
* Registro de rutas al activar y regeneración de reglas al actualizar, sin vaciar la caché en cada visita.
= 1.1.0 =
* Panel rediseñado con 12UI en gris, blanco y azul. Menú CPT AI.
* Original y traducción en dos columnas iguales de escritorio, una en tablet/móvil.
* Guardado AJAX de cadenas y ajustes con aviso flotante, protección de ediciones y conflictos concurrentes.
* Carpetas /es/, /en/, etc., navegación interna y migración de URLs antiguas.
= 1.0.0 =
* Primera versión: captura HTML y dinámica, diccionario compartido, edición manual, selector de idiomas y conectores OpenAI / Claude.
