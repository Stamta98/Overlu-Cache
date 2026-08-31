<?php
/**
 * Backend selection.
 *
 * @package BricksCache
 */

namespace BricksCache\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks the backend to use and reports what the server could offer. This
 * install has no Redis, Memcached or APCu, so the disk is both the default and
 * the only option; the detection stays here so the day one appears, the admin
 * says so instead of the plugin silently ignoring it.
 */
final class Factory {

	/**
	 * Build the active backend.
	 */
	public static function make(): Store_Interface {
		/**
		 * Filter the cache backend.
		 *
		 * @param Store_Interface $store Backend instance.
		 */
		$store = apply_filters( 'bricks_cache_store', new Disk_Store() );

		return $store instanceof Store_Interface ? $store : new Disk_Store();
	}

	/**
	 * In-memory backends this server could support.
	 *
	 * @return array<string,bool>
	 */
	public static function available_backends(): array {
		return [
			'disk'      => true,
			'redis'     => class_exists( 'Redis' ),
			'memcached' => class_exists( 'Memcached' ),
			'apcu'      => function_exists( 'apcu_enabled' ) && apcu_enabled(),
		];
	}
}
