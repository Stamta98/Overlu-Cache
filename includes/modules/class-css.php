<?php
/**
 * CSS module: minify, combine and, when there is critical CSS, stop blocking.
 *
 * @package BricksCache
 */

namespace BricksCache\Modules;

use BricksCache\Css\Bundle;
use BricksCache\Css\Collector;
use BricksCache\Css\Critical;
use BricksCache\Diagnostics;
use BricksCache\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This site loads 23 stylesheets on every page, 583 KB of them, every one
 * render blocking, and one file queued twice under two handles. Merging them
 * is worth more than shrinking them: the browser stops opening 23 connections
 * before it can paint anything.
 *
 * Order is everything here. WordPress already resolved it, so the queue is
 * read in the order it would have printed, and the bundle keeps that order.
 * Inline styles added with wp_add_inline_style() are carried across and
 * re-printed in place: dropping them is how a combined stylesheet ends up
 * almost right, which is the hardest kind of wrong to see.
 */
final class Css extends Module {

	/**
	 * Prefix of the handles this module enqueues.
	 */
	private const HANDLE = 'bricks-cache-css';

	/**
	 * Handles this module printed, for the async filter.
	 *
	 * @var string[]
	 */
	private array $own_handles = [];

	/**
	 * Handles whose content already travels inside a bundle.
	 *
	 * @var string[]
	 */
	private array $bundled_handles = [];

	/**
	 * Files already inside a bundle. The same file is queued under two handles
	 * more often than anyone would guess, and the second handle deserves the
	 * same treatment as the first.
	 *
	 * @var string[]
	 */
	private array $bundled_paths = [];

	/**
	 * Numbers for the status screen.
	 *
	 * @var array<string,int>
	 */
	private array $report = [
		'combinadas' => 0,
		'bytes'      => 0,
		'omitidas'   => 0,
	];

	/**
	 * Settings section and identifier.
	 */
	public function id(): string {
		return 'css';
	}

	/**
	 * Name shown in the admin.
	 */
	public function label(): string {
		return __( 'CSS', 'bricks-cache' );
	}

	/**
	 * Declare this module's settings section.
	 */
	public function register_settings(): void {
		add_filter( 'bricks_cache_settings_schema', [ $this, 'settings_schema' ] );
	}

