<?php
/**
 * Runs when the plugin is deleted from the plugins screen.
 *
 * The plugin classes are not loaded here, so everything is done with plain
 * WordPress calls. Deleting means deleting: settings, cached pages, the log,
 * the drop-in and the scheduled task all go.
 *
 * @package BricksCache
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bricks_cache_settings' );
delete_option( 'bricks_cache_version' );
delete_option( 'bricks_cache_css_report' );
delete_transient( 'bricks_cache_notices' );

wp_clear_scheduled_hook( 'bricks_cache_cleanup' );

$bricks_cache_dropin = WP_CONTENT_DIR . '/advanced-cache.php';

if ( is_file( $bricks_cache_dropin ) ) {
	$bricks_cache_head = (string) file_get_contents( $bricks_cache_dropin, false, null, 0, 400 ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	if ( str_contains( $bricks_cache_head, 'Bricks Cache' ) ) {
		unlink( $bricks_cache_dropin );
	}
}

/**
 * Remove the cache directory tree.
 *
 * @param string $path Absolute path.
 */
function bricks_cache_uninstall_delete( string $path ): void {
	if ( is_file( $path ) || is_link( $path ) ) {
		unlink( $path );

		return;
	}

	if ( ! is_dir( $path ) ) {
		return;
	}

	foreach ( array_diff( (array) scandir( $path ), [ '.', '..' ] ) as $item ) {
		bricks_cache_uninstall_delete( $path . '/' . $item );
	}

	rmdir( $path );
}

bricks_cache_uninstall_delete( WP_CONTENT_DIR . '/cache/bricks-cache' );
