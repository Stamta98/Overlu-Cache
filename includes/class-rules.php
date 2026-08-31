<?php
/**
 * WordPress-side eligibility: may this response be stored?
 *
 * @package BricksCache
 */

namespace BricksCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bypass answers the question the drop-in can ask with no WordPress loaded.
 * This class asks everything else: the conditional tags, the status code, the
 * headers the response is about to send, and whatever WooCommerce says about
 * the current visitor.
 *
 * Reasons are returned as short English strings because they end up in the log
 * and in a response header, not in front of a customer.
 */
final class Rules {

	/**
	 * Settings service.
	 */
	private Settings $settings;

	/**
	 * Drop-in configuration for this request.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $config = null;

	/**
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The same configuration the drop-in read, so both ends agree.
	 *
	 * @return array<string,mixed>
	 */
	public function config(): array {
		if ( null === $this->config ) {
			$this->config = Config::read();
		}

		return $this->config;
	}

	/**
	 * Why this request must not be cached, or null when it may be.
	 */
	public function request_reason(): ?string {
		if ( defined( 'BRICKS_CACHE_SERVED' ) ) {
			return 'already_served';
		}

		if ( ! defined( 'BRICKS_CACHE_DROPIN' ) ) {
			// Nothing would ever serve what we stored.
			return 'dropin_inactive';
		}

		if ( defined( 'BRICKS_CACHE_BYPASS' ) ) {
			return (string) constant( 'BRICKS_CACHE_BYPASS' );
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return 'admin_request';
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return 'api_request';
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( is_user_logged_in() ) {
			return 'logged_in';
		}

		$reason = Bypass::reason( $this->config(), $_SERVER, $_COOKIE ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( null !== $reason ) {
			return $reason;
		}

		/**
		 * Filter the request level decision. Return a non-empty string to keep
		 * the current request out of the cache.
		 *
		 * @param string|null $reason Reason so far.
		 */
		$reason = apply_filters( 'bricks_cache_request_bypass_reason', null );

		return is_string( $reason ) && '' !== $reason ? $reason : null;
	}

	/**
	 * Why this finished response must not be stored, or null when it may be.
	 *
	 * @param string $html Rendered output.
	 */
	public function response_reason( string $html ): ?string {
		if ( function_exists( 'http_response_code' ) && 200 !== http_response_code() ) {
			return 'status_' . (string) http_response_code();
		}

		if ( is_404() || is_search() || is_feed() || is_trackback() || is_robots() || is_preview() ) {
			return 'dynamic_template';
		}

		if ( is_customize_preview() || post_password_required() ) {
			return 'private_template';
		}

		if ( is_user_logged_in() ) {
			return 'logged_in';
		}

		$header_reason = $this->header_reason();

		if ( null !== $header_reason ) {
			return $header_reason;
		}

		if ( strlen( $html ) < 255 ) {
			return 'response_too_short';
		}

		if ( ! str_contains( $html, '</html>' ) ) {
			// A truncated page usually means a fatal error mid-render. Storing
			// it would freeze the broken page for everyone.
			return 'incomplete_html';
		}

		if ( str_contains( $html, '<!--bricks-cache:nocache-->' ) ) {
			return 'nocache_marker';
		}

		/**
		 * Filter the response level decision. Compatibility layers use this to
		 * keep personalised pages out of the cache.
		 *
		 * @param string|null $reason Reason so far.
		 * @param string      $html   Rendered output.
		 */
		$reason = apply_filters( 'bricks_cache_response_bypass_reason', null, $html );

		return is_string( $reason ) && '' !== $reason ? $reason : null;
	}

	/**
	 * Headers the response is about to send that forbid caching. A Set-Cookie
	 * is the important one: something started a session for this visitor, so
	 * the HTML is theirs and nobody else's.
	 */
	private function header_reason(): ?string {
		if ( ! function_exists( 'headers_list' ) ) {
			return null;
		}

		foreach ( headers_list() as $header ) {
			$header = strtolower( $header );

			if ( str_starts_with( $header, 'set-cookie:' ) && ! $this->is_harmless_cookie( $header ) ) {
				return 'sets_cookie';
			}

			if ( str_starts_with( $header, 'cache-control:' )
				&& ( str_contains( $header, 'no-store' ) || str_contains( $header, 'private' ) )
			) {
				return 'no_store_header';
			}
		}

		return null;
	}

	/**
	 * Cookies that do not personalise the HTML and would otherwise disable the
	 * cache on every first visit.
	 *
	 * @param string $header Lowercased Set-Cookie header.
	 */
	private function is_harmless_cookie( string $header ): bool {
		$harmless = [
			'pll_language',
			'wp-wpml_current_language',
			'_ga',
			'sbjs_',
		];

		/**
		 * Filter the cookies that may be set without disabling the cache.
		 *
		 * @param string[] $harmless Cookie name fragments.
		 */
		$harmless = (array) apply_filters( 'bricks_cache_harmless_cookies', $harmless );

		foreach ( $harmless as $needle ) {
			if ( '' !== trim( (string) $needle ) && str_contains( $header, strtolower( (string) $needle ) ) ) {
				return true;
			}
		}

		return false;
	}
}
