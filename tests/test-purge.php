<?php
/**
 * Standalone checks for the unused-CSS removal.
 *
 * This is the most dangerous thing the plugin does: every check here is a way
 * of deleting a rule the page needed. Run with: php tests/test-purge.php
 *
 * @package BricksCache
 */

require_once __DIR__ . '/../includes/css/class-selectors.php';
require_once __DIR__ . '/../includes/css/class-purger.php';
require_once __DIR__ . '/../includes/css/class-vocabulary.php';

use BricksCache\Css\Purger;
use BricksCache\Css\Selectors;
use BricksCache\Css\Vocabulary;

$failures = 0;
$checks   = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Result.
 * @param string $label     What was checked.
 */
function check( bool $condition, string $label ): void {
	global $failures, $checks;

	++$checks;

	if ( ! $condition ) {
		++$failures;

		echo "FAIL  {$label}\n";

		return;
	}

	echo "ok    {$label}\n";
}

/**
 * Assert two strings are equal.
 *
 * @param string $expected Expected value.
 * @param string $actual   Actual value.
 * @param string $label    What was checked.
 */
function same( string $expected, string $actual, string $label ): void {
	global $failures, $checks;

	++$checks;

	if ( $expected !== $actual ) {
		++$failures;

		echo "FAIL  {$label}\n      esperado: {$expected}\n      obtenido: {$actual}\n";

		return;
	}

	echo "ok    {$label}\n";
}

/**
 * Shorthand: purge with a vocabulary given as a list.
 *
 * @param string   $css      Stylesheet.
 * @param string[] $present  Tokens the page has.
 * @param string[] $safelist Patterns always kept.
 */
function purge( string $css, array $present, array $safelist = [] ): string {
	return Purger::purge( $css, array_fill_keys( $present, true ), $safelist )['css'];
}

// What must go.
same( '.usada{color:red}', purge( '.usada{color:red}.sobra{color:blue}', [ 'usada' ] ), 'quita la clase que no está en la página' );
same( '', purge( '.a .b{color:red}', [ 'a' ] ), 'quita si falta cualquiera de las clases del selector' );
same( '', purge( '#cabecera{color:red}', [ 'otra' ] ), 'quita por id ausente' );

// What must stay, no matter what.
same( 'body{margin:0}', purge( 'body{margin:0}', [] ), 'las reglas de etiqueta se conservan siempre' );
same( 'a:hover{color:red}', purge( 'a:hover{color:red}', [] ), 'pseudoclases sobre etiqueta se conservan' );
same( ':root{--x:1px}', purge( ':root{--x:1px}', [] ), 'las variables de :root se conservan' );
same( '*{box-sizing:border-box}', purge( '*{box-sizing:border-box}', [] ), 'el selector universal se conserva' );
same( '[data-abrir] .caja{color:red}', purge( '[data-abrir] .caja{color:red}', [ 'caja' ] ), 'selector de atributo con clase presente' );
same( '@font-face{font-family:x;src:url(x.woff2)}', purge( '@font-face{font-family:x;src:url(x.woff2)}', [] ), '@font-face se conserva entero' );
same( '@keyframes girar{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}', purge( '@keyframes girar{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}', [] ), '@keyframes se conserva con sus llaves anidadas' );
same( '@import url(x.css);', purge( '@import url(x.css);', [] ), '@import se conserva' );

// Selector lists.
same( '.a{color:red}', purge( '.a,.b{color:red}', [ 'a' ] ), 'de una lista solo sobrevive lo usado' );
same( '.a,.b{color:red}', purge( '.a,.b{color:red}', [ 'a', 'b' ] ), 'la lista entera si todo se usa' );
same( '', purge( '.a,.b{color:red}', [ 'c' ] ), 'la regla entera se va si no sobrevive ningún selector' );

