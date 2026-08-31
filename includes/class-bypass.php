<?php
/**
 * Decides whether a request may be served from, or stored in, the cache.
 * Pure PHP: no WordPress functions.
 *
 * @package BricksCache
 */

namespace BricksCache;

/**
 * The one rule that matters in a shop: when in doubt, do not cache. A missed
 * cache hit costs milliseconds; a cached cart, checkout or session page shows
 * one customer the data of another. Every check here fails closed.
 *
 * Used by the drop-in when reading and by the page cache module when writing,
 * so both ends apply exactly the same rules.
 */
final class Bypass {

	/**
	 * Paths that are never cached, whatever the settings say.
	 *
	 * @var string[]
	 */
	private const HARD_PATHS = [
		'/wp-admin/*',
		'/wp-json/*',
		'/wp-content/*',
		'/wp-includes/*',
		'*/feed/',
		'*/embed/',
		'*.php',
	];

	/**
	 * Cookie name prefixes that mean "this visitor has a state of their own".
	 *
	 * @var string[]
	 */
	private const HARD_COOKIES = [
		'wordpress_logged_in_',
		'wordpress_sec_',
		'wp-postpass_',
		'comment_author_',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',
		'store_notice',
	];

	/**
	 * Query arguments that always mean a dynamic response.
	 *
	 * @var string[]
	 */
	private const HARD_QUERY_ARGS = [
		'wc-ajax',
		'wc-api',
		's',
		'preview',
		'preview_id',
		'p',
		'customize_changeset_uuid',
		'unapproved',
		'add-to-cart',
		'removed_item',
		'download_file',
		'bricks',
		'brickspreview',
		'elementor-preview',
		'nocache',
		'novamira_preview',
	];

	/**
	 * Why this request must not use the cache, or null when it may.
	 *
	 * @param array<string,mixed> $config  Drop-in config.
	 * @param array<string,mixed> $server  $_SERVER.
	 * @param array<string,mixed> $cookies $_COOKIE.
	 *
	 * @return string|null Machine readable reason, kept in English for the log.
	 */
	public static function reason( array $config, array $server, array $cookies ): ?string {
		if ( empty( $config['enabled'] ) ) {
			return 'cache_disabled';
		}

		$method = strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) );

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return 'method_' . strtolower( $method );
		}

		if ( ! empty( $server['PHP_AUTH_USER'] ) || ! empty( $server['HTTP_AUTHORIZATION'] ) ) {
			return 'http_auth';
		}

		$path = Key::path( $server );

		if ( Key::matches_any( $path, self::HARD_PATHS ) ) {
			return 'reserved_path';
		}

		if ( Key::matches_any( $path, (array) ( $config['excluded_paths'] ?? [] ) ) ) {
			return 'excluded_path';
		}

		$cookie_reason = self::cookie_reason( $config, $cookies );

		if ( null !== $cookie_reason ) {
			return $cookie_reason;
		}

		$agent = (string) ( $server['HTTP_USER_AGENT'] ?? '' );

		if ( '' !== $agent ) {
			foreach ( (array) ( $config['excluded_agents'] ?? [] ) as $needle ) {
				if ( '' !== trim( (string) $needle ) && false !== stripos( $agent, (string) $needle ) ) {
					return 'excluded_agent';
				}
			}
		}

		return self::query_reason( $config, $server );
	}

	/**
	 * Cookie-based bypass, split out because the plugin also checks it on its
	 * own when deciding whether to store a response.
	 *
	 * @param array<string,mixed> $config  Drop-in config.
	 * @param array<string,mixed> $cookies $_COOKIE.
	 */
	public static function cookie_reason( array $config, array $cookies ): ?string {
		$extra = (array) ( $config['excluded_cookies'] ?? [] );

		foreach ( array_keys( $cookies ) as $name ) {
			$name = (string) $name;

			foreach ( self::HARD_COOKIES as $prefix ) {
				if ( str_starts_with( $name, $prefix ) ) {
					return 'session_cookie';
				}
			}

			if ( Key::matches_any( $name, $extra ) ) {
				return 'excluded_cookie';
			}

			foreach ( $extra as $needle ) {
				$needle = trim( (string) $needle );

				if ( '' !== $needle && ! str_contains( $needle, '*' ) && str_starts_with( $name, $needle ) ) {
					return 'excluded_cookie';
				}
			}
		}

		return null;
	}

	/**
	 * Query-string bypass: an argument nobody declared may change the page in
	 * ways the key does not describe, so it is safer not to cache at all.
	 *
	 * @param array<string,mixed> $config Drop-in config.
	 * @param array<string,mixed> $server $_SERVER.
	 */
	public static function query_reason( array $config, array $server ): ?string {
		$raw = (string) parse_url( (string) ( $server['REQUEST_URI'] ?? '/' ), PHP_URL_QUERY );

		if ( '' === $raw ) {
			return null;
		}

		parse_str( $raw, $args );

		$ignored = (array) ( $config['ignored_query_args'] ?? [] );
		$allowed = (array) ( $config['cached_query_args'] ?? [] );

		foreach ( array_keys( $args ) as $name ) {
			$name = (string) $name;

			if ( in_array( strtolower( $name ), self::HARD_QUERY_ARGS, true ) ) {
				return 'dynamic_query_arg';
			}

			if ( Key::matches_any( $name, $ignored ) || Key::matches_any( $name, $allowed ) ) {
				continue;
			}

			return 'unknown_query_arg';
		}

		return null;
	}
}
