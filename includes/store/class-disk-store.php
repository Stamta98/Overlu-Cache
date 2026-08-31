<?php
/**
 * Disk backend.
 *
 * @package BricksCache
 */

namespace BricksCache\Store;

use BricksCache\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pages are plain files, which is what lets the drop-in serve a hit with two
 * filesystem calls and no database. The compressed copy is written next to the
 * plain one so the drop-in never has to compress during a request.
 */
final class Disk_Store implements Store_Interface {

	/**
	 * Identifier.
	 */
	public function id(): string {
		return 'disk';
	}

	/**
	 * Name shown in the admin.
	 */
	public function label(): string {
		return __( 'Disco', 'bricks-cache' );
	}

	/**
	 * The disk backend only needs a writable cache directory.
	 */
	public function is_available(): bool {
		return Filesystem::is_writable();
	}

	/**
	 * Write the page, its meta and, when asked for, its compressed twin.
	 *
	 * @param string              $base Absolute path without extension.
	 * @param string              $html Rendered HTML.
	 * @param array<string,mixed> $meta Extra data.
	 */
	public function store( string $base, string $html, array $meta = [] ): bool {
		if ( '' === trim( $html ) || ! Filesystem::is_inside_root( $base ) ) {
			return false;
		}

		if ( ! Filesystem::write( $base . '.html', $html ) ) {
			return false;
		}

		$meta = array_merge(
			[
				'created'      => time(),
				'content_type' => 'text/html; charset=UTF-8',
				'bytes'        => strlen( $html ),
			],
			$meta
		);

		Filesystem::write( $base . '.json', (string) wp_json_encode( $meta ) );

		if ( ! empty( $meta['gzip'] ) && function_exists( 'gzencode' ) ) {
			$compressed = gzencode( $html, 6 );

			if ( false !== $compressed ) {
				Filesystem::write( $base . '.html.gz', $compressed );
			}
		} else {
			Filesystem::delete( $base . '.html.gz' );
		}

		return true;
	}

	/**
	 * Whether a stored page exists.
	 *
	 * @param string $base Absolute path without extension.
	 */
	public function has( string $base ): bool {
		return is_readable( $base . '.html' );
	}

	/**
	 * Delete one URL directory with every variant inside it.
	 *
	 * @param string $directory Absolute path.
	 */
	public function forget( string $directory ): bool {
		if ( ! is_dir( $directory ) ) {
			return false;
		}

		// Only the variants of this URL: child directories are other URLs.
		foreach ( Filesystem::scan( $directory ) as $item ) {
			if ( is_file( $item ) ) {
				Filesystem::delete( $item );
			}
		}

		return true;
	}

	/**
	 * Delete every stored page, keeping the directory itself.
	 */
	public function flush(): bool {
		return Filesystem::empty_dir( Filesystem::dir( 'page' ) );
	}

	/**
	 * Remove pages past their lifetime.
	 *
	 * @param int $ttl Lifetime in seconds.
	 *
	 * @return int Pages removed.
	 */
	public function purge_expired( int $ttl ): int {
		$root = Filesystem::dir( 'page' );

		if ( $ttl <= 0 || ! is_dir( $root ) ) {
			return 0;
		}

		$deadline = time() - $ttl;
		$removed  = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			if ( $file->isDir() ) {
				// Prune directories left empty by the deletions above.
				@rmdir( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

				continue;
			}

			if ( ! str_ends_with( $file->getFilename(), '.html' ) || $file->getMTime() > $deadline ) {
				continue;
			}

			$base = substr( $file->getPathname(), 0, -5 );

			Filesystem::delete( $base . '.html' );
			Filesystem::delete( $base . '.html.gz' );
			Filesystem::delete( $base . '.json' );

			++$removed;
		}

		return $removed;
	}

	/**
	 * Files, bytes and pages currently stored.
	 *
	 * @return array{files:int,bytes:int,pages:int}
	 */
	public function stats(): array {
		return Filesystem::stats( Filesystem::dir( 'page' ) );
	}
}
