<?php
/**
 * Bricks Cache — advanced-cache.php
 * Dropin Version: {{DROPIN_VERSION}}
 *
 * Installed and removed by the Bricks Cache plugin. Do not edit by hand: it is
 * overwritten whenever the plugin updates.
 *
 * This file runs from wp-settings.php, before plugins, before the theme and
 * before any database query. A hit ends the request here, which is the whole
 * point: the fastest page is the one WordPress never builds.
 *
 * @package BricksCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( 'cli' === PHP_SAPI ) {
	return;
}

if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
	return;
}

( static function (): void {
	$config_file = '{{CONFIG_FILE}}';

	if ( ! is_readable( $config_file ) ) {
		return;
	}

	$config = include $config_file;

	if ( ! is_array( $config ) || empty( $config['enabled'] ) ) {
		return;
	}

	$plugin_dir = (string) ( $config['plugin_dir'] ?? '' );

	foreach ( [ '/includes/class-key.php', '/includes/class-bypass.php' ] as $dependency ) {
		if ( ! is_readable( $plugin_dir . $dependency ) ) {
			return;
		}

		require_once $plugin_dir . $dependency;
	}

	define( 'BRICKS_CACHE_START', microtime( true ) );
	define( 'BRICKS_CACHE_DROPIN', true );

	$reason = \BricksCache\Bypass::reason( $config, $_SERVER, $_COOKIE );

	if ( null !== $reason ) {
		define( 'BRICKS_CACHE_BYPASS', $reason );

		return;
	}

	$base = \BricksCache\Key::file_base( $config, $_SERVER, $_COOKIE );
	$html = $base . '.html';

	if ( ! is_readable( $html ) ) {
		// A miss. The plugin will build the page and store it on shutdown.
		return;
	}

	$created = (int) filemtime( $html );
	$ttl     = (int) ( $config['ttl'] ?? 0 );

	if ( $ttl > 0 && ( time() - $created ) > $ttl ) {
		// Expired. Leave the files for the plugin to overwrite: deleting them
		// here would race with a second visitor arriving in the same second.
		return;
	}

	$meta = [];

	if ( is_readable( $base . '.json' ) ) {
		$decoded = json_decode( (string) file_get_contents( $base . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$meta = is_array( $decoded ) ? $decoded : [];
	}

	define( 'BRICKS_CACHE_SERVED', true );

	$age          = time() - $created;
	$modified     = gmdate( 'D, d M Y H:i:s', $created ) . ' GMT';
	$etag         = '"' . md5( $html . $created . (string) filesize( $html ) ) . '"';
	$content_type = (string) ( $meta['content_type'] ?? 'text/html; charset=UTF-8' );

	header( 'Content-Type: ' . $content_type );
	header( 'X-Bricks-Cache: HIT' );
	header( 'X-Bricks-Cache-Age: ' . $age );
	header( 'Last-Modified: ' . $modified );
	header( 'ETag: ' . $etag );
	header( 'Cache-Control: max-age=0, must-revalidate' );
	header( 'Vary: Accept-Encoding' . ( empty( $config['mobile_variant'] ) ? '' : ', User-Agent' ) );

	if ( ! empty( $config['debug_headers'] ) ) {
		header( 'X-Bricks-Cache-File: ' . basename( $html ) );
	}

	$if_none_match     = trim( (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) );
	$if_modified_since = trim( (string) ( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '' ) );

	$not_modified = ( '' !== $if_none_match && str_contains( $if_none_match, trim( $etag, '"' ) ) )
		|| ( '' !== $if_modified_since && strtotime( $if_modified_since ) >= $created );

	if ( $not_modified ) {
		$protocol = (string) ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1' );

		header( $protocol . ' 304 Not Modified', true, 304 );
		exit;
	}

	if ( 'HEAD' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
		exit;
	}

	$gz      = $base . '.html.gz';
	$accepts = (string) ( $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '' );

	if ( ! empty( $config['gzip'] )
		&& is_readable( $gz )
		&& false !== stripos( $accepts, 'gzip' )
		&& ! ini_get( 'zlib.output_compression' )
	) {
		header( 'Content-Encoding: gzip' );
		header( 'Content-Length: ' . filesize( $gz ) );
		readfile( $gz ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	header( 'Content-Length: ' . filesize( $html ) );
	readfile( $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
} )();
