<?php
/**
 * Builds, names and cleans the combined stylesheets.
 *
 * @package BricksCache
 */

namespace BricksCache\Css;

use BricksCache\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A bundle is named after a fingerprint of what went into it, so it never
 * needs a version query string and never goes stale: change any source file
 * and the name changes with it.
 *
 * Old bundles are kept for a while on purpose. A page stored by the page cache
 * points at the bundle that existed when it was rendered, and deleting that
 * file the moment a new one appears would leave those visitors with a page
 * that has no styles at all.
 */
final class Bundle {

	/**
	 * Directory holding the generated files.
	 */
	public static function dir(): string {
		return Filesystem::dir( 'css' );
	}

	/**
	 * Build, or reuse, the bundle for a list of stylesheets.
	 *
	 * @param array<int,array<string,mixed>> $items  Collected stylesheets.
	 * @param bool                           $minify Whether to minify.
	 *
	 * @return array{url:string,path:string,hash:string,bytes:int}|null
	 */
	public function build( array $items, bool $minify = true ): ?array {
		$files = array_values( array_filter( $items, static fn( $item ) => ! empty( $item['path'] ) ) );

		if ( [] === $files ) {
			return null;
		}

		$hash = $this->fingerprint( $files, $minify );
		$path = self::dir() . '/' . $hash . '.css';
		$url  = self::to_url( $path );

		if ( null === $url ) {
			return null;
		}

		if ( is_readable( $path ) ) {
			return [
				'url'   => $url,
				'path'  => $path,
				'hash'  => $hash,
				'bytes' => (int) filesize( $path ),
			];
		}

		$css = '';

		foreach ( $files as $item ) {
			$contents = (string) @file_get_contents( (string) $item['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions

			if ( '' === trim( $contents ) ) {
				continue;
			}

			// Strip the BOM and any @charset: only the first one in a file is
			// valid, and a stray one in the middle invalidates the rule around it.
			$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents );
			$contents = (string) preg_replace( '/@charset\s+["\'][^"\']*["\'];/i', '', (string) $contents );

			$base = trailingslashit( dirname( (string) $item['src'] ) );

			$contents = Minifier::rewrite_urls( $contents, $base );
			$contents = $minify ? Minifier::minify( $contents ) : $contents;

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$css .= "\n/*! " . $item['handle'] . " */\n";
			}

			$css .= $contents . "\n";
		}

		if ( '' === trim( $css ) ) {
			return null;
		}

		if ( ! Filesystem::write( $path, $css ) ) {
			return null;
		}

		return [
			'url'   => $url,
			'path'  => $path,
			'hash'  => $hash,
			'bytes' => strlen( $css ),
		];
	}

	/**
	 * Name of the bundle: everything that could change its content.
	 *
	 * @param array<int,array<string,mixed>> $items  Collected stylesheets.
	 * @param bool                           $minify Whether minification is on.
	 */
	public function fingerprint( array $items, bool $minify ): string {
		$parts = [ BRICKS_CACHE_VERSION, $minify ? 'min' : 'raw' ];

		foreach ( $items as $item ) {
			$parts[] = $item['handle'] . '|' . $item['path'] . '|' . $item['mtime'] . '|' . $item['bytes'];
		}

		return substr( md5( implode( "\n", $parts ) ), 0, 16 );
	}

	/**
	 * Turn a local URL into a filesystem path, or null when it is not local.
	 *
	 * @param string $url Stylesheet URL.
	 */
	public static function to_path( string $url ): ?string {
		$url = strtok( $url, '?' );
		$url = (string) $url;

		if ( str_starts_with( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}

		$map = [
			content_url()  => untrailingslashit( WP_CONTENT_DIR ),
			includes_url() => untrailingslashit( ABSPATH . WPINC ),
			site_url( '/' ) => untrailingslashit( ABSPATH ),
		];

		foreach ( $map as $base_url => $base_path ) {
			$base_url = untrailingslashit( (string) $base_url );

			if ( str_starts_with( $url, $base_url ) ) {
				$path = $base_path . substr( $url, strlen( $base_url ) );

				return is_file( $path ) ? $path : null;
			}
		}

		// Root relative, e.g. /wp-content/themes/x/style.css
		if ( str_starts_with( $url, '/' ) ) {
			$path = untrailingslashit( ABSPATH ) . $url;

			return is_file( $path ) ? $path : null;
		}

		return null;
	}

	/**
	 * Turn a path inside the cache directory into a URL, or null when the
	 * cache lives somewhere the browser cannot reach.
	 *
	 * @param string $path Absolute path.
	 */
	public static function to_url( string $path ): ?string {
		$content_dir = untrailingslashit( WP_CONTENT_DIR );

		if ( str_starts_with( $path, $content_dir . '/' ) ) {
			return content_url( substr( $path, strlen( $content_dir ) ) );
		}

		$abspath = untrailingslashit( ABSPATH );

		if ( str_starts_with( $path, $abspath . '/' ) ) {
			return site_url( substr( $path, strlen( $abspath ) ) );
		}

		return null;
	}

	/**
	 * Delete generated files nobody has asked for in a while: bundles, their
	 * reduced versions, and the small indexes cached next to them. A file that
	 * is still in use is simply rebuilt the next time a page needs it, and no
	 * stored page can be older than its own lifetime, which is measured in
	 * hours rather than days.
	 *
	 * @param int $days How long an unused bundle is kept.
	 *
	 * @return int Files removed.
	 */
	public function collect_garbage( int $days = 7 ): int {
		$dir = self::dir();

		if ( $days <= 0 || ! is_dir( $dir ) ) {
			return 0;
		}

		$deadline = time() - ( $days * DAY_IN_SECONDS );
		$removed  = 0;

		foreach ( Filesystem::scan( $dir ) as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}

			// Reading a bundle updates its access time; a bundle no page has
			// linked in a week is not coming back.
			$last_used = max( (int) @fileatime( $file ), (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			if ( $last_used < $deadline && Filesystem::delete( $file ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Files and bytes currently generated.
	 *
	 * @return array{files:int,bytes:int,pages:int}
	 */
	public function stats(): array {
		return Filesystem::stats( self::dir() );
	}
}
