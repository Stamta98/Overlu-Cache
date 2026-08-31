<?php
/**
 * Standalone checks for the CSS minifier and the URL rewriter.
 *
 * Minifying with a regular expression is how a cache plugin breaks a site, so
 * every case here is one of the things a regular expression gets wrong.
 * Run with: php tests/test-css.php
 *
 * @package BricksCache
 */

require_once __DIR__ . '/../includes/css/class-minifier.php';

use BricksCache\Css\Minifier;

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
 * Assert two strings are equal, printing both when they are not.
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

// Whitespace and comments.
same( 'a{color:red}', Minifier::minify( "a {\n  color: red;\n}\n" ), 'espacios y punto y coma final' );
same( 'a{color:red}', Minifier::minify( '/* comentario */ a { color : red ; }' ), 'comentario normal eliminado' );
same( '/*! licencia */a{color:red}', Minifier::minify( '/*! licencia */ a { color: red }' ), 'cabecera de licencia conservada' );

// The classic breakages.
same( 'a{width:calc(100% - 20px)}', Minifier::minify( 'a { width: calc(100% - 20px); }' ), 'calc conserva sus espacios' );
same( 'a{width:calc(100% + 2rem)}', Minifier::minify( 'a { width: calc(100%   +   2rem); }' ), 'calc con + colapsa pero no pierde el espacio' );
same( 'a{font-size:clamp(1rem,2vw,3rem)}', Minifier::minify( 'a { font-size: clamp( 1rem , 2vw , 3rem ); }' ), 'clamp con comas' );
same( '@media (min-width:600px){a{color:red}}', Minifier::minify( '@media (min-width: 600px) { a { color: red; } }' ), 'consulta de medios' );
same( 'a{content:"} no es una llave"}', Minifier::minify( 'a { content: "} no es una llave"; }' ), 'llave dentro de una cadena' );
same( 'a{content:"/* no es un comentario */"}', Minifier::minify( 'a { content: "/* no es un comentario */"; }' ), 'comentario dentro de una cadena' );
same( 'a{content:"a\\"b"}', Minifier::minify( 'a { content: "a\\"b"; }' ), 'comilla escapada dentro de una cadena' );

// Selectors.
same( '.a>.b{color:red}', Minifier::minify( '.a > .b { color: red }' ), 'combinador hijo' );
same( '.a+.b{color:red}', Minifier::minify( '.a + .b { color: red }' ), 'combinador hermano' );
same( '.a .b{color:red}', Minifier::minify( '.a   .b { color: red }' ), 'descendiente conserva su espacio' );
same( 'input[type="text"]:not(.x){color:red}', Minifier::minify( 'input[type="text"]:not( .x ) { color: red }' ), 'atributo y :not' );
same( ':is(.a,.b) .c{color:red}', Minifier::minify( ':is( .a , .b ) .c { color: red }' ), ':is con lista' );

// url().
same( 'a{background:url(img/x.png)}', Minifier::minify( 'a { background: url( img/x.png ) }' ), 'url sin comillas' );
$data = 'a{background:url("data:image/svg+xml;charset=utf8,%3Csvg xmlns=\'x\'%3E")}';
same( $data, Minifier::minify( 'a { background: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns=\'x\'%3E"); }' ), 'data URI intacto' );

// Sizes.
$css_largo = str_repeat( "/* c */\n.selector-{$checks} {\n  color: #ffffff;\n  margin: 0 auto;\n}\n", 200 );
$min       = Minifier::minify( $css_largo );
check( strlen( $min ) < strlen( $css_largo ) * 0.7, 'un CSS sin minificar se reduce al menos un 30%' );
check( substr_count( $min, '{' ) === substr_count( $min, '}' ), 'las llaves siguen equilibradas' );

// URL rewriting.
$base = 'https://overlu.com/wp-content/themes/bricks/assets/css/';
same( 'url(https://overlu.com/wp-content/themes/bricks/assets/css/img/x.png)', Minifier::rewrite_urls( 'url(img/x.png)', $base ), 'ruta relativa a absoluta' );
same( 'url(https://overlu.com/wp-content/themes/bricks/assets/fonts/x.woff2)', Minifier::rewrite_urls( 'url(../fonts/x.woff2)', $base ), 'ruta con .. resuelta' );
same( 'url("https://overlu.com/wp-content/themes/bricks/a/b.png")', Minifier::rewrite_urls( 'url("../../a/b.png")', $base ), 'dos niveles arriba' );
same( 'url(/absoluta.png)', Minifier::rewrite_urls( 'url(/absoluta.png)', $base ), 'ruta absoluta intacta' );
same( 'url(https://cdn.com/x.png)', Minifier::rewrite_urls( 'url(https://cdn.com/x.png)', $base ), 'url externa intacta' );
same( 'url(data:image/png;base64,AAA)', Minifier::rewrite_urls( 'url(data:image/png;base64,AAA)', $base ), 'data URI intacto al reescribir' );
same( 'url(https://overlu.com/wp-content/themes/bricks/assets/css/f.woff?v=2#iefix)', Minifier::rewrite_urls( 'url(f.woff?v=2#iefix)', $base ), 'consulta y ancla conservados' );

echo "\n{$checks} comprobaciones, {$failures} fallos\n";

exit( $failures > 0 ? 1 : 0 );
