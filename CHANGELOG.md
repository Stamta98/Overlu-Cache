# Cambios

## 0.2.1

- Las hojas originales se dejaban de encolar pero se imprimían igual: la página
  cargaba el paquete **y** las 23 hojas. `wp_print_styles()` imprime `to_do`, no
  la cola, y resolver el orden es lo que rellena `to_do` (trampa 3.19).

## 0.2.0 — Módulo de CSS

- Nuevo módulo **CSS**, apagado por defecto: minifica y combina las hojas
  locales en un archivo por tipo de medio, respetando el orden de dependencias
  que WordPress ya había resuelto.
- El CSS en línea de cada hoja (`wp_add_inline_style`) se conserva y se vuelve a
  imprimir en su sitio, en vez de desaparecer con la hoja (trampa 3.15).
- Se detectan y se eliminan las hojas duplicadas: el mismo archivo encolado dos
  veces con handles distintos deja de pedirse dos veces.
- Minificador propio escrito como escáner, no como expresión regular: respeta
  `calc()`, las cadenas, los `data:` URI y los comentarios de licencia. 26
  pruebas en `tests/test-css.php`, sin WordPress (trampa 3.17).
- Las rutas relativas de `url()` se reescriben a absolutas al combinar.
- Carga sin bloquear el dibujado, solo en las páginas con CSS crítico escrito.
- CSS crítico por tipo de página: portada, catálogo, ficha, contenido y resto.
- Los archivos generados se conservan siete días para no dejar sin estilos a las
  páginas ya guardadas en caché (trampa 3.16).
- El módulo declara su propia sección de ajustes: el panel le crea la pestaña y
  el formulario sin tocar el núcleo, que era el objetivo del diseño.

## 0.1.2

- Cambiar un ajuste desde el código ya no apaga los demás interruptores de su
  sección. Encender la caché dejaba apagadas la compresión y la firma del HTML
  sin decir nada (trampa 3.14).
- Las purgas de diseño de Bricks se registran en `after_setup_theme`: al
  arrancar el plugin el tema todavía no existe, así que nunca llegaban a
  conectarse (trampa 3.13).

## 0.1.1

- Las fichas de producto vuelven a cachearse: la cookie
  `woocommerce_recently_viewed`, que WooCommerce manda en todas ellas, ya no
  cuenta como motivo para no guardar la página (trampa 3.12).
- La configuración que lee el archivo servidor se regenera al activar o
  desactivar un plugin, al cambiar de tema y al mover las páginas de
  WooCommerce, para que sus exclusiones no se queden viejas.

## 0.1.0 — Base

Primera versión: las columnas del plugin.

- Contenedor de servicios y sistema de módulos, para que cada optimización
  futura se enchufe sin tocar el núcleo.
- Ajustes declarados en un único esquema, del que salen los valores por
  defecto, el saneo y el formulario del panel.
- Caché de página en disco: `advanced-cache.php` propio, copia comprimida,
  `304 Not Modified`, variante opcional para móvil y cabeceras de diagnóstico.
- Reglas de exclusión compartidas entre el archivo servidor y el plugin, para
  que ambos extremos decidan igual.
- Exclusiones automáticas de WooCommerce: carrito, caja, cuenta, sesión,
  carrito con productos y avisos pendientes.
- Purga automática por contenido, stock, precio, pedidos pagados, valoraciones,
  ajustes de WooCommerce y regeneración de CSS de Bricks, con cola por petición.
- Compatibilidad con Bricks Ecommerce: las cookies de lista de deseos,
  comparador y vistos recientemente desactivan la caché mientras existen.
- Panel en español: estado con comprobaciones, ajustes, registro y herramientas.
- Accesos en la barra superior para vaciar todo o solo la página actual.
- Registro con rotación y limpieza horaria de copias caducadas.
- Desactivar el plugin deja el sitio como estaba: sin archivo, sin constante,
  sin páginas guardadas.
