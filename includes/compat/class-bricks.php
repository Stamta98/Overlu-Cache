<?php
/**
 * Bricks builder compatibility.
 *
 * @package BricksCache
 */

namespace BricksCache\Compat;

use BricksCache\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bricks renders pages from data and writes the CSS it needs into files under
 * uploads/bricks/css. Two consequences for a cache.
 *
 * A saved template can change any page on the site, so there is no narrow
 * purge for it. And when Bricks regenerates its CSS files, every stored page
 * still links the old file names: the HTML is fine, the design is broken. Both
 * cases end in the same place, a full purge, which is why the setting for it
 * is one switch and not five.
 */
final class Bricks {

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
	 * Whether the Bricks theme is active.
	 */
	public static function is_active(): bool {
		return defined( 'BRICKS_VERSION' ) || class_exists( '\Bricks\Theme' );
	}

	/**
	 * Bricks version, for diagnostics.
	 */
	public static function version(): ?string {
		return defined( 'BRICKS_VERSION' ) ? (string) BRICKS_VERSION : null;
	}

	/**
	 * WordPress loads plugins before the theme, and Bricks is a theme. Asking
	 * whether Bricks exists while the plugin boots always answers no, and the
	 * design purges end up silently unregistered: nothing breaks, nothing is
	 * logged, and the site simply keeps serving pages styled by a CSS file that
	 * no longer exists.
	 *
	 * So the question is asked once the theme is in memory.
	 */
	public function boot(): void {
		add_action( 'after_setup_theme', [ $this, 'register' ], 20 );
	}

	/**
	 * Register the design-change hooks, with the theme already loaded.
	 */
	public function register(): void {
		if ( ! self::is_active() ) {
			return;
		}

		if ( ! $this->plugin->settings()->on( 'invalidation.on_design_change' ) ) {
			return;
		}

		// Fired by Bricks every time it writes a CSS file.
		add_action( 'bricks/generate_css_file', [ $this, 'on_css_generated' ] );

		add_action( 'updated_option', [ $this, 'on_updated_option' ] );
	}

	/**
	 * Bricks rewrote its CSS: the stored HTML points at file names that no
	 * longer exist.
	 */
	public function on_css_generated(): void {
		$this->plugin->purge()->queue_everything( 'bricks_css_generated' );
	}

	/**
	 * Global settings, theme styles, colours and global classes all restyle
	 * the whole site.
	 *
	 * @param string $option Option name.
	 */
	public function on_updated_option( $option ): void {
		$watched = [
			'bricks_global_settings',
			'bricks_theme_styles',
			'bricks_color_palette',
			'bricks_global_classes',
			'bricks_global_elements',
			'bricks_global_variables',
		];

		if ( in_array( (string) $option, $watched, true ) ) {
			$this->plugin->purge()->queue_everything( 'bricks_design' );
		}
	}
}
