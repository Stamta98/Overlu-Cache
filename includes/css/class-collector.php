<?php
/**
 * Reads the stylesheet queue and decides what can be bundled.
 *
 * @package BricksCache
 */

namespace BricksCache\Css;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress already knows the right order for the stylesheets: it resolved the
 * dependency graph to print them. This class asks for that order and then
 * removes from it everything that must not be touched — external files, files
 * that use @import, conditional stylesheets and whatever the user excluded.
 *
 * What is left is grouped by media attribute, because merging a print
 * stylesheet into the screen one would apply it to the screen.
 */
final class Collector {

	/**
	 * Handles the user excluded.
	 *
	 * @var string[]
	 */
	private array $exclude_handles;

	/**
	 * URL fragments the user excluded.
	 *
	 * @var string[]
	 */
	private array $exclude_patterns;

	/**
	 * Handles left alone, with the reason, for the admin.
	 *
	 * @var array<string,string>
	 */
	private array $skipped = [];

	/**
	 * @param string[] $exclude_handles  Handles to leave alone.
	 * @param string[] $exclude_patterns URL fragments to leave alone.
	 */
	public function __construct( array $exclude_handles = [], array $exclude_patterns = [] ) {
		$this->exclude_handles  = array_map( 'strval', $exclude_handles );
		$this->exclude_patterns = array_map( 'strval', $exclude_patterns );
	}

	/**
	 * Walk the queue in dependency order.
	 *
	 * @param \WP_Styles $styles Style registry.
	 *
	 * @return array<string,array<int,array<string,mixed>>> Items grouped by media.
	 */
	public function collect( \WP_Styles $styles ): array {
		$this->skipped = [];

		// all_deps() appends to $styles->to_do, and do_items() prints whatever
		// is in to_do rather than re-reading the queue. Resolving the order on
		// the real object would therefore pin every stylesheet in place: the
		// later dequeue would remove it from the queue and it would be printed
		// anyway, next to the bundle that contains it. So the order is resolved
		// on a copy and the registry is left untouched.
		$registry        = clone $styles;
		$registry->to_do = [];
		$registry->all_deps( $registry->queue );

		$groups = [];
		$seen   = [];

		foreach ( $registry->to_do as $handle ) {
			$item = $styles->registered[ $handle ] ?? null;

			if ( ! $item instanceof \_WP_Dependency ) {
				continue;
			}

			$inline = $this->inline_for( $registry, $handle );

			if ( ! $item->src ) {
				// An alias handle: no file, but it may carry inline styles that
				// would disappear with it.
				if ( '' !== $inline ) {
					$groups['all'][] = $this->make_item( $handle, null, null, 'all', $inline );
				}

				continue;
			}

			$reason = $this->skip_reason( $registry, $item );

			if ( null !== $reason ) {
				$this->skipped[ $handle ] = $reason;

				continue;
			}

			$src  = $this->resolve_src( $registry, $item );
			$path = Bundle::to_path( $src );

			if ( null === $path ) {
				$this->skipped[ $handle ] = 'externa';

				continue;
			}

			$contents = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions

			if ( str_contains( $contents, '@import' ) ) {
				// @import only works at the top of a file. Merging such a sheet
				// into a bundle silently drops whatever it imported.
				$this->skipped[ $handle ] = 'usa @import';

				continue;
			}

			if ( isset( $seen[ $path ] ) ) {
				// The same file queued twice under two handles. It happens more
				// often than it should, and it is a free request to remove.
				$this->skipped[ $handle ] = 'duplicada de ' . $seen[ $path ];

				if ( '' !== $inline ) {
					$groups['all'][] = $this->make_item( $handle, null, null, 'all', $inline );
				}

				continue;
			}

			$seen[ $path ] = $handle;

			$media            = $this->media_for( $item );
			$groups[ $media ] = $groups[ $media ] ?? [];
			$groups[ $media ][] = $this->make_item( $handle, $src, $path, $media, $inline );
		}

		return $groups;
	}

	/**
	 * Handles that were left in the queue, and why.
	 *
	 * @return array<string,string>
	 */
	public function skipped(): array {
		return $this->skipped;
	}

	/**
	 * Build one queue entry.
	 *
	 * @param string      $handle Handle.
	 * @param string|null $src    Absolute URL, null for inline-only entries.
	 * @param string|null $path   Absolute path, null for inline-only entries.
	 * @param string      $media  Media attribute.
	 * @param string      $inline Inline CSS attached to this handle.
	 *
	 * @return array<string,mixed>
	 */
	private function make_item( string $handle, ?string $src, ?string $path, string $media, string $inline ): array {
		return [
			'handle' => $handle,
			'src'    => $src,
			'path'   => $path,
			'media'  => $media,
			'inline' => $inline,
			'mtime'  => null !== $path && is_file( $path ) ? (int) filemtime( $path ) : 0,
			'bytes'  => null !== $path && is_file( $path ) ? (int) filesize( $path ) : 0,
		];
	}

	/**
	 * Why a stylesheet must stay where it is, or null when it may be bundled.
	 *
	 * @param \WP_Styles       $styles Style registry.
	 * @param \_WP_Dependency  $item   Registered stylesheet.
	 */
	private function skip_reason( \WP_Styles $styles, \_WP_Dependency $item ): ?string {
		if ( in_array( $item->handle, $this->exclude_handles, true ) ) {
			return 'excluida en los ajustes';
		}

		if ( $styles->get_data( $item->handle, 'conditional' ) ) {
			return 'condicional';
		}

		if ( isset( $item->extra['alt'] ) && $item->extra['alt'] ) {
			return 'hoja alternativa';
		}

		$src = (string) $item->src;

		foreach ( $this->exclude_patterns as $pattern ) {
			if ( '' !== trim( $pattern ) && str_contains( $src, $pattern ) ) {
				return 'excluida en los ajustes';
			}
		}

		return null;
	}

	/**
	 * Absolute URL of a stylesheet, the way WordPress would print it.
	 *
	 * @param \WP_Styles      $styles Style registry.
	 * @param \_WP_Dependency $item   Registered stylesheet.
	 */
	private function resolve_src( \WP_Styles $styles, \_WP_Dependency $item ): string {
		$src = (string) $item->src;

		if ( ! preg_match( '|^(https?:)?//|', $src ) && ! ( $styles->content_url && str_starts_with( $src, $styles->content_url ) ) ) {
			$src = $styles->base_url . $src;
		}

		return $src;
	}

	/**
	 * Media attribute, defaulting to all.
	 *
	 * @param \_WP_Dependency $item Registered stylesheet.
	 */
	private function media_for( \_WP_Dependency $item ): string {
		$media = is_string( $item->args ) && '' !== $item->args ? $item->args : 'all';

		return strtolower( $media );
	}

	/**
	 * Inline CSS attached to a handle with wp_add_inline_style(). Losing it is
	 * the classic way a combined stylesheet ends up almost right.
	 *
	 * @param \WP_Styles $styles Style registry.
	 * @param string     $handle Handle.
	 */
	private function inline_for( \WP_Styles $styles, string $handle ): string {
		$after = $styles->get_data( $handle, 'after' );

		if ( ! $after ) {
			return '';
		}

		return trim( implode( "\n", array_map( 'strval', (array) $after ) ) );
	}
}
