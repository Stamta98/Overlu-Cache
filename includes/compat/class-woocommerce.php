<?php
/**
 * WooCommerce compatibility.
 *
 * @package BricksCache
 */

namespace BricksCache\Compat;

use BricksCache\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything specific to running a cache in front of a shop.
 *
 * Two jobs. Keeping pages that belong to one customer out of the cache — cart,
 * checkout, account, and any page rendered while a session or a notice exists.
 * And throwing pages away when the catalogue changes underneath them, which in
 * practice means stock: the moment stock moves, every page that printed it is
 * a lie.
 */
final class WooCommerce {

	/**
	 * Container.
	 */
	private Plugin $plugin;

	/**
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether WooCommerce is running.
	 */
	public static function is_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Register the exclusions and the invalidation hooks.
	 */
	public function boot(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'bricks_cache_excluded_paths', [ $this, 'excluded_paths' ] );
		add_filter( 'bricks_cache_response_bypass_reason', [ $this, 'response_reason' ], 10, 2 );

		if ( $this->plugin->settings()->on( 'invalidation.on_stock_change' ) ) {
			add_action( 'woocommerce_product_set_stock', [ $this, 'on_product_stock' ] );
			add_action( 'woocommerce_variation_set_stock', [ $this, 'on_variation_stock' ] );
			add_action( 'woocommerce_product_set_stock_status', [ $this, 'on_product_stock_status' ], 10, 1 );
			add_action( 'woocommerce_variation_set_stock_status', [ $this, 'on_variation_stock_status' ], 10, 1 );
			add_action( 'woocommerce_update_product', [ $this, 'on_update_product' ] );
			add_action( 'woocommerce_product_object_updated_props', [ $this, 'on_updated_props' ], 10, 2 );
		}

		if ( $this->plugin->settings()->on( 'invalidation.on_order_paid' ) ) {
			add_action( 'woocommerce_payment_complete', [ $this, 'on_order' ] );
			add_action( 'woocommerce_order_status_processing', [ $this, 'on_order' ] );
			add_action( 'woocommerce_order_status_completed', [ $this, 'on_order' ] );
			add_action( 'woocommerce_order_status_cancelled', [ $this, 'on_order' ] );
		}

