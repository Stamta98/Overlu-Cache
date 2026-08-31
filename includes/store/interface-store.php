<?php
/**
 * Contract for a cache backend.
 *
 * @package BricksCache
 */

namespace BricksCache\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Today there is one backend, the disk, because this server has no Redis,
 * Memcached or APCu. The interface exists so adding one later is a new class
 * and a factory line, not a rewrite of the page cache.
 *
 * A backend addressed by the drop-in must be readable without WordPress: any
 * future implementation has to be reachable from plain PHP as well.
 */
interface Store_Interface {

	/**
	 * Short identifier, used in settings and diagnostics.
	 */
	public function id(): string;

	/**
	 * Human readable name for the admin, in Spanish.
	 */
	public function label(): string;

	/**
	 * Whether this backend can be used on this server right now.
	 */
	public function is_available(): bool;

	/**
	 * Save one rendered page.
	 *
	 * @param string               $base Absolute path without extension, from Key::file_base().
	 * @param string               $html Rendered HTML.
	 * @param array<string,mixed>  $meta Extra data stored next to the page.
	 */
	public function store( string $base, string $html, array $meta = [] ): bool;

	/**
	 * Whether a page exists for this base path.
	 *
	 * @param string $base Absolute path without extension.
	 */
	public function has( string $base ): bool;

	/**
	 * Delete every variant stored for one URL.
	 *
	 * @param string $directory Absolute directory path from Key::directory().
	 */
	public function forget( string $directory ): bool;

	/**
	 * Delete everything.
	 */
	public function flush(): bool;

	/**
	 * Delete entries older than the given lifetime.
	 *
	 * @param int $ttl Lifetime in seconds. 0 disables the cleanup.
	 *
	 * @return int Number of pages removed.
	 */
	public function purge_expired( int $ttl ): int;

	/**
	 * Size of the cache.
	 *
	 * @return array{files:int,bytes:int,pages:int}
	 */
	public function stats(): array;
}
