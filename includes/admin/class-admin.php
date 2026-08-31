<?php
/**
 * Admin screen.
 *
 * @package BricksCache
 */

namespace BricksCache\Admin;

use BricksCache\Config;
use BricksCache\Diagnostics;
use BricksCache\Dropin;
use BricksCache\Filesystem;
use BricksCache\Plugin;
use BricksCache\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One screen, tabs down the top: the state of things, the settings sections
 * declared by the schema, the log and the manual tools.
 *
 * The form is rendered from the settings schema, so a module that declares a
 * section gets its own tab with no code here.
 */
final class Admin {

	public const SLUG = 'bricks-cache';

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
	 * Register the screen and its handlers.
	 */
	public function boot(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_bricks_cache_save', [ $this, 'handle_save' ] );
		add_action( 'admin_post_bricks_cache_tool', [ $this, 'handle_tool' ] );
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
		add_filter( 'plugin_action_links_' . BRICKS_CACHE_BASENAME, [ $this, 'action_links' ] );
	}

	/**
	 * Top level menu entry.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Bricks Cache', 'bricks-cache' ),
			__( 'Bricks Cache', 'bricks-cache' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-performance',
			58
		);
	}

	/**
	 * Shortcut from the plugins list.
	 *
	 * @param string[] $links Existing links.
	 *
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
				esc_html__( 'Ajustes', 'bricks-cache' )
			)
		);

		return $links;
	}

	/**
	 * Tab list: fixed ones plus one per settings section.
	 *
	 * @return array<string,string>
	 */
	private function tabs(): array {
		$tabs = [ 'estado' => __( 'Estado', 'bricks-cache' ) ];

		foreach ( Settings::schema() as $section => $definition ) {
			$tabs[ $section ] = (string) $definition['label'];
		}

		$tabs['registro']    = __( 'Registro', 'bricks-cache' );
		$tabs['herramientas'] = __( 'Herramientas', 'bricks-cache' );

		return $tabs;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs    = $this->tabs();
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'estado'; // phpcs:ignore WordPress.Security.NonceVerification
		$current = array_key_exists( $current, $tabs ) ? $current : 'estado';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Bricks Cache', 'bricks-cache' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Caché de página y optimización para esta tienda. Empieza por «Estado».', 'bricks-cache' ) . '</p>';

		echo '<h2 class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ),
				$slug === $current ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</h2>';

		switch ( $current ) {
			case 'estado':
				$this->render_status();
				break;

			case 'registro':
				$this->render_log();
				break;

			case 'herramientas':
				$this->render_tools();
				break;

			default:
				$this->render_section( $current );
				break;
		}