// The pseudo-classes that break naive purgers.
same( '.menu:not(.cerrado){display:block}', purge( '.menu:not(.cerrado){display:block}', [ 'menu' ] ), ':not() no exige que su clase exista' );
same( ':is(.a,.b) .c{color:red}', purge( ':is(.a,.b) .c{color:red}', [ 'c' ] ), ':is() no exige que sus clases existan' );
same( '.tarjeta:has(.oferta){border:1px}', purge( '.tarjeta:has(.oferta){border:1px}', [ 'tarjeta' ] ), ':has() no exige que su clase exista' );

// Conditional groups.
same( '@media (min-width:600px){.a{color:red}}', purge( '@media (min-width:600px){.a{color:red}.b{color:blue}}', [ 'a' ] ), 'dentro de @media se filtra' );
same( '', purge( '@media (min-width:600px){.b{color:blue}}', [ 'a' ] ), '@media vacío desaparece' );
same( '@supports (display:grid){@media screen{.a{color:red}}}', purge( '@supports (display:grid){@media screen{.a{color:red}.b{color:blue}}}', [ 'a' ] ), 'grupos anidados' );

// Strings and escapes.
same( '.a{content:"}, no es una llave"}', purge( '.a{content:"}, no es una llave"}', [ 'a' ] ), 'llave y coma dentro de una cadena' );
same( '.md\\:flex{display:flex}', purge( '.md\\:flex{display:flex}', [ 'md:flex' ] ), 'clase con dos puntos escapados' );

// Safelist.
same( '.is-abierto{display:block}', purge( '.is-abierto{display:block}', [], [ 'is-*' ] ), 'la lista de conservación admite comodín' );
same( '.woocommerce-message{color:green}', purge( '.woocommerce-message{color:green}', [], [ 'woocommerce-message' ] ), 'la lista de conservación admite nombre exacto' );

// A realistic icon font: one icon used out of three.
$iconos = '.fa{font-family:FA}.fa-carrito:before{content:"\\f07a"}.fa-usuario:before{content:"\\f007"}.fa-mapa:before{content:"\\f279"}';
same( '.fa{font-family:FA}.fa-carrito:before{content:"\\f07a"}', purge( $iconos, [ 'fa', 'fa-carrito' ] ), 'de una fuente de iconos solo queda el icono usado' );

// Counters and structure.
$resultado = Purger::purge( '.a{color:red}.b{color:blue}.c{color:green}', [ 'a' => true ] );
check( 1 === $resultado['kept'] && 2 === $resultado['removed'], 'las reglas conservadas y quitadas se cuentan' );
check( substr_count( $resultado['css'], '{' ) === substr_count( $resultado['css'], '}' ), 'las llaves quedan equilibradas' );

// The index that keys the cache.
check( [ 'a', 'b' ] === Purger::index( '.a{x:1}@media screen{.b{y:2}}body{z:3}' ), 'el índice recoge las clases de todos los niveles' );

// Vocabulary out of HTML.
$html  = '<div class="uno dos" id="cabecera"><span class=\'tres\'>x</span></div>';
$vocab = Vocabulary::from_html( $html );
check( isset( $vocab['uno'], $vocab['dos'], $vocab['tres'], $vocab['cabecera'] ), 'se leen clases e ids del HTML, con comillas simples o dobles' );

// Vocabulary out of JavaScript.
$js    = "el.classList.add('abierto');\n\$('.menu').addClass('activo desplegado');\nconst t = '<div class=\"aviso-ajax\">';\ndocument.querySelector('#buscador');";
$vocab = Vocabulary::from_script( $js );
check( isset( $vocab['abierto'] ), 'classList.add' );
check( isset( $vocab['activo'], $vocab['desplegado'] ), 'addClass con varias clases' );
check( isset( $vocab['menu'], $vocab['buscador'] ), 'selectores escritos dentro de cadenas' );
check( isset( $vocab['aviso-ajax'] ), 'HTML generado desde JavaScript' );

echo "\n{$checks} comprobaciones, {$failures} fallos\n";

exit( $failures > 0 ? 1 : 0 );
