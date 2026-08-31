<?php
/**
 * Environment gate.
 *
 * @package BricksCache
 */

namespace BricksCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses to boot on an environment the plugin cannot support, and explains
 * why in the admin instead of failing silently.
 */
final class Requirements {

	/**
	 * Collected failures, filled by are_met().
	 *
	 * @var string[]
	 */
	private static array $failures = [];

	/**
	 * Whether the plugin can run here.
	 */
	public static function are_met(): bool {
		self::$failures = [];

		if ( version_compare( PHP_VERSION, BRICKS_CACHE_MIN_PHP, '<' ) ) {
			self::$failures[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				esc_html__( 'Se necesita PHP %1$s o superior. Este servidor usa PHP %2$s.', 'bricks-cache' ),
				BRICKS_CACHE_MIN_PHP,
				PHP_VERSION
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), BRICKS_CACHE_MIN_WP, '<' ) ) {
			self::$failures[] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version. */
				esc_html__( 'Se necesita WordPress %1$s o superior. Este sitio usa la versión %2$s.', 'bricks-cache' ),
				BRICKS_CACHE_MIN_WP,
				get_bloginfo( 'version' )
			);
		}

		return [] === self::$failures;
	}

	/**
	 * Admin notice listing every unmet requirement.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) || [] === self::$failures ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Bricks Cache no se ha iniciado.', 'bricks-cache' ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';

		foreach ( self::$failures as $failure ) {
			echo '<li>' . esc_html( $failure ) . '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Cache plugins that also install an advanced-cache.php drop-in. Two page
	 * caches on the same site fight over the drop-in and serve stale HTML.
	 *
	 * @return string[] Plugin names found active.
	 */
	public static function conflicting_plugins(): array {
		$known = [
			'wp-rocket/wp-rocket.php'                 => 'WP Rocket',
			'w3-total-cache/w3-total-cache.php'       => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'             => 'WP Super Cache',
			'litespeed-cache/litespeed-cache.php'     => 'LiteSpeed Cache',
			'wp-fastest-cache/wpFastestCache.php'     => 'WP Fastest Cache',
			'cache-enabler/cache-enabler.php'         => 'Cache Enabler',
			'sg-cachepress/sg-cachepress.php'         => 'SiteGround Optimizer',
			'breeze/breeze.php'                       => 'Breeze',
			'nitropack/main.php'                      => 'NitroPack',
			'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
			'autoptimize/autoptimize.php'             => 'Autoptimize',
			'flying-press/flying-press.php'           => 'FlyingPress',
		];

		$found = [];

		foreach ( $known as $file => $label ) {
			if ( is_plugin_active( $file ) ) {
				$found[] = $label;
			}
		}

		return $found;
	}
}