		echo '</div>';
	}

	/**
	 * Status tab: what the cache is doing right now.
	 */
	private function render_status(): void {
		$stats = $this->plugin->store()->stats();

		echo '<h2>' . esc_html__( 'Resumen', 'bricks-cache' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';

		$rows = [
			__( 'Páginas guardadas', 'bricks-cache' )   => number_format_i18n( $stats['pages'] ),
			__( 'Espacio en disco', 'bricks-cache' )     => size_format( $stats['bytes'], 2 ),
			__( 'Almacenamiento', 'bricks-cache' )       => $this->plugin->store()->label(),
			__( 'Carpeta', 'bricks-cache' )              => Filesystem::root(),
		];

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row" style="width:220px">%s</th><td><code>%s</code></td></tr>',
				esc_html( $label ),
				esc_html( (string) $value )
			);
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Comprobaciones', 'bricks-cache' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		$icons = [
			Diagnostics::OK   => '<span style="color:#00a32a" aria-hidden="true">●</span>',
			Diagnostics::WARN => '<span style="color:#dba617" aria-hidden="true">●</span>',
			Diagnostics::FAIL => '<span style="color:#d63638" aria-hidden="true">●</span>',
		];

		foreach ( ( new Diagnostics( $this->plugin ) )->checks() as $check ) {
			printf(
				'<tr><td style="width:24px">%s</td><th scope="row" style="width:240px">%s</th><td>%s</td></tr>',
				wp_kses_post( $icons[ $check['status'] ] ?? '' ),
				esc_html( $check['label'] ),
				esc_html( $check['message'] )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * One settings section, rendered from the schema.
	 *
	 * @param string $section Section key.
	 */
	private function render_section( string $section ): void {
		$schema = Settings::schema()[ $section ] ?? null;

		if ( null === $schema ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bricks_cache_save' );
		echo '<input type="hidden" name="action" value="bricks_cache_save">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $section ) . '">';

		if ( ! empty( $schema['description'] ) ) {
			echo '<p class="description" style="margin:16px 0">' . esc_html( (string) $schema['description'] ) . '</p>';
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $schema['fields'] as $field => $args ) {
			$this->render_field( $section, $field, $args );
		}

		echo '</tbody></table>';

		submit_button( __( 'Guardar cambios', 'bricks-cache' ) );
		echo '</form>';
	}

	/**
	 * One field.
	 *
	 * @param string              $section Section key.
	 * @param string              $field   Field key.
	 * @param array<string,mixed> $args    Field declaration.
	 */
	private function render_field( string $section, string $field, array $args ): void {
		$name  = sprintf( 'bricks_cache[%s][%s]', $section, $field );
		$id    = 'bricks-cache-' . $section . '-' . $field;
		$value = $this->plugin->settings()->get( $section . '.' . $field );
		$type  = (string) ( $args['type'] ?? 'text' );

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( (string) $args['label'] ) . '</label></th><td>';

		switch ( $type ) {
			case 'toggle':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html__( 'Activado', 'bricks-cache' )
				);
				break;

			case 'number':
				printf(
					'<input type="number" class="regular-text" id="%s" name="%s" value="%s" min="%s" max="%s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) ( $args['min'] ?? 0 ) ),
					esc_attr( (string) ( $args['max'] ?? 999999 ) )
				);
				break;

			case 'select':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );

				foreach ( (array) ( $args['options'] ?? [] ) as $option => $label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( (string) $option ),
						selected( (string) $value, (string) $option, false ),
						esc_html( (string) $label )
					);
				}

				echo '</select>';
				break;

			case 'list':
				printf(
					'<textarea id="%s" name="%s" rows="6" class="large-text code">%s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( implode( "\n", (array) $value ) )
				);
				break;

			default:
				printf(
					'<input type="text" class="regular-text" id="%s" name="%s" value="%s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
		}

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( (string) $args['description'] ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Log tab.
	 */
	private function render_log(): void {
		$lines = $this->plugin->logger()->tail( 300 );

		echo '<h2>' . esc_html__( 'Últimas entradas', 'bricks-cache' ) . '</h2>';

		if ( [] === $lines ) {
			echo '<p>' . esc_html__( 'El registro está vacío.', 'bricks-cache' ) . '</p>';

			return;
		}

		echo '<textarea readonly rows="24" class="large-text code" style="font-family:monospace">';
		echo esc_textarea( implode( "\n", $lines ) );
		echo '</textarea>';

		$this->tool_button( 'clear_log', __( 'Vaciar el registro', 'bricks-cache' ) );
	}

	/**
	 * Tools tab.
	 */
	private function render_tools(): void {
		$tools = [
			'purge_all'        => [
				__( 'Vaciar toda la caché', 'bricks-cache' ),
				__( 'Borra todas las páginas guardadas. Se vuelven a generar en la siguiente visita.', 'bricks-cache' ),
			],
			'cleanup_expired'  => [
				__( 'Limpiar lo caducado', 'bricks-cache' ),
				__( 'Borra solo las copias que ya han pasado su duración.', 'bricks-cache' ),
			],
			'reinstall_dropin' => [
				__( 'Reinstalar el archivo advanced-cache.php', 'bricks-cache' ),
				__( 'Vuelve a copiar el archivo que sirve las páginas y regenera su configuración.', 'bricks-cache' ),
			],
			'rebuild_config'   => [
				__( 'Regenerar la configuración', 'bricks-cache' ),
				__( 'Reescribe el archivo de reglas que lee la caché. Útil si has cambiado las páginas de WooCommerce.', 'bricks-cache' ),
			],
		];

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';

		foreach ( $tools as $tool => $data ) {
			echo '<tr><th scope="row" style="width:320px">' . esc_html( $data[0] ) . '<p class="description" style="font-weight:400">' . esc_html( $data[1] ) . '</p></th><td style="vertical-align:middle">';
			$this->tool_button( $tool, $data[0] );
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Información técnica', 'bricks-cache' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';

		$info = [
			__( 'Versión del plugin', 'bricks-cache' )       => BRICKS_CACHE_VERSION,
			__( 'Versión del archivo servidor', 'bricks-cache' ) => (string) ( Dropin::installed_version() ?? '—' ),
			__( 'Archivo de configuración', 'bricks-cache' ) => Config::file(),
			__( 'wp-config.php', 'bricks-cache' )            => (string) ( Dropin::wp_config_path() ?? '—' ),
		];

		foreach ( $info as $label => $value ) {
			printf(
				'<tr><th scope="row" style="width:320px">%s</th><td><code>%s</code></td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * A form with a single tool button.
	 *
	 * @param string $tool  Tool identifier.
	 * @param string $label Button label.
	 */
	private function tool_button( string $tool, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-top:8px">';
		wp_nonce_field( 'bricks_cache_tool' );
		echo '<input type="hidden" name="action" value="bricks_cache_tool">';
		echo '<input type="hidden" name="tool" value="' . esc_attr( $tool ) . '">';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Save the posted settings section.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para cambiar estos ajustes.', 'bricks-cache' ) );
		}

		check_admin_referer( 'bricks_cache_save' );

		$raw = isset( $_POST['bricks_cache'] ) && is_array( $_POST['bricks_cache'] )
			? wp_unslash( $_POST['bricks_cache'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: [];

		$this->plugin->settings()->update( (array) $raw );

		$this->plugin->notice( __( 'Ajustes guardados y caché vaciada.', 'bricks-cache' ), 'success' );

		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'estado';

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/**
	 * Run a manual tool.
	 */
	public function handle_tool(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para ejecutar esta acción.', 'bricks-cache' ) );
		}

		check_admin_referer( 'bricks_cache_tool' );

		$tool = isset( $_POST['tool'] ) ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : '';
		$tool = '' === $tool && isset( $_GET['tool'] ) ? sanitize_key( wp_unslash( $_GET['tool'] ) ) : $tool;

		switch ( $tool ) {
			case 'purge_all':
				$this->plugin->purge()->all( 'admin' );
				$this->plugin->notice( __( 'Caché vaciada.', 'bricks-cache' ), 'success' );
				break;

			case 'purge_url':
				$url  = isset( $_REQUEST['url'] ) ? esc_url_raw( wp_unslash( $_REQUEST['url'] ) ) : '';
				$host = (string) wp_parse_url( $url, PHP_URL_HOST );

				if ( '' !== $url && $host === (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) {
					$this->plugin->purge()->url( $url );
					$this->plugin->notice( __( 'Página vaciada de la caché.', 'bricks-cache' ), 'success' );
				}
				break;

			case 'cleanup_expired':
				$this->plugin->purge()->cleanup_expired();
				$this->plugin->notice( __( 'Copias caducadas eliminadas.', 'bricks-cache' ), 'success' );
				break;

			case 'reinstall_dropin':
				$this->plugin->config()->write();
				$result = Dropin::install();

				if ( is_wp_error( $result ) ) {
					$this->plugin->notice( $result->get_error_message(), 'error' );
				} else {
					$this->plugin->notice( __( 'Archivo advanced-cache.php reinstalado.', 'bricks-cache' ), 'success' );
				}
				break;

			case 'rebuild_config':
				$this->plugin->config()->write();
				$this->plugin->notice( __( 'Configuración regenerada.', 'bricks-cache' ), 'success' );
				break;

			case 'clear_log':
				$this->plugin->logger()->clear();
				$this->plugin->notice( __( 'Registro vaciado.', 'bricks-cache' ), 'success' );
				break;
		}

		$referer = wp_get_referer();

		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Print and clear the queued notices.
	 */
	public function render_notices(): void {
		$notices = get_transient( 'bricks_cache_notices' );

		if ( ! is_array( $notices ) || [] === $notices ) {
			return;
		}

		delete_transient( 'bricks_cache_notices' );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_attr( (string) ( $notice['type'] ?? 'info' ) ),
				esc_html__( 'Bricks Cache:', 'bricks-cache' ),
				esc_html( (string) ( $notice['message'] ?? '' ) )
			);
		}
	}
}
