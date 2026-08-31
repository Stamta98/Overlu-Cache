<?php
/**
 * Class autoloader.
 *
 * @package BricksCache
 */

namespace BricksCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps namespaced class names to WordPress-style file names.
 *
 * BricksCache\Settings              -> includes/class-settings.php
 * BricksCache\Modules\Page_Cache    -> includes/modules/class-page-cache.php
 * BricksCache\Store\Store_Interface -> includes/store/interface-store.php
 */
final class Autoloader {

	/**
	 * Register the autoloader with SPL.
	 */
	public static function register(): void {
		spl_autoload_register( [ self::class, 'load' ] );
	}

	/**
	 * Resolve and require one class.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'BricksCache\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( 'BricksCache\\' ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );

		$directory = BRICKS_CACHE_PATH . 'includes/';

		foreach ( $parts as $part ) {
			$directory .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}

		$slug   = strtolower( str_replace( '_', '-', $name ) );
		$prefix = str_ends_with( $slug, '-interface' ) ? 'interface-' : 'class-';
		$slug   = str_ends_with( $slug, '-interface' ) ? substr( $slug, 0, -10 ) : $slug;

		$file = $directory . $prefix . $slug . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
