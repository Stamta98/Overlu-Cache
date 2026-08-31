# Bricks Cache — cómo está montado

Guía para trabajar en el plugin. La segunda parte, **Trampas**, es la
importante: cada una es un fallo que una caché delante de una tienda comete si
nadie lo impide, con lo que cuesta y cómo se evita aquí. Léela una vez antes de
tocar nada.

---

## 1. El mapa

```
bricks-cache.php          Arranque. Constantes, autocarga, ciclo de vida. Nada más.
dropin/advanced-cache.php Plantilla del archivo que WordPress copia a wp-content.
includes/
  class-plugin.php        Contenedor: construye los servicios en orden y arranca módulos.
  class-lifecycle.php     Activar y desactivar. Desactivar deja el sitio como estaba.
  class-settings.php      Esquema único de ajustes.
  class-config.php        Vuelca los ajustes a un PHP plano que lee el dropin.
  class-dropin.php        Instala, verifica y retira advanced-cache.php y WP_CACHE.
  class-key.php           Dónde vive cada página. Sin WordPress.
  class-bypass.php        Qué no se cachea nunca. Sin WordPress.
  class-rules.php         Lo que solo se sabe con WordPress cargado.
  class-purge.php         Invalidación, con cola por petición.
  class-filesystem.php    Único punto de escritura en disco.
  class-logger.php        Registro con rotación.
  class-diagnostics.php   Comprobaciones del panel de estado.
  store/                  Backend intercambiable. Hoy solo disco.
  modules/                Una optimización = un módulo.
  compat/                 WooCommerce, Bricks, Bricks Ecommerce.
  admin/                  Panel y barra superior.
tests/test-pure-rules.php Pruebas de Key y Bypass, sin WordPress.
```

**Las dos mitades.** El *dropin* lee: corre antes que WordPress y sirve la
página guardada. El *módulo* escribe: corre dentro de WordPress y guarda el
HTML terminado. Las dos mitades comparten `Key` (dónde se guarda) y `Bypass`
(qué no se guarda). Todo lo demás es de una mitad o de la otra.

## 2. Añadir un módulo

1. Crea `includes/modules/mi-modulo.php` con una clase que extienda `Module`.
2. Devuelve en `id()` la clave de su sección de ajustes.
3. Declara esa sección con el filtro `bricks_cache_settings_schema`: el panel
   le crea la pestaña y el formulario solos.
4. Regístralo en `Plugin::register_modules()` o con el filtro
   `bricks_cache_modules`.

Solo se arrancan los módulos cuyo ajuste `<id>.enabled` está activo.

## 3. Trampas

### 3.1 El dropin no tiene WordPress

`advanced-cache.php` se incluye desde `wp-settings.php`: no hay plugins, no hay
tema, no hay base de datos y **no hay funciones de WordPress**. Una sola llamada
a `home_url()` o a `get_option()` allí es un error fatal en cada visita.

**Regla:** `Key` y `Bypass` son PHP puro y se prueban con
`php tests/test-pure-rules.php`, que corre fuera de WordPress justo para que
esa dependencia no pueda colarse. Lo que el dropin necesita saber del sitio se
vuelca antes en `cache/bricks-cache/config/config.php`.

### 3.2 Las dos mitades tienen que calcular la misma ruta

Si el dropin busca en una ruta y el plugin guarda en otra, todas las visitas
son fallo de caché **y nada en el panel parece roto**: las páginas se guardan,
el disco crece y el sitio va igual de lento.

**Regla:** una sola implementación, `Key::file_base()`, usada por los dos
extremos. Nadie más construye rutas de caché.

### 3.3 Los nonces caducan a las 12 horas

El HTML guardado incluye nonces —Bricks Ecommerce mete el suyo en
`BricksEcomATC.nonce`—. Un nonce de visitante anónimo vive 24 horas y se
renueva a las 12. Si una página lleva guardada más tiempo, el nonce que sirve
ya no vale y **el botón de añadir al carrito deja de funcionar** solo en las
páginas antiguas: el fallo más difícil de reproducir que puede tener esta
tienda.

**Regla:** la duración por defecto son 12 horas y el panel avisa si se sube.
La solución definitiva es renovar el nonce por JavaScript, y está en la lista.

### 3.4 Escribir en la sesión de WooCommerce apaga la caché entera

`WC()->session->set()` marca la sesión como sucia, WooCommerce manda su cookie,
y esa cookie hace que la página no se pueda guardar. Un `set()` en la portada
—el mismo fallo que está documentado en la trampa 3.5 de Bricks Ecommerce— no
deja sin caché a esa página: deja sin caché **a toda la tienda para todos los
visitantes nuevos**.

