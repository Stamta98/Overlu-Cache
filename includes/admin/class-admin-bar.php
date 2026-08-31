<?php
/**
 * Admin bar shortcuts.
 *
 * @package BricksCache
 */

namespace BricksCache\Admin;

use BricksCache\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purging has to be one click away from the page being looked at. Anyone who
 * has to open a settings screen to clear one product page will simply turn the
 * cache off instead.
 */
final class Admin_Bar {

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
	 * Register the menu.
	 */
	public function boot(): void {
		add_action( 'admin_bar_menu', [ $this, 'register_nodes' ], 100 );
	}

	/**
	 * Add the parent node and its actions.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function register_nodes( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled = $this->plugin->settings()->on( 'page_cache.enabled' );

		$bar->add_node(
			[
				'id'    => 'bricks-cache',
				'title' => $enabled
					? esc_html__( 'Caché', 'bricks-cache' )
					: esc_html__( 'Caché (apagada)', 'bricks-cache' ),
				'href'  => admin_url( 'admin.php?page=' . Admin::SLUG ),
				'meta'  => [ 'title' => esc_html__( 'Bricks Cache', 'bricks-cache' ) ],
			]
		);

		$bar->add_node(
			[
				'parent' => 'bricks-cache',
				'id'     => 'bricks-cache-purge-all',
				'title'  => esc_html__( 'Vaciar toda la caché', 'bricks-cache' ),
				'href'   => $this->tool_url( 'purge_all' ),
			]
		);

		if ( ! is_admin() ) {
			$bar->add_node(
				[
					'parent' => 'bricks-cache',
					'id'     => 'bricks-cache-purge-url',
					'title'  => esc_html__( 'Vaciar solo esta página', 'bricks-cache' ),
					'href'   => $this->tool_url( 'purge_url', [ 'url' => home_url( add_query_arg( [] ) ) ] ),
				]
			);
		}

		$bar->add_node(
			[
				'parent' => 'bricks-cache',
				'id'     => 'bricks-cache-settings',
				'title'  => esc_html__( 'Ajustes de la caché', 'bricks-cache' ),
				'href'   => admin_url( 'admin.php?page=' . Admin::SLUG ),
			]
		);
	}

	/**
	 * Nonce-protected URL for one tool.
	 *
	 * @param string               $tool Tool identifier.
	 * @param array<string,string> $args Extra query arguments.
	 */
	private function tool_url( string $tool, array $args = [] ): string {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					[
						'action' => 'bricks_cache_tool',
						'tool'   => $tool,
					],
					$args
				),
				admin_url( 'admin-post.php' )
			),
			'bricks_cache_tool'
		);
	}
}