	/**
	 * Fields of the CSS section.
	 *
	 * @param array<string,array<string,mixed>> $schema Sections so far.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function settings_schema( array $schema ): array {
		$fields = [
			'enabled'          => [
				'type'        => 'toggle',
				'label'       => __( 'Optimizar el CSS', 'bricks-cache' ),
				'description' => __( 'Combina y minifica las hojas de estilo locales en un único archivo por tipo de medio.', 'bricks-cache' ),
				'default'     => false,
			],
			'combine'          => [
				'type'        => 'toggle',
				'label'       => __( 'Combinar en un solo archivo', 'bricks-cache' ),
				'description' => __( 'De 23 peticiones bloqueantes a una. Si algo se ve raro, desactiva esto antes que nada: seguirás teniendo la minificación.', 'bricks-cache' ),
				'default'     => true,
			],
			'minify'           => [
				'type'        => 'toggle',
				'label'       => __( 'Minificar', 'bricks-cache' ),
				'description' => __( 'Quita comentarios y espacios sobrantes. No toca lo que hay dentro de calc(), de las comillas ni de url().', 'bricks-cache' ),
				'default'     => true,
			],
			'async'            => [
				'type'        => 'toggle',
				'label'       => __( 'Cargar el CSS sin bloquear el dibujado', 'bricks-cache' ),
				'description' => __( 'Solo se aplica en las páginas para las que hayas escrito CSS crítico más abajo. Sin él la página aparecería un instante sin estilos.', 'bricks-cache' ),
				'default'     => false,
			],
			'keep_days'        => [
				'type'        => 'number',
				'label'       => __( 'Días que se guardan los archivos antiguos', 'bricks-cache' ),
				'description' => __( 'Las páginas ya guardadas en caché siguen apuntando al archivo con el que se generaron, así que no se borra de inmediato.', 'bricks-cache' ),
				'default'     => 7,
				'min'         => 1,
				'max'         => 90,
			],
			'exclude_handles'  => [
				'type'        => 'list',
				'label'       => __( 'Hojas que no se tocan (por identificador)', 'bricks-cache' ),
				'description' => __( 'Una por línea, el identificador sin el sufijo -css. Por ejemplo: jet-search', 'bricks-cache' ),
				'default'     => [],
			],
			'exclude_patterns' => [
				'type'        => 'list',
				'label'       => __( 'Hojas que no se tocan (por URL)', 'bricks-cache' ),
				'description' => __( 'Una por línea. Basta con un trozo de la ruta del archivo.', 'bricks-cache' ),
				'default'     => [],
			],
		];

		foreach ( Critical::CONTEXTS as $context ) {
			$fields[ 'critical_' . $context ] = [
				'type'    => 'code',
				'label'   => sprintf(
					/* translators: %s: kind of page. */
					__( 'CSS crítico · %s', 'bricks-cache' ),
					Critical::label( $context )
				),
				'default' => '',
			];
		}

		$schema['css'] = [
			'label'       => __( 'CSS', 'bricks-cache' ),
			'description' => __( 'Las hojas externas, las condicionales y las que usan @import se dejan como están. Después de guardar, comprueba la portada, una categoría y una ficha de producto.', 'bricks-cache' ),
			'fields'      => $fields,
		];

		return $schema;
	}

	/**
	 * Hook into the style queue.
	 */
	public function boot(): void {
		add_action( 'wp_print_styles', [ $this, 'optimize' ], 1 );
		add_action( 'wp_head', [ $this, 'print_critical' ], 1 );
		add_filter( 'style_loader_tag', [ $this, 'suppress_bundled_tag' ], 9, 2 );
		add_filter( 'style_loader_tag', [ $this, 'maybe_async_tag' ], 10, 2 );
		add_action( 'bricks_cache_cleanup', [ $this, 'collect_garbage' ] );
		add_filter( 'bricks_cache_diagnostics', [ $this, 'diagnostics' ] );
	}

	/**
	 * Replace the queued stylesheets with the generated ones.
	 */
	public function optimize(): void {
		if ( ! $this->should_run() ) {
			return;
		}

		$styles = wp_styles();

		if ( ! $styles instanceof \WP_Styles ) {
			return;
		}

		$collector = new Collector(
			(array) $this->setting( 'exclude_handles', [] ),
			(array) $this->setting( 'exclude_patterns', [] )
		);

		$groups = $collector->collect( $styles );

		if ( [] === $groups ) {
			return;
		}

		$bundle  = new Bundle();
		$combine = (bool) $this->setting( 'combine', true );
		$minify  = (bool) $this->setting( 'minify', true );

		// Anything already resolved into to_do would be printed even after
		// being dequeued. Emptying it forces WordPress to read the queue again,
		// which is where the dequeues actually happen.
		$styles->to_do = [];

		foreach ( $groups as $media => $items ) {
			$this->replace_group( $styles, $bundle, (string) $media, $items, $combine, $minify );
		}

		$this->report['omitidas'] = count( $collector->skipped() );

		$this->logger()->debug(
			'CSS optimised.',
			[
				'combined' => $this->report['combinadas'],
				'bytes'    => $this->report['bytes'],
				'skipped'  => $collector->skipped(),
			]
		);
	}

	/**
	 * Swap one media group for its generated file, or files.
	 *
	 * @param \WP_Styles                     $styles  Style registry.
	 * @param Bundle                         $bundle  Bundle builder.
	 * @param string                         $media   Media attribute.
	 * @param array<int,array<string,mixed>> $items   Collected stylesheets.
	 * @param bool                           $combine Whether to merge them.
	 * @param bool                           $minify  Whether to minify.
	 */
	private function replace_group( \WP_Styles $styles, Bundle $bundle, string $media, array $items, bool $combine, bool $minify ): void {
		$files = array_values( array_filter( $items, static fn( $item ) => ! empty( $item['path'] ) ) );

		if ( [] === $files ) {
			// Nothing but inline styles: they are already attached to handles
			// that stay in the queue.
			return;
		}

		if ( $combine ) {
			$runs  = $this->split_into_runs( $items );
			$index = 0;

			foreach ( $runs as $run ) {
				$run_files = array_values( array_filter( $run['items'], static fn( $item ) => ! empty( $item['path'] ) ) );

				if ( [] === $run_files ) {
					continue;
				}

				$built = $bundle->build( $run_files, $minify );

				if ( null === $built ) {
					continue;
				}

				++$index;

				$handle = self::HANDLE . '-' . sanitize_key( $media ) . '-' . $index;

				foreach ( $run_files as $item ) {
					$styles->dequeue( (string) $item['handle'] );

					$this->bundled_handles[] = (string) $item['handle'];
					$this->bundled_paths[]   = (string) $item['path'];
				}

				wp_enqueue_style( $handle, $built['url'], [], null, $media ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters

				$inline = $this->inline_of( $run['items'] );

				if ( '' !== $inline ) {
					wp_add_inline_style( $handle, $inline );
				}

				$this->own_handles[]         = $handle;
				$this->report['combinadas'] += count( $run_files );
				$this->report['bytes']      += (int) $built['bytes'];
			}

			return;
		}

		// Minify only: every sheet keeps its own file and its own place.
		foreach ( $items as $item ) {
			if ( empty( $item['path'] ) ) {
				continue;
			}

			$built = $bundle->build( [ $item ], $minify );

			if ( null === $built ) {
				continue;
			}

			$handle = self::HANDLE . '-' . sanitize_key( (string) $item['handle'] );

			$styles->dequeue( (string) $item['handle'] );

			wp_enqueue_style( $handle, $built['url'], [], null, $media ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters

			if ( '' !== (string) $item['inline'] ) {
				wp_add_inline_style( $handle, (string) $item['inline'] );
			}

			$this->bundled_handles[]     = (string) $item['handle'];
			$this->bundled_paths[]       = (string) $item['path'];
			$this->own_handles[]         = $handle;
			$this->report['combinadas'] += 1;
			$this->report['bytes']      += (int) $built['bytes'];
		}
	}

	/**
	 * Split an ordered list of stylesheets into consecutive runs of the same
	 * kind: the ones every page shares, and the ones Bricks writes for this
	 * particular page.
	 *
	 * A single bundle per page would be the obvious thing to build and the
	 * wrong one: every page type would get its own half-megabyte file with
	 * nearly the same content, so moving from the home page to a product page
	 * would download all of it again. Splitting by kind gives the shared runs
	 * the same fingerprint on every page, so the browser downloads them once
	 * for the whole site.
	 *
	 * Splitting by *runs* rather than by kind is what keeps the cascade intact:
	 * the order inside a media group never changes, so a rule that used to win
	 * still wins.
	 *
	 * @param array<int,array<string,mixed>> $items Collected stylesheets.
	 *
	 * @return array<int,array{page:bool,items:array<int,array<string,mixed>>}>
	 */
	private function split_into_runs( array $items ): array {
		$runs    = [];
		$current = null;

		foreach ( $items as $item ) {
			$is_page = $this->is_page_specific( $item );

			if ( null === $current || $current['page'] !== $is_page ) {
				if ( null !== $current ) {
					$runs[] = $current;
				}

				$current = [
					'page'  => $is_page,
					'items' => [],
				];
			}

			$current['items'][] = $item;
		}

		if ( null !== $current ) {
			$runs[] = $current;
		}

		return $runs;
	}

	/**
	 * Whether a stylesheet belongs to one page only. Bricks writes one CSS file
	 * per post, template and popup, and those are the ones that change from
	 * page to page.
	 *
	 * @param array<string,mixed> $item Collected stylesheet.
	 */
	private function is_page_specific( array $item ): bool {
		$handle = (string) $item['handle'];
		$path   = (string) $item['path'];

		$page_specific = str_starts_with( $handle, 'bricks-post-' )
			|| (bool) preg_match( '#/bricks/css/post-\d+#', $path );

		/**
		 * Filter whether a stylesheet is specific to one page.
		 *
		 * @param bool                $page_specific Guess so far.
		 * @param array<string,mixed> $item          Collected stylesheet.
		 */
		return (bool) apply_filters( 'bricks_cache_css_is_page_specific', $page_specific, $item );
	}

	/**
	 * Inline CSS of a group, in the order it was queued.
	 *
	 * @param array<int,array<string,mixed>> $items Collected stylesheets.
	 */
	private function inline_of( array $items ): string {
		$inline = [];

		foreach ( $items as $item ) {
			if ( '' !== (string) $item['inline'] ) {
				$inline[] = (string) $item['inline'];
			}
		}

		return implode( "\n", $inline );
	}

	/**
	 * Print the critical CSS before anything else in the head.
	 */
	public function print_critical(): void {
		if ( ! $this->should_run() || ! $this->async_allowed() ) {
			return;
		}

		$critical = ( new Critical( $this->settings() ) )->css();

		printf(
			"<style id=\"bricks-cache-critico\">%s</style>\n",
			wp_strip_all_tags( $critical ) // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	/**
	 * Drop the tag of a stylesheet that is already inside a bundle.
	 *
	 * Bricks enqueues stylesheets while it renders — the header template, the
	 * footer, a popup, a slider — long after the head has been printed, and
	 * WordPress prints those at the end of the body. The handle was already
	 * bundled, so what arrives late is a second copy of CSS the page has:
	 * dequeuing it earlier cannot help, because the enqueue happens afterwards.
	 *
	 * @param string $tag    Link tag.
	 * @param string $handle Style handle.
	 */
	public function suppress_bundled_tag( string $tag, string $handle ): string {
		if ( in_array( $handle, $this->bundled_handles, true ) ) {
			return '';
		}

		if ( ! preg_match( '/href=[\'"]([^\'"]+)[\'"]/', $tag, $match ) ) {
			return $tag;
		}

		$path = Bundle::to_path( $match[1] );

		return null !== $path && in_array( $path, $this->bundled_paths, true ) ? '' : $tag;
	}

	/**
	 * Turn our own link tags into non-blocking ones.
	 *
	 * @param string $tag    Link tag.
	 * @param string $handle Style handle.
	 */
	public function maybe_async_tag( string $tag, string $handle ): string {
		if ( ! in_array( $handle, $this->own_handles, true ) || ! $this->async_allowed() ) {
			return $tag;
		}

		$async = str_replace(
			"rel='stylesheet'",
			"rel='stylesheet' media='print' onload=\"this.media='all';this.onload=null\"",
			$tag
		);

		if ( $async === $tag ) {
			return $tag;
		}

		return $async . '<noscript>' . $tag . '</noscript>' . "\n";
	}

	/**
	 * Asynchronous loading is only safe where there is critical CSS to paint
	 * with in the meantime.
	 */
	private function async_allowed(): bool {
		if ( ! $this->setting( 'async', false ) ) {
			return false;
		}

		return ( new Critical( $this->settings() ) )->has_css();
	}

	/**
	 * Delete generated files nobody links any more.
	 */
	public function collect_garbage(): void {
		$removed = ( new Bundle() )->collect_garbage( (int) $this->setting( 'keep_days', 7 ) );

		if ( $removed > 0 ) {
			$this->logger()->info( 'Old CSS bundles removed.', [ 'count' => $removed ] );
		}
	}

	/**
	 * Add the generated size to the status screen.
	 *
	 * @param array<int,array{label:string,status:string,message:string}> $checks Checks.
	 *
	 * @return array<int,array{label:string,status:string,message:string}>
	 */
	public function diagnostics( array $checks ): array {
		$stats = ( new Bundle() )->stats();

		$checks[] = [
			'label'   => __( 'CSS generado', 'bricks-cache' ),
			'status'  => Diagnostics::OK,
			'message' => sprintf(
				/* translators: 1: number of files, 2: formatted size. */
				__( '%1$s archivos, %2$s en disco.', 'bricks-cache' ),
				number_format_i18n( $stats['files'] ),
				size_format( $stats['bytes'], 2 )
			),
		];

		return $checks;
	}

	/**
	 * Never touch the builder, the customiser or the admin: Bricks needs the
	 * original stylesheets to edit with, and a bundle would hide them.
	 */
	private function should_run(): bool {
		if ( is_admin() || is_feed() || is_customize_preview() ) {
			return false;
		}

		if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['bricks'] ) || isset( $_GET['brickspreview'] ) ) {
			return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification

		/**
		 * Filter whether the CSS module runs on this request.
		 *
		 * @param bool $run Whether to optimise.
		 */
		return (bool) apply_filters( 'bricks_cache_optimize_css', true );
	}
}
