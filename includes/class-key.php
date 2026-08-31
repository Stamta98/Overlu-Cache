<?php
/**
 * Cache key and file layout. Pure PHP: no WordPress functions.
 *
 * @package BricksCache
 */

namespace BricksCache;

/**
 * The drop-in runs before WordPress exists and the plugin runs after it, and
 * both have to agree on where a page lives on disk. That agreement is this
 * class, and it is the reason it cannot call a single WordPress function: the
 * moment the two sides compute paths differently, every page is a permanent
 * miss and nothing in the admin looks wrong.
 *
 * Layout: <root>/page/<host>/<path>/<variant>-<hash>.html
 *
 * The directory mirrors the URL so purging one URL is deleting one directory,
 * with every variant inside it. The hash disambiguates paths that sanitise to
 * the same characters and folds in the query string.
 */
final class Key {

	/**
	 * Host of the request, reduced to characters that are safe in a path.
	 *
	 * @param array<string,mixed> $server $_SERVER.
	 */
	public static function host( array $server ): string {
		$host = (string) ( $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'default' );
		$host = strtolower( $host );
		$host = preg_replace( '/[^a-z0-9.\-:]/', '', $host );
		$host = str_replace( ':', '_', (string) $host );

		return '' === $host ? 'default' : substr( $host, 0, 128 );
	}

