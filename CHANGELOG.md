# Cambios

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
