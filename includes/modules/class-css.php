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
			$built = $bundle->build( $files, $minify );

			if ( null === $built ) {
				return;
			}

			$handle = self::HANDLE . '-' . sanitize_key( $media );

			foreach ( $items as $item ) {
				if ( ! empty( $item['path'] ) ) {
					$styles->dequeue( $item['handle'] );
				}
			}

			wp_enqueue_style( $handle, $built['url'], [], null, $media ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters

			$inline = $this->inline_of( $items );

			if ( '' !== $inline ) {
				wp_add_inline_style( $handle, $inline );
			}

			$this->own_handles[]         = $handle;
			$this->report['combinadas'] += count( $files );
			$this->report['bytes']      += (int) $built['bytes'];

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

			$this->own_handles[]         = $handle;
			$this->report['combinadas'] += 1;
			$this->report['bytes']      += (int) $built['bytes'];
		}
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
