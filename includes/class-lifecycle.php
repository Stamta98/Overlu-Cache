<?php
/**
 * Activation, deactivation and the state they leave behind.
 *
 * @package BricksCache
 */

namespace BricksCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A cache plugin has to be safe to switch off. Deactivating removes the
 * drop-in, the constant and the stored pages, because a site left with a
 * drop-in pointing at a disabled plugin serves pages nobody can purge.
 *
 * Activation deliberately does not turn the page cache on. The user enables it
 * from the settings, after reading what it does, on a store that is live.
 */
final class Lifecycle {

	/**
	 * Prepare the disk and the schedule.
	 */
	public static function activate(): void {
		Filesystem::prepare();

		if ( false === get_option( Settings::OPTION, false ) ) {
			update_option( Settings::OPTION, Settings::defaults(), true );
		}

		$settings = new Settings();

		( new Config( $settings ) )->write();

		update_option( 'bricks_cache_version', BRICKS_CACHE_VERSION, true );

		if ( ! wp_next_scheduled( 'bricks_cache_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'bricks_cache_cleanup' );
		}
	}

	/**
	 * Leave the site exactly as it was found: no drop-in, no constant, no
	 * stored pages, no scheduled task. Settings stay, so reactivating restores
	 * the configuration.
	 */
	public static function deactivate(): void {
		Dropin::uninstall();
		Dropin::set_wp_cache( false );

		Filesystem::empty_dir( Filesystem::dir( 'page' ) );

		wp_clear_scheduled_hook( 'bricks_cache_cleanup' );
	}
}
