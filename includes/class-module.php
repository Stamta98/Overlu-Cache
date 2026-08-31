<?php
/**
 * Shared behaviour for modules.
 *
 * @package BricksCache
 */

namespace BricksCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives every module the container services and the enabled check, so a new
 * module is a boot() method and nothing else.
 */
abstract class Module implements Module_Interface {

	/**
	 * Container.
	 */
	protected Plugin $plugin;

	/**
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Settings service.
	 */
	protected function settings(): Settings {
		return $this->plugin->settings();
	}

	/**
	 * Logger service.
	 */
	protected function logger(): Logger {
		return $this->plugin->logger();
	}

	/**
	 * Declare this module's settings section. Called before anything reads the
	 * settings, so a module can add its own fields and get them stored,
	 * sanitised and rendered without touching the core.
	 */
	public function register_settings(): void {
	}

	/**
	 * Reads "<module id>.enabled" from the settings.
	 */
	public function is_enabled(): bool {
		return $this->settings()->on( $this->id() . '.enabled' );
	}

	/**
	 * One of this module's settings.
	 *
	 * @param string $field   Field name inside the module section.
	 * @param mixed  $default Fallback.
	 *
	 * @return mixed
	 */
	protected function setting( string $field, mixed $default = null ): mixed {
		return $this->settings()->get( $this->id() . '.' . $field, $default );
	}
}
