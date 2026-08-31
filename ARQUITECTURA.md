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
  css/                    Minificador, recolector de la cola, empaquetado y CSS crítico.
  modules/                Una optimización = un módulo. Hoy: caché de página y CSS.
  compat/                 WooCommerce, Bricks, Bricks Ecommerce.
  admin/                  Panel y barra superior.
tests/test-pure-rules.php Pruebas de Key y Bypass, sin WordPress.
tests/test-css.php        Pruebas del minificador, sin WordPress.
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

### 3.12 WooCommerce escribe una cookie en cada ficha de producto

`woocommerce_recently_viewed` se manda en **todas** las fichas de producto. Como
la regla 3.4 rechaza cualquier respuesta con `Set-Cookie`, el resultado es que
las únicas páginas que nunca se cachean son justo las que sostienen la tienda.

> Pasó en la primera verificación en producción: portada y catálogo daban HIT,
> las fichas de producto daban MISS siempre.

**Regla:** esa cookie está en la lista de inofensivas
(`bricks_cache_harmless_cookies`), porque no cambia la ficha: solo alimenta la
lista de «vistos recientemente». El precio de esa decisión es que **una página
que pinte esa lista en el servidor enseñaría los productos de otro visitante**.
Si algún día se añade ese elemento a una plantilla cacheada, hay que sacar la
cookie de la lista con el filtro.

### 3.13 El tema carga después que los plugins

`BRICKS_VERSION` no existe cuando el plugin arranca en `plugins_loaded`: Bricks
es un tema y WordPress carga los temas después. Preguntar ahí si Bricks está
activo siempre responde que no, y las purgas de diseño se quedan **sin
registrar en silencio**. Nada falla, nada se escribe en el registro, y el sitio
sigue sirviendo páginas que enlazan un CSS que Bricks ya ha regenerado.

> Pasó. Se detectó comprobando `has_action( 'bricks/generate_css_file' )` en la
> verificación, no usando el sitio.

**Regla:** lo que dependa del tema se registra en `after_setup_theme`. Es la
misma trampa que la 3.1 de Bricks Ecommerce, vista desde el otro lado: allí el
problema es cargar demasiado pronto una clase del tema, aquí es preguntar
demasiado pronto por él.

### 3.14 Guardar un ajuste no puede apagar sus vecinos

Una casilla desmarcada no se envía en un formulario, así que al guardar hay que
leer su ausencia como «desactivada». Pero `Settings::set()` recibe **un solo
campo** desde el código: aplicar ahí la misma regla apaga todas las demás
casillas de esa sección.

> Pasó al encender la caché desde el MCP: `set( 'page_cache.enabled', true )`
> dejó apagadas la compresión y la firma del HTML. La caché funcionaba, y las
> copias comprimidas no se escribían.

**Regla:** `update()` solo interpreta las ausencias cuando el origen es un
formulario completo (`$from_form`). Desde el código, lo que no se pasa no se
toca.

### 3.15 El CSS en línea desaparece con su hoja

`wp_add_inline_style( 'mi-handle', $css )` guarda el CSS **colgado del handle**.
Al desencolar esa hoja para meterla en el paquete, el CSS en línea se va con
ella. Y ese CSS suele ser el que tiene los valores calculados de la página: el
color de una sección, el alto de la cabecera.

El resultado no es una página rota, es una página *casi* bien, que es la clase
de fallo más difícil de ver.

**Regla:** el recolector lee `get_data( $handle, 'after' )` de cada hoja antes
de tocarla y vuelve a colgarlo del paquete, en el mismo orden. El CSS en línea
**no** entra en el archivo combinado: cambia de una página a otra y haría que
cada página generase su propio archivo.

### 3.16 El HTML guardado apunta a un CSS que puede dejar de existir

El nombre del paquete es la huella de lo que lleva dentro: cambia un archivo,
cambia el nombre. Perfecto para el navegador, y una trampa con la caché de
página encendida: las páginas guardadas ayer enlazan el paquete de ayer. Si al
generar el nuevo se borra el viejo, esas páginas se sirven **sin estilos**.

**Regla:** los paquetes antiguos se conservan (siete días por defecto) y solo
los borra la limpieza programada. Es disco barato a cambio de no dejar a nadie
mirando una página desnuda.

### 3.17 Minificar con una expresión regular rompe `calc()`

Es la forma clásica de tirar el diseño de un sitio: la expresión regular se come
el espacio de `calc(100% - 20px)`, la llave dentro de `content: "}"`, el punto
y coma de un `data:` URI y el `/*` que vive dentro de una cadena.