**Regla:** `Rules::response_reason()` rechaza cualquier respuesta con una
sesión abierta o con un `Set-Cookie` que no esté en la lista de inofensivas. Si
el ratio de aciertos cae de golpe, esto es lo primero que hay que mirar: el
registro dice `woocommerce_session`.

### 3.5 Una cookie de estado convierte una página en personal

El contador de la lista de deseos, el comparador y los vistos recientemente se
pintan en PHP leyendo una cookie. Guardar esa página y servírsela al siguiente
visitante le enseña la lista de otro.

**Regla:** mientras esas cookies existan, no se cachea. Es estrecho a
propósito: la cookie solo aparece cuando el visitante usa la función, así que
el que llega por primera vez —la mayoría, y el que mide la velocidad— sí recibe
página guardada. El arreglo bueno es pintar esos contadores por JavaScript.

### 3.6 Un parámetro desconocido puede cambiar la página

`?color=rojo` puede ser un filtro que cambia el listado entero. Si no está
declarado, la clave no lo incluye y dos páginas distintas comparten copia.

**Regla:** fallar cerrado. Un parámetro que no esté ni en los ignorados ni en
los que generan copia propia desactiva la caché en esa visita. Añadir un filtro
nuevo al catálogo significa añadirlo también a los ajustes.

### 3.7 Dos cachés a la vez se pisan

Solo puede existir un `wp-content/advanced-cache.php`. Instalar el nuestro
encima del de otro plugin deja al otro medio activo y sirviendo HTML que nadie
purga.

**Regla:** `Dropin::install()` se niega a sobrescribir un archivo que no lleve
nuestra firma, y el panel de estado lista los plugins de caché activos.

### 3.8 Guardar HTML incompleto congela un error

Si algo revienta a mitad del render, el buffer llega cortado. Guardarlo
significa servir esa página rota durante 12 horas a todo el mundo, sin errores
en el registro de PHP porque ya nadie ejecuta el código.

**Regla:** no se guarda nada que no termine en `</html>`, ni respuestas de
menos de 255 bytes, ni códigos distintos de 200.

### 3.9 Editar wp-config.php puede tumbar el sitio

Activar la caché escribe `define( 'WP_CACHE', true );` en `wp-config.php`. Un
error ahí no deja el sitio lento: lo deja en blanco, incluido el panel desde el
que arreglarlo.

**Regla:** el resultado se parsea con `token_get_all()` **antes** de escribirlo,
se guarda una copia de seguridad al lado y la escritura es atómica. Si el
archivo no se puede escribir, el panel dice la línea exacta que hay que añadir a
mano en vez de intentarlo igualmente.

### 3.10 Una plantilla de Bricks no tiene una URL que purgar

Guardar una plantilla, una clase global o un color de Bricks puede cambiar
cualquier página del sitio. Y cuando Bricks regenera sus archivos CSS, el HTML
guardado sigue apuntando a nombres de archivo que ya no existen: la página se
ve rota, no vieja.

**Regla:** los tipos de contenido no públicos y `bricks/generate_css_file`
purgan todo. Es caro y es lo correcto.

### 3.11 El stock miente en cuanto se vende una unidad

Una ficha guardada que dice «En stock» después de venderse la última unidad
cuesta un cliente, no unos milisegundos.

**Regla:** stock, estado de stock, precios y pedidos pagados purgan el producto
y el listado que lo muestra. Las purgas se acumulan en una cola y se ejecutan
una sola vez al final de la petición, porque guardar un producto dispara una
docena de ganchos.

---

## 4. Desplegar sin sustos

1. Comprobar la sintaxis **antes** de que el archivo llegue a `plugins/`
   (`php -l`, o subirlo con extensión inerte y parsearlo allí).
2. Instalar con la caché de página **apagada**. Es el estado por defecto.
3. Chequeo de estado: `/`, `/wp-json/`, `/shop/`, `/cart/`, `/my-account/` y una
   ficha de producto.
4. Encender la caché y repetir el chequeo mirando las cabeceras:
   `curl -sI https://overlu.com/ | grep -i x-bricks-cache`.
   La primera visita es `MISS`, la segunda `HIT`. `/cart/` tiene que ser
   `BYPASS` siempre.
5. Añadir un producto al carrito y recargar la portada: debe dejar de haber
   `HIT` para esa sesión.
6. Si algo falla, desactivar el plugin. Al desactivarse se retira el archivo, la
   constante y todas las páginas guardadas, así que el sitio vuelve al estado
   anterior sin tocar nada más.
