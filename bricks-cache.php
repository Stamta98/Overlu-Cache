<?php
/**
 * Plugin Name: Bricks Cache
 * Plugin URI:  https://overlu.com/
 * Description: Caché de página y optimización de recursos para tiendas construidas con Bricks + WooCommerce. Pensada para funcionar junto a Bricks Ecommerce y Overlu Marketplace.
 * Version:     0.2.1
 * Author:      Overlu
 * Text Domain: bricks-cache
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.0
 *
 * @package BricksCache
 *
 * Boot order matters here. This file only declares constants, registers the
 * autoloader and the lifecycle hooks, and hands control to the container on
 * `plugins_loaded`. Nothing that touches the theme may be required from this
 * file: plugins load before the theme, so a class extending a Bricks class
 * would fatal the whole site (frontend, wp-admin and REST at once).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BRICKS_CACHE_VERSION', '0.2.1' );
define( 'BRICKS_CACHE_FILE', __FILE__ );
define( 'BRICKS_CACHE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BRICKS_CACHE_URL', plugin_dir_url( __FILE__ ) );
define( 'BRICKS_CACHE_BASENAME', plugin_basename( __FILE__ ) );

/** Bumped whenever dropin/advanced-cache.php changes, to trigger a reinstall. */
define( 'BRICKS_CACHE_DROPIN_VERSION', '1' );

define( 'BRICKS_CACHE_MIN_PHP', '8.0' );
define( 'BRICKS_CACHE_MIN_WP', '6.4' );

require_once BRICKS_CACHE_PATH . 'includes/class-autoloader.php';
BricksCache\Autoloader::register();

require_once BRICKS_CACHE_PATH . 'includes/functions.php';

register_activation_hook( __FILE__, [ 'BricksCache\Lifecycle', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'BricksCache\Lifecycle', 'deactivate' ] );

/**
 * Boot the container once every plugin is in memory, but before the theme
 * renders anything. Priority 5 keeps the page cache ahead of output-producing
 * plugins without racing WooCommerce, which initialises on `plugins_loaded` 10.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! BricksCache\Requirements::are_met() ) {
			add_action( 'admin_notices', [ 'BricksCache\Requirements', 'render_notice' ] );

			return;
		}

		BricksCache\Plugin::instance()->boot();
	},
	5
);