**Regla:** `Minifier` es un escáner, no una expresión regular: recorre el
archivo una vez sabiendo si está dentro de una cadena, de un comentario, de
`url()` o de un paréntesis, y dentro de paréntesis no quita nada más que
espacios repetidos. Cada uno de esos casos es una prueba en `tests/test-css.php`.

### 3.18 El constructor de Bricks necesita ver sus hojas

Si el paquete sustituye a las hojas originales dentro del constructor, Bricks
deja de encontrar lo que edita.

**Regla:** el módulo no hace nada en el escritorio, en el personalizador, con
`?bricks=` o `?brickspreview=` en la URL, ni cuando `bricks_is_builder()` dice
que sí.

### 3.19 Desencolar una hoja no basta

`wp_print_styles()` no imprime la cola: imprime `$wp_styles->to_do`, que
`all_deps()` rellena y **nunca vacía**. Preguntar por el orden de la cola es
justo lo que rellena `to_do`, así que después de eso `dequeue()` quita la hoja
de la cola y WordPress la imprime igualmente.

> Pasó en producción, con el módulo recién encendido: el paquete se generó
> bien, se encoló bien, y la página pasó a cargar **24 hojas y 1,1 MB** en vez
> de una. El sitio se veía perfecto, que es lo que hace que este fallo dure
> semanas si nadie mira las peticiones.

**Regla:** el recolector resuelve el orden sobre una **copia** de `WP_Styles` y
no toca el registro real; el módulo vacía `to_do` antes de encolar, para que
WordPress vuelva a leer la cola ya modificada. Después de cualquier cambio en
este módulo, contar las hojas de la página es la comprobación obligatoria: que
se vea bien no demuestra nada.

### 3.20 Bricks encola hojas mientras dibuja la página

Combinar la cola no basta. Bricks encola CSS **durante el render**: la
plantilla de cabecera, la de pie, un popup, el slider de una ficha. Todo eso
ocurre después de que la cabecera del documento ya se haya impreso, y WordPress
imprime esas hojas al final del cuerpo.

> Pasó: tras arreglar la trampa 3.19 la portada bajó de 24 hojas a 9, y esas 9
> eran CSS que **ya iba dentro del paquete**. La página cargaba 80 KB repetidos.

**Regla:** el módulo recuerda qué handles metió en un paquete y elimina su
etiqueta con el filtro `style_loader_tag`, la impriman cuando la impriman.
Desencolar antes no sirve de nada cuando el encolado ocurre después. Las hojas
que aparecen **solo** tarde y no están en ningún paquete se imprimen
normalmente: quitarlas dejaría la página sin esos estilos.

### 3.21 Un solo paquete por página se descarga entero en cada página

Combinarlo todo en un archivo es lo obvio y es lo equivocado. Bricks escribe un
CSS por página, así que la portada, el catálogo y la ficha generan tres archivos
de medio mega **casi idénticos**: al pasar de una a otra el navegador se
descarga otra vez todo lo que ya tenía. Antes de combinar, esas quince hojas
compartidas viajaban una sola vez para todo el sitio.

**Regla:** las hojas se parten en **tramos consecutivos** por tipo —las que
comparten todas las páginas y las que Bricks escribe para esta— y cada tramo es
un paquete. Los tramos compartidos tienen la misma huella en todas las páginas,
así que se descargan una vez para todo el sitio, y partir por tramos en vez de
por tipo mantiene intacto el orden de la cascada: la regla que ganaba, sigue
ganando.

---

## 4. Desplegar sin sustos

1. Comprobar la sintaxis **antes** de que el archivo llegue a `plugins/`
   (`php -l`, o subirlo con extensión inerte y parsearlo allí).
2. Instalar con la caché de página **apagada**. Es el estado por defecto.
3. Chequeo de estado: `/`, `/wp-json/`, `/shop/`, `/cart/`, `/my-account/` y una
   ficha de producto.
4. Con el módulo de CSS, **contar las hojas de estilo** de la portada, una
   categoría y una ficha: tiene que bajar de 23 a 1, no subir a 24.
5. Encender la caché y repetir el chequeo mirando las cabeceras:
   `curl -sI https://overlu.com/ | grep -i x-bricks-cache`.
   La primera visita es `MISS`, la segunda `HIT`. `/cart/` tiene que ser
   `BYPASS` siempre.
6. Añadir un producto al carrito y recargar la portada: debe dejar de haber
   `HIT` para esa sesión.
7. Si algo falla, desactivar el plugin. Al desactivarse se retira el archivo, la
   constante y todas las páginas guardadas, así que el sitio vuelve al estado
   anterior sin tocar nada más.
