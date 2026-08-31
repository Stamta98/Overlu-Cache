<?php
/**
 * Public helpers. Third-party code and snippets should use these instead of
 * reaching into the classes directly.
 *
 * @package BricksCache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bricks_cache' ) ) {
	/**
	 * Container accessor.
	 */
	function bricks_cache(): BricksCache\Plugin {
		return BricksCache\Plugin::instance();
	}
}

if ( ! function_exists( 'bricks_cache_purge_all' ) ) {
	/**
	 * Drop every cached page.
	 *
	 * @param string $reason Free text stored in the log.
	 */
	function bricks_cache_purge_all( string $reason = 'manual' ): bool {
		return bricks_cache()->purge()->all( $reason );
	}
}

if ( ! function_exists( 'bricks_cache_purge_url' ) ) {
	/**
	 * Drop every variant cached for one URL.
	 *
	 * @param string $url Absolute URL.
	 */
	function bricks_cache_purge_url( string $url ): bool {
		return bricks_cache()->purge()->url( $url );
	}
}

if ( ! function_exists( 'bricks_cache_purge_post' ) ) {
	/**
	 * Drop the cache of a post and everything that lists it.
	 *
	 * @param int $post_id Post ID.
	 */
	function bricks_cache_purge_post( int $post_id ): bool {
		return bricks_cache()->purge()->post( $post_id );
	}
}

if ( ! function_exists( 'bricks_cache_is_hit' ) ) {
	/**
	 * Whether the current response was served from the cache by the drop-in.
	 * Always false inside PHP, since a hit never reaches WordPress. Kept for
	 * symmetry with templates that want to state the opposite.
	 */
	function bricks_cache_is_hit(): bool {
		return defined( 'BRICKS_CACHE_SERVED' ) && BRICKS_CACHE_SERVED;
	}
}
