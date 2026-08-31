<?php
/**
 * Above-the-fold CSS per kind of page.
 *
 * @package BricksCache
 */

namespace BricksCache\Css;

use BricksCache\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generating critical CSS automatically means rendering the page in a real
 * browser and asking it what was visible. There is no browser on this server,
 * and guessing produces a page that flashes unstyled — worse than not trying.
 *
 * So the CSS is written by hand, once per kind of page, and pasted into the
 * settings. Five boxes cover a shop: portada, catálogo, ficha, contenido y el
 * resto. Without one for the current kind of page, the asynchronous load stays
 * off for that page, which is the safe direction to fail in.
 */
final class Critical {

	/**
	 * Kinds of page that can have their own critical CSS.
	 *
	 * @var string[]
	 */
	public const CONTEXTS = [ 'home', 'shop', 'product', 'singular', 'other' ];

	/**
	 * Settings service.
	 */
	private Settings $settings;

	/**
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Kind of page being rendered.
	 */
	public static function context(): string {
		if ( is_front_page() || is_home() ) {
			return 'home';
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}

		if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() ) ) {
			return 'shop';
		}

		if ( is_singular() ) {
			return 'singular';
		}

		return 'other';
	}

	/**
	 * Label shown in the settings.
	 *
	 * @param string $context Context key.
	 */
	public static function label( string $context ): string {
		$labels = [
			'home'     => __( 'Portada', 'bricks-cache' ),
			'shop'     => __( 'Catálogo y categorías', 'bricks-cache' ),
			'product'  => __( 'Ficha de producto', 'bricks-cache' ),
			'singular' => __( 'Entradas y páginas', 'bricks-cache' ),
			'other'    => __( 'Resto de páginas', 'bricks-cache' ),
		];

		return $labels[ $context ] ?? $context;
	}

	/**
	 * Critical CSS for the page being rendered.
	 */
	public function css(): string {
		$css = (string) $this->settings->get( 'css.critical_' . self::context(), '' );

		/**
		 * Filter the critical CSS about to be inlined.
		 *
		 * @param string $css     Critical CSS.
		 * @param string $context Kind of page.
		 */
		return (string) apply_filters( 'bricks_cache_critical_css', $css, self::context() );
	}

	/**
	 * Whether this page has critical CSS to inline.
	 */
	public function has_css(): bool {
		return '' !== trim( $this->css() );
	}
}
