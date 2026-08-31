<?php
/**
 * Contract every optimisation module follows.
 *
 * @package BricksCache
 */

namespace BricksCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A module is one optimisation: the page cache, and later the CSS pipeline,
 * the script loader, the image work and the preloader. The container boots
 * them, so none of them has to know the others exist.
 */
interface Module_Interface {

	/**
	 * Identifier, also the settings section this module reads.
	 */
	public function id(): string;

	/**
	 * Name shown in the admin, in Spanish.
	 */
	public function label(): string;

	/**
	 * Whether the user turned it on.
	 */
	public function is_enabled(): bool;

	/**
	 * Register hooks. Only called when the module is enabled.
	 */
	public function boot(): void;
}
