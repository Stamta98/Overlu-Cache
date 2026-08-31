<?php
/**
 * Bricks Ecommerce and Overlu Marketplace compatibility.
 *
 * @package BricksCache
 */

namespace BricksCache\Compat;

use BricksCache\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shop elements of this site are rendered by Bricks Ecommerce, and some of
 * them print state that lives in a cookie: the wishlist counter, the compare
 * list, recently viewed products.
 *
 * Caching a page that prints one visitor's wishlist and serving it to the next
 * one is the same bug as caching a cart. The rule here is narrow on purpose:
 * the cookie only exists once the visitor uses the feature, so first-time
 * visitors — the overwhelming majority, and the ones whose speed is measured —
 * still get a cached page, and only the ones with a wishlist fall back to a
 * live render.
 *
 * The permanent fix is to print those counters from JavaScript instead of PHP,
 * which is a Bricks Ecommerce change, not a cache one. Until then, this.
 */
final class Bricks_Ecommerce {

	/**
	 * Container.
	 */
	private Plugin $plugin;

	/**
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether Bricks Ecommerce is running.
	 */
	public static function is_active(): bool {
		return defined( 'BRICKSECOM_VER' );
	}

	/**
	 * Whether Overlu Marketplace is running.
	 */
	public static function marketplace_is_active(): bool {
		return defined( 'OVERLU_MARKETPLACE_VERSION' ) || function_exists( 'overlu_marketplace' );
	}

	/**
	 * Register the cookie exclusions.
	 */
	public function boot(): void {
		add_filter( 'bricks_cache_config', [ $this, 'add_state_cookies' ] );
	}

	/**
	 * Cookies whose presence means the page carries personal state.
	 *
	 * @param array<string,mixed> $config Drop-in configuration.
	 *
	 * @return array<string,mixed>
	 */
	public function add_state_cookies( array $config ): array {
		$cookies = [];

		foreach ( [ 'BRICKSECOM_WISHLIST_COOKIE', 'BRICKSECOM_COMPARE_COOKIE', 'BRICKSECOM_VIEWED_COOKIE' ] as $constant ) {
			if ( defined( $constant ) ) {
				$cookies[] = (string) constant( $constant );
			}
		}

		if ( [] === $cookies && self::is_active() ) {
			// The plugin is there but did not expose its constants yet.
			$cookies = [ 'bricksecom_wishlist', 'bricksecom_compare', 'bricksecom_viewed' ];
		}

		/**
		 * Filter the shop cookies that disable the cache while they exist.
		 *
		 * @param string[] $cookies Cookie names.
		 */
		$cookies = (array) apply_filters( 'bricks_cache_state_cookies', $cookies );

		$config['excluded_cookies'] = array_values(
			array_unique(
				array_merge( (array) ( $config['excluded_cookies'] ?? [] ), $cookies )
			)
		);

		return $config;
	}
}
