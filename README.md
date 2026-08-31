# Bricks Cache

Caché de página y optimización para **overlu.com**: una tienda WooCommerce
construida con el tema **Bricks**, los elementos de **Bricks Ecommerce** y
**Overlu Marketplace**.

Esta versión es la **base**: las columnas sobre las que se apoyará el resto
(CSS crítico, carga diferida de scripts, imágenes, precarga). No intenta
optimizarlo todo todavía; intenta que lo que hay sea correcto, medible y
seguro de encender en una tienda que está vendiendo.

> El código, los comentarios y el registro están en inglés. Todo lo que ve una
> persona —panel, avisos, textos— está en español.

---

## Qué hace hoy

**Caché de página en disco.** El HTML terminado se guarda en
`wp-content/cache/bricks-cache/` y se sirve desde `advanced-cache.php`, antes
de que WordPress arranque: una visita repetida no carga plugins, ni tema, ni
base de datos. Se guarda además una copia comprimida, y se responden `304 Not
Modified` cuando el navegador ya tiene la página.

**Reglas de tienda.** Carrito, caja, cuenta y cualquier visita con sesión de
WooCommerce, carrito con productos, aviso pendiente o sesión iniciada quedan
fuera de la caché. Sin configurarlo: las rutas se leen de los propios ajustes
de WooCommerce, así que funcionan aunque las páginas estén traducidas.

**Purga automática.** Al guardar contenido, al cambiar stock o precio, al
pagarse un pedido, al aprobarse una valoración, al tocar ajustes de
WooCommerce y cuando Bricks regenera su CSS. Las purgas de una misma petición
se agrupan y se ejecutan una sola vez.

**Panel en español** con estado, comprobaciones de salud, ajustes, registro y
herramientas manuales; y accesos directos en la barra superior para vaciar todo
o solo la página que se está viendo.

## Qué no hace todavía

Está previsto, en este orden:

1. CSS: minificar, combinar y generar CSS crítico por plantilla de Bricks.
2. Eliminar el CSS que una página no usa (Bricks carga bastante de más).
3. Scripts: diferir, retrasar hasta la primera interacción, excluir lo crítico.
4. Imágenes: `loading`, `fetchpriority`, dimensiones, WebP/AVIF.
5. Precarga: recorrer el sitemap y calentar la caché tras una purga.
6. Caché de objetos persistente, cuando el servidor tenga Redis o APCu.
7. Contadores de lista de deseos y comparador servidos por JavaScript, para que
   dejen de desactivar la caché.

## Instalación

1. Copia la carpeta en `wp-content/plugins/bricks-cache/`.
2. Actívalo. **Al activarse no cachea nada todavía**: prepara las carpetas y
   deja el panel listo.
3. Entra en **Bricks Cache → Estado** y revisa las comprobaciones.
4. Cuando estés listo, ve a **Caché de página** y activa la caché. En ese
   momento se instala `wp-content/advanced-cache.php` y se añade
   `define( 'WP_CACHE', true );` a `wp-config.php`.

Al desactivar el plugin se deshace todo: se borra el archivo, la constante, las
páginas guardadas y la tarea programada.

## Requisitos

- WordPress 6.4 o superior, PHP 8.0 o superior.
- Enlaces permanentes distintos de «simple».
- Ningún otro plugin de caché de página activo.

## Comprobar que funciona

```
curl -sI https://overlu.com/ | grep -i x-bricks-cache
```

- `X-Bricks-Cache: MISS` — WordPress ha generado la página y la ha guardado.
- `X-Bricks-Cache: HIT` — servida desde disco sin arrancar WordPress.
- `X-Bricks-Cache: BYPASS` — no se cachea; la cabecera
  `X-Bricks-Cache-Reason` dice por qué.

Al final del HTML guardado hay un comentario con la fecha en que se generó.

## Pruebas

Las reglas que deciden qué se cachea y dónde se guarda no dependen de
WordPress, así que se pueden probar sin levantar nada:

```
php tests/test-pure-rules.php
```

## Estructura

```
bricks-cache.php          Arranque: constantes, autocarga, ciclo de vida.
dropin/advanced-cache.php Plantilla del archivo que sirve las páginas.
includes/
  class-plugin.php        Contenedor. Construye los servicios y arranca módulos.
  class-settings.php      Esquema único: valores por defecto, saneo y formulario.
  class-key.php           Dónde vive cada página en disco. Sin WordPress.
  class-bypass.php        Qué no se cachea nunca. Sin WordPress.
  class-rules.php         Lo que solo se puede decidir con WordPress cargado.
  class-purge.php         Invalidación y cola de purgas.
  class-config.php        Genera el archivo de reglas que lee el dropin.
  class-dropin.php        Instala, verifica y retira advanced-cache.php.
  class-filesystem.php    Único punto de escritura en disco.
  class-logger.php        Registro con rotación.
  class-diagnostics.php   Comprobaciones del panel de estado.
  store/                  Almacenamiento intercambiable (hoy: disco).
  modules/                Una optimización por módulo (hoy: caché de página).
  compat/                 WooCommerce, Bricks, Bricks Ecommerce.
  admin/                  Panel y barra superior.
tests/                    Pruebas de las reglas puras.
```

Antes de tocar el código, lee **[ARQUITECTURA.md](ARQUITECTURA.md)**: la
segunda parte son las trampas de poner una caché delante de una tienda.