		// Currency, taxes, catalogue display: settings that change every price
		// on the site at once.
		add_action( 'updated_option', [ $this, 'on_updated_option' ] );
	}

	/**
	 * Shop pages that must never be cached, read from the site's own settings
	 * rather than guessed from the URL, because these slugs are translated.
	 *
	 * @param string[] $paths Paths so far.
	 *
	 * @return string[]
	 */
	public function excluded_paths( array $paths ): array {
		foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
			$path = $this->page_path( $page );

			if ( null !== $path ) {
				$paths[] = $path;
				$paths[] = rtrim( $path, '/' ) . '/*';
			}
		}

		$paths[] = '/wc-api/*';
		$paths[] = '*/wc-ajax/*';

		return $paths;
	}

	/**
	 * Keep out any page rendered for a visitor who already has a shop state:
	 * items in the cart, an open session, or a notice waiting to be printed.
	 *
	 * @param string|null $reason Reason so far.
	 * @param string      $html   Rendered output.
	 */
	public function response_reason( ?string $reason, string $html ): ?string {
		if ( null !== $reason ) {
			return $reason;
		}

		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return 'woocommerce_page';
		}

		$wc = function_exists( 'WC' ) ? WC() : null;

		if ( ! $wc ) {
			return null;
		}

		if ( isset( $wc->cart ) && is_callable( [ $wc->cart, 'is_empty' ] ) && ! $wc->cart->is_empty() ) {
			return 'cart_not_empty';
		}

		// A session that exists and has data means WooCommerce is about to set
		// its cookie: the page belongs to this visitor.
		if ( isset( $wc->session ) && is_callable( [ $wc->session, 'has_session' ] ) && $wc->session->has_session() ) {
			return 'woocommerce_session';
		}

		if ( function_exists( 'wc_notice_count' ) && wc_notice_count() > 0 ) {
			return 'woocommerce_notice';
		}

		return null;
	}

	/**
	 * Stock changed on a simple product.
	 *
	 * @param mixed $product Product object.
	 */
	public function on_product_stock( $product ): void {
		$this->queue_product( $this->product_id( $product ) );
	}

	/**
	 * Stock changed on a variation: the parent is what customers see.
	 *
	 * @param mixed $variation Variation object.
	 */
	public function on_variation_stock( $variation ): void {
		$id = $this->product_id( $variation );

		if ( $id > 0 ) {
			$parent = wp_get_post_parent_id( $id );

			$this->queue_product( $parent > 0 ? $parent : $id );
		}
	}

	/**
	 * Stock status changed on a product.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_stock_status( $product_id ): void {
		$this->queue_product( (int) $product_id );
	}

	/**
	 * Stock status changed on a variation.
	 *
	 * @param int $variation_id Variation ID.
	 */
	public function on_variation_stock_status( $variation_id ): void {
		$parent = wp_get_post_parent_id( (int) $variation_id );

		$this->queue_product( $parent > 0 ? $parent : (int) $variation_id );
	}

	/**
	 * A product was saved through the WooCommerce API.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_update_product( $product_id ): void {
		$this->queue_product( (int) $product_id );
	}

	/**
	 * Only price and stock properties are worth a purge; a changed rating or
	 * total_sales is not worth rebuilding the catalogue for.
	 *
	 * @param mixed    $product Product object.
	 * @param string[] $props   Changed property names.
	 */
	public function on_updated_props( $product, $props ): void {
		$watched = [ 'price', 'regular_price', 'sale_price', 'stock_quantity', 'stock_status', 'catalog_visibility', 'status' ];

		if ( [] === array_intersect( (array) $props, $watched ) ) {
			return;
		}

		$this->queue_product( $this->product_id( $product ) );
	}

	/**
	 * An order moved: its products just lost or recovered stock.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_order( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order || ! is_callable( [ $order, 'get_items' ] ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( is_callable( [ $item, 'get_product_id' ] ) ) {
				$this->queue_product( (int) $item->get_product_id() );
			}
		}
	}

	/**
	 * A WooCommerce option changed. Prices, taxes and catalogue options affect
	 * every page, so there is nothing narrower to purge.
	 *
	 * @param string $option Option name.
	 */
	public function on_updated_option( $option ): void {
		$option = (string) $option;

		if ( ! str_starts_with( $option, 'woocommerce_' ) ) {
			return;
		}

		$ignored = [
			'woocommerce_db_version',
			'woocommerce_admin_notices',
			'woocommerce_meta_box_errors',
			'woocommerce_queue_flush_rewrite_rules',
		];

		if ( in_array( $option, $ignored, true ) ) {
			return;
		}

		$this->plugin->purge()->queue_everything( 'woocommerce_option' );
	}

	/**
	 * Queue a product page plus the shop archive that lists it.
	 *
	 * @param int $product_id Product ID.
	 */
	private function queue_product( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		$purge = $this->plugin->purge();

		$purge->queue_post( $product_id );

		$shop_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;

		if ( $shop_id > 0 ) {
			$link = get_permalink( $shop_id );

			if ( is_string( $link ) ) {
				$purge->queue_url( $link, true );
			}
		}
	}

	/**
	 * Product ID out of whatever the hook passed: an object or an ID.
	 *
	 * @param mixed $product Product object or ID.
	 */
	private function product_id( $product ): int {
		if ( is_numeric( $product ) ) {
			return (int) $product;
		}

		if ( is_object( $product ) && is_callable( [ $product, 'get_id' ] ) ) {
			return (int) $product->get_id();
		}

		return 0;
	}

	/**
	 * Path of one WooCommerce page, as configured on this site.
	 *
	 * @param string $page cart|checkout|myaccount|shop.
	 */
	private function page_path( string $page ): ?string {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return null;
		}

		$id = (int) wc_get_page_id( $page );

		if ( $id <= 0 ) {
			return null;
		}

		$link = get_permalink( $id );

		if ( ! is_string( $link ) ) {
			return null;
		}

		$path = (string) wp_parse_url( $link, PHP_URL_PATH );

		return '' === $path ? null : $path;
	}
}
