<?php
/**
 * Disk access for everything the plugin writes.
 *
 * @package BricksCache
 */

namespace BricksCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All writes go through here so paths, permissions and atomicity are decided
 * in one place. Nothing else in the plugin calls file_put_contents().
 */
final class Filesystem {

	/**
	 * Absolute path of the cache root, without a trailing slash.
	 */
	public static function root(): string {
		/**
		 * Filter the directory where the plugin stores everything.
		 *
		 * @param string $root Absolute path without trailing slash.
		 */
		$root = apply_filters( 'bricks_cache_root_dir', WP_CONTENT_DIR . '/cache/bricks-cache' );

		return untrailingslashit( (string) $root );
	}

	/**
	 * Absolute path of one of the plugin directories.
	 *
	 * @param string $sub One of: page, config, logs, assets.
	 */
	public static function dir( string $sub = '' ): string {
		$path = self::root() . ( '' === $sub ? '' : '/' . trim( $sub, '/' ) );

		return untrailingslashit( $path );
	}

	/**
	 * Create the directory tree the plugin needs, with the usual guards so a
	 * misconfigured server cannot list or execute what is inside.
	 */
	public static function prepare(): bool {
		$ok = true;

		foreach ( [ '', 'page', 'config', 'logs' ] as $sub ) {
			$ok = self::ensure_dir( self::dir( $sub ) ) && $ok;
		}

		self::write( self::dir() . '/index.php', "<?php\n// Silence is golden.\n" );
		self::write( self::dir( 'logs' ) . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		self::write( self::dir( 'config' ) . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );

		return $ok;
	}

	/**
	 * Create a directory if it is missing.
	 *
	 * @param string $path Absolute path.
	 */
	public static function ensure_dir( string $path ): bool {
		if ( is_dir( $path ) ) {
			return true;
		}

		return wp_mkdir_p( $path );
	}

	/**
	 * Write a file atomically: the reader either sees the old content or the
	 * new one, never half a page. A partially written HTML file served to a
	 * customer is worse than no cache at all.
	 *
	 * @param string $file     Absolute path.
	 * @param string $contents File body.
	 */
	public static function write( string $file, string $contents ): bool {
		if ( ! self::ensure_dir( dirname( $file ) ) ) {
			return false;
		}

		$temp = $file . '.' . wp_generate_password( 6, false ) . '.tmp';

		if ( false === @file_put_contents( $temp, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}

		@chmod( $temp, self::file_permissions() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! @rename( $temp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			return false;
		}

		return true;
	}

	/**
	 * Append a line to a file, creating it when needed.
	 *
	 * @param string $file Absolute path.
	 * @param string $line Line including its own newline.
	 */
	public static function append( string $file, string $line ): bool {
		if ( ! self::ensure_dir( dirname( $file ) ) ) {
			return false;
		}

		return false !== @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Delete a file or a whole directory tree, staying inside the cache root.
	 *
	 * @param string $path Absolute path.
	 */
	public static function delete( string $path ): bool {
		if ( ! self::is_inside_root( $path ) ) {
			return false;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		if ( ! is_dir( $path ) ) {
			return true;
		}

		foreach ( self::scan( $path ) as $item ) {
			self::delete( $item );
		}

		return @rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Empty a directory without removing the directory itself.
	 *
	 * @param string $path Absolute path.
	 */
	public static function empty_dir( string $path ): bool {
		if ( ! self::is_inside_root( $path ) || ! is_dir( $path ) ) {
			return false;
		}

		foreach ( self::scan( $path ) as $item ) {
			self::delete( $item );
		}

		return true;
	}

	/**
	 * Direct children of a directory as absolute paths.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return string[]
	 */
	public static function scan( string $path ): array {
		$items = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $items ) {
			return [];
		}

		$out = [];

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$out[] = $path . '/' . $item;
		}

		return $out;
	}

	/**
	 * File count and total size of a tree.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return array{files:int,bytes:int,pages:int}
	 */
	public static function stats( string $path ): array {
		$stats = [
			'files' => 0,
			'bytes' => 0,
			'pages' => 0,
		];

		if ( ! is_dir( $path ) ) {
			return $stats;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			if ( ! $file->isFile() ) {
				continue;
			}

			++$stats['files'];
			$stats['bytes'] += (int) $file->getSize();

			if ( str_ends_with( $file->getFilename(), '.html' ) ) {
				++$stats['pages'];
			}
		}

		return $stats;
	}

	/**
	 * Guard against deleting anything outside the cache root, however the path
	 * was built.
	 *
	 * @param string $path Absolute path.
	 */
	public static function is_inside_root( string $path ): bool {
		$root = self::root();
		$path = rtrim( $path, '/' );

		if ( '' === $path || $root === $path ) {
			return $root === $path;
		}

		return str_starts_with( $path, $root . '/' ) && ! str_contains( $path, '/../' );
	}

	/**
	 * Permissions for written files, honouring FS_CHMOD_FILE when defined.
	 */
	public static function file_permissions(): int {
		return defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 );
	}

	/**
	 * Whether the cache root can be written to right now.
	 */
	public static function is_writable(): bool {
		$root = self::root();

		if ( ! is_dir( $root ) ) {
			return wp_is_writable( dirname( $root ) );
		}

		return wp_is_writable( $root );
	}
}
