<?php
/**
 * Standalone checks for the two classes the drop-in depends on.
 *
 * These run outside WordPress on purpose: if Key or Bypass ever start needing
 * WordPress to work, this file stops running and the drop-in would have been
 * broken silently. Run it with: php tests/test-pure-rules.php
 *
 * @package BricksCache
 */

require_once __DIR__ . '/../includes/class-key.php';
require_once __DIR__ . '/../includes/class-bypass.php';

use BricksCache\Bypass;
use BricksCache\Key;

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

$config = [
	'enabled'            => true,
	'page_dir'           => '/var/cache/page',
	'ttl'                => 3600,
	'ignored_query_args' => [ 'utm_*', 'fbclid' ],
	'cached_query_args'  => [ 'orderby', 'paged' ],
	'excluded_paths'     => [ '/cart/', '/cart/*', '/checkout/*', '/my-account/*' ],
	'excluded_cookies'   => [ 'bricksecom_wishlist' ],
	'excluded_agents'    => [],
	'mobile_variant'     => false,
];

$get = static function ( string $uri, array $extra = [] ): array {
	return array_merge(
		[
			'REQUEST_METHOD' => 'GET',
			'REQUEST_URI'    => $uri,
			'HTTP_HOST'      => 'overlu.com',
			'HTTPS'          => 'on',
		],
		$extra
	);
};

// Paths.
check( '/tienda/' === Key::path( $get( '/tienda/' ) ), 'trailing slash kept' );
check( Key::path( $get( '/tienda' ) ) === Key::path( $get( '/tienda/' ) ), 'with and without trailing slash are one entry' );
check( Key::path( $get( '/tienda/?utm_source=x' ) ) === '/tienda/', 'query string is not part of the path' );

// Directories stay under the cache root and never climb out of it.
$directory = Key::directory( $config, $get( '/../../etc/passwd' ) );
check( str_starts_with( $directory, '/var/cache/page/overlu.com/' ), 'directory stays under the page root' );
check( ! str_contains( $directory, '..' ), 'traversal is stripped from the directory' );

// Same page, different tracking parameters: one entry.
$a = Key::file_base( $config, $get( '/tienda/?utm_source=news&utm_medium=mail' ), [] );
$b = Key::file_base( $config, $get( '/tienda/' ), [] );
check( $a === $b, 'ignored parameters do not create a second copy' );

// Declared parameters: one entry each.
$c = Key::file_base( $config, $get( '/tienda/?orderby=price' ), [] );
check( $a !== $c, 'a declared parameter gets its own copy' );

// Argument order does not matter.
$d = Key::file_base( $config, $get( '/tienda/?orderby=price&paged=2' ), [] );
$e = Key::file_base( $config, $get( '/tienda/?paged=2&orderby=price' ), [] );
check( $d === $e, 'parameter order does not change the key' );

// Bypass: the shop pages.
check( 'excluded_path' === Bypass::reason( $config, $get( '/cart/' ), [] ), 'cart is never cached' );
check( 'excluded_path' === Bypass::reason( $config, $get( '/checkout/pedido-recibido/' ), [] ), 'order received is never cached' );
check( 'excluded_path' === Bypass::reason( $config, $get( '/my-account/orders/' ), [] ), 'account pages are never cached' );

// Bypass: sessions and carts.
check( 'session_cookie' === Bypass::reason( $config, $get( '/tienda/' ), [ 'woocommerce_items_in_cart' => '1' ] ), 'a filled cart disables the cache' );
check( 'session_cookie' === Bypass::reason( $config, $get( '/tienda/' ), [ 'wordpress_logged_in_abc' => '1' ] ), 'a logged in visitor is never served a cached page' );
check( 'excluded_cookie' === Bypass::reason( $config, $get( '/tienda/' ), [ 'bricksecom_wishlist' => '12' ] ), 'a wishlist cookie disables the cache' );

// Bypass: methods and dynamic requests.
check( null !== Bypass::reason( $config, $get( '/tienda/', [ 'REQUEST_METHOD' => 'POST' ] ), [] ), 'POST is never cached' );
check( 'dynamic_query_arg' === Bypass::reason( $config, $get( '/?s=camiseta' ), [] ), 'searches are never cached' );
check( 'dynamic_query_arg' === Bypass::reason( $config, $get( '/tienda/?wc-ajax=get_refreshed_fragments' ), [] ), 'WooCommerce AJAX is never cached' );
check( 'unknown_query_arg' === Bypass::reason( $config, $get( '/tienda/?filtro_raro=1' ), [] ), 'an undeclared parameter falls back to no cache' );
check( 'reserved_path' === Bypass::reason( $config, $get( '/wp-admin/edit.php' ), [] ), 'wp-admin is never cached' );
check( 'reserved_path' === Bypass::reason( $config, $get( '/wp-json/wc/v3/products' ), [] ), 'the REST API is never cached' );
check( 'reserved_path' === Bypass::reason( $config, $get( '/blog/entrada/feed/' ), [] ), 'feeds are never cached' );

// Bypass: the plain case.
check( null === Bypass::reason( $config, $get( '/tienda/' ), [] ), 'a plain anonymous visit is cacheable' );
check( null === Bypass::reason( $config, $get( '/tienda/?orderby=price&utm_source=x' ), [] ), 'declared and ignored parameters together are cacheable' );

// Mobile variants.
$mobile_config = array_merge( $config, [ 'mobile_variant' => true ] );
$phone         = $get( '/tienda/', [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile/15E148' ] );
$desktop       = $get( '/tienda/', [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64)' ] );

check( Key::file_base( $mobile_config, $phone, [] ) !== Key::file_base( $mobile_config, $desktop, [] ), 'mobile and desktop are separate copies when enabled' );
check( Key::file_base( $config, $phone, [] ) === Key::file_base( $config, $desktop, [] ), 'without the option there is a single copy' );

// The cache off switch wins over everything.
check( 'cache_disabled' === Bypass::reason( array_merge( $config, [ 'enabled' => false ] ), $get( '/tienda/' ), [] ), 'disabled means disabled' );

echo "\n{$checks} comprobaciones, {$failures} fallos\n";

exit( $failures > 0 ? 1 : 0 );