	/**
	 * Request path without the query string, always starting and ending with
	 * a slash so /shop and /shop/ are one entry and not two.
	 *
	 * @param array<string,mixed> $server $_SERVER.
	 */
	public static function path( array $server ): string {
		$uri  = (string) ( $server['REQUEST_URI'] ?? '/' );
		$path = (string) parse_url( $uri, PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;

		return '/' . trim( $path, '/' ) . ( '/' === $path ? '' : '/' );
	}

	/**
	 * Query arguments that take part in the key, sorted so that ?a=1&b=2 and
	 * ?b=2&a=1 are the same page.
	 *
	 * @param array<string,mixed> $config Drop-in config.
	 * @param array<string,mixed> $server $_SERVER.
	 *
	 * @return array<string,string>
	 */
	public static function significant_query( array $config, array $server ): array {
		$raw = (string) parse_url( (string) ( $server['REQUEST_URI'] ?? '/' ), PHP_URL_QUERY );

		if ( '' === $raw ) {
			return [];
		}

		parse_str( $raw, $args );

		$ignored = (array) ( $config['ignored_query_args'] ?? [] );
		$kept    = [];

		foreach ( $args as $name => $value ) {
			if ( self::matches_any( (string) $name, $ignored ) ) {
				continue;
			}

			$kept[ (string) $name ] = is_scalar( $value ) ? (string) $value : wp_json_encode_fallback( $value );
		}

		ksort( $kept );

		return $kept;
	}

	/**
	 * Variant of the same URL: everything that makes two anonymous visitors
	 * receive different HTML for the same path.
	 *
	 * @param array<string,mixed> $config  Drop-in config.
	 * @param array<string,mixed> $server  $_SERVER.
	 * @param array<string,mixed> $cookies $_COOKIE.
	 */
	public static function variant( array $config, array $server, array $cookies ): string {
		$parts = [ self::is_https( $server ) ? 'https' : 'http' ];

		if ( ! empty( $config['mobile_variant'] ) && self::is_mobile( $server ) ) {
			$parts[] = 'mobile';
		} else {
			$parts[] = 'desktop';
		}

		// Declared cookies whose value changes the rendered page (currency,
		// language, warehouse...). Only the value's fingerprint is used.
		foreach ( (array) ( $config['variant_cookies'] ?? [] ) as $cookie ) {
			if ( isset( $cookies[ $cookie ] ) && is_scalar( $cookies[ $cookie ] ) ) {
				$parts[] = substr( md5( $cookie . '=' . (string) $cookies[ $cookie ] ), 0, 8 );
			}
		}

		return implode( '-', $parts );
	}

	/**
	 * Absolute path of a cached page without extension. Append .html,
	 * .html.gz or .json to reach the body, the compressed body or the meta.
	 *
	 * @param array<string,mixed> $config  Drop-in config.
	 * @param array<string,mixed> $server  $_SERVER.
	 * @param array<string,mixed> $cookies $_COOKIE.
	 */
	public static function file_base( array $config, array $server, array $cookies ): string {
		$variant = self::variant( $config, $server, $cookies );
		$query   = self::significant_query( $config, $server );

		$fingerprint = substr(
			md5( self::host( $server ) . '|' . self::path( $server ) . '|' . http_build_query( $query ) . '|' . $variant ),
			0,
			10
		);

		return self::directory( $config, $server ) . '/' . $variant . '-' . $fingerprint;
	}

	/**
	 * Directory holding every variant of one URL.
	 *
	 * @param array<string,mixed> $config Drop-in config.
	 * @param array<string,mixed> $server $_SERVER.
	 */
	public static function directory( array $config, array $server ): string {
		$root = rtrim( (string) ( $config['page_dir'] ?? '' ), '/' );

		return $root . '/' . self::host( $server ) . self::sanitize_path( self::path( $server ) );
	}

	/**
	 * Turn a URL path into a directory path, one segment per level.
	 *
	 * @param string $path Request path.
	 */
	public static function sanitize_path( string $path ): string {
		$segments = array_filter( explode( '/', $path ), static fn( $segment ) => '' !== $segment );
		$clean    = [];

		foreach ( $segments as $segment ) {
			$segment = rawurldecode( $segment );
			$segment = preg_replace( '/[^A-Za-z0-9._\-]/u', '-', $segment );
			$segment = trim( (string) $segment, '-' );
			$segment = '' === $segment ? 'x' : $segment;

			// Two dots in a row would climb out of the cache directory.
			$segment = str_replace( '..', '-', $segment );

			$clean[] = substr( $segment, 0, 100 );
		}

		if ( [] === $clean ) {
			return '/_root';
		}

		return '/' . implode( '/', array_slice( $clean, 0, 12 ) );
	}

	/**
	 * Whether the request came in over TLS.
	 *
	 * @param array<string,mixed> $server $_SERVER.
	 */
	public static function is_https( array $server ): bool {
		if ( ! empty( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] ) ) {
			return true;
		}

		if ( isset( $server['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $server['HTTP_X_FORWARDED_PROTO'] ) ) {
			return true;
		}

		return isset( $server['SERVER_PORT'] ) && '443' === (string) $server['SERVER_PORT'];
	}

	/**
	 * Rough mobile detection, only used when the mobile variant is enabled.
	 *
	 * @param array<string,mixed> $server $_SERVER.
	 */
	public static function is_mobile( array $server ): bool {
		$agent = (string) ( $server['HTTP_USER_AGENT'] ?? '' );

		if ( '' === $agent ) {
			return false;
		}

		return (bool) preg_match(
			'/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi|iPhone|iPod|IEMobile/i',
			$agent
		);
	}

	/**
	 * Case-insensitive match of a value against a list of patterns that may
	 * end in *.
	 *
	 * @param string   $value    Value to test.
	 * @param string[] $patterns Patterns.
	 */
	public static function matches_any( string $value, array $patterns ): bool {
		$value = strtolower( $value );

		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( trim( (string) $pattern ) );

			if ( '' === $pattern ) {
				continue;
			}

			if ( str_contains( $pattern, '*' ) ) {
				if ( fnmatch( $pattern, $value ) ) {
					return true;
				}

				continue;
			}

			if ( $pattern === $value ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'BricksCache\wp_json_encode_fallback' ) ) {
	/**
	 * json_encode for non-scalar query values, without depending on WordPress.
	 *
	 * @param mixed $value Value.
	 */
	function wp_json_encode_fallback( mixed $value ): string {
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
