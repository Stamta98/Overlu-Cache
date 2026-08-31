<?php
/**
 * Health checks shown on the status screen.
 *
 * @package BricksCache
 */

namespace BricksCache;

use BricksCache\Compat\Bricks;
use BricksCache\Compat\WooCommerce;
use BricksCache\Store\Factory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A cache that is quietly doing nothing looks exactly like a cache that works.
 * These checks are the difference: each one answers a question the user would
 * otherwise have to answer with a terminal.
 */
final class Diagnostics {

	public const OK   = 'ok';
	public const WARN = 'warn';
	public const FAIL = 'fail';

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
	 * Every check, ready to render.
	 *
	 * @return array<int,array{label:string,status:string,message:string}>
	 */
	public function checks(): array {
		$settings = $this->plugin->settings();
		$enabled  = $settings->on( 'page_cache.enabled' );

		$checks = [];

		$checks[] = [
			'label'   => __( 'Caché de página', 'bricks-cache' ),
			'status'  => $enabled ? self::OK : self::WARN,
			'message' => $enabled
				? __( 'Activada: las visitas anónimas reciben HTML guardado.', 'bricks-cache' )
				: __( 'Desactivada. Actívala en la pestaña «Caché de página» cuando quieras empezar.', 'bricks-cache' ),
		];

		$checks[] = $this->dropin_check( $enabled );

		$checks[] = [
			'label'   => __( 'Constante WP_CACHE', 'bricks-cache' ),
			'status'  => Dropin::wp_cache_enabled() ? self::OK : ( $enabled ? self::FAIL : self::WARN ),
			'message' => Dropin::wp_cache_enabled()
				? __( 'Definida como true en wp-config.php.', 'bricks-cache' )
				: sprintf(
					/* translators: %s: PHP line to add. */
					__( 'Sin ella WordPress no carga el archivo de caché. Añade %s al principio de wp-config.php.', 'bricks-cache' ),
					Dropin::manual_line()
				),
		];

		$writable = Filesystem::is_writable();

		$checks[] = [
			'label'   => __( 'Carpeta de caché', 'bricks-cache' ),
			'status'  => $writable ? self::OK : self::FAIL,
			'message' => $writable
				? sprintf(
					/* translators: %s: absolute path. */
					__( 'Se puede escribir en %s', 'bricks-cache' ),
					Filesystem::root()
				)
				: sprintf(
					/* translators: %s: absolute path. */
					__( 'No se puede escribir en %s. Revisa los permisos.', 'bricks-cache' ),
					Filesystem::root()
				),
		];

		$conflicts = $this->conflicting_plugins();

		$checks[] = [
			'label'   => __( 'Otros plugins de caché', 'bricks-cache' ),
			'status'  => [] === $conflicts ? self::OK : self::FAIL,
			'message' => [] === $conflicts
				? __( 'Ninguno activo. Bricks Cache es el único que gestiona la caché.', 'bricks-cache' )
				: sprintf(
					/* translators: %s: comma separated plugin names. */
					__( 'Activos a la vez: %s. Dos cachés se pisan y sirven páginas viejas.', 'bricks-cache' ),
					implode( ', ', $conflicts )
				),
		];

		$permalinks = (string) get_option( 'permalink_structure' );

		$checks[] = [
			'label'   => __( 'Enlaces permanentes', 'bricks-cache' ),
			'status'  => '' === $permalinks ? self::FAIL : self::OK,
			'message' => '' === $permalinks
				? __( 'Están en «simple». La caché necesita URLs con ruta para poder guardarlas.', 'bricks-cache' )
				: sprintf(
					/* translators: %s: permalink structure. */
					__( 'Estructura: %s', 'bricks-cache' ),
					$permalinks
				),
		];

		$ttl = (int) $settings->get( 'page_cache.ttl', 43200 );

		$checks[] = [
			'label'   => __( 'Duración frente a los nonces', 'bricks-cache' ),
			'status'  => ( $ttl > 43200 || 0 === $ttl ) ? self::WARN : self::OK,
			'message' => ( $ttl > 43200 || 0 === $ttl )
				? __( 'Las páginas guardadas incluyen nonces que caducan a las 12 horas. Con una duración mayor, los botones de añadir al carrito pueden dejar de funcionar en páginas antiguas.', 'bricks-cache' )
				: __( 'Por debajo de las 12 horas que dura un nonce. Correcto.', 'bricks-cache' ),
		];

		$backends  = Factory::available_backends();
		$in_memory = array_keys( array_filter( array_diff_key( $backends, [ 'disk' => true ] ) ) );

		$checks[] = [
			'label'   => __( 'Almacenamiento', 'bricks-cache' ),
			'status'  => self::OK,
			'message' => [] === $in_memory
				? __( 'Disco. Este servidor no tiene Redis, Memcached ni APCu, así que no hay caché de objetos persistente disponible.', 'bricks-cache' )
				: sprintf(
					/* translators: %s: comma separated backend names. */
					__( 'Disco. El servidor además ofrece: %s.', 'bricks-cache' ),
					implode( ', ', $in_memory )
				),
		];

		$next = wp_next_scheduled( 'bricks_cache_cleanup' );

		$checks[] = [
			'label'   => __( 'Limpieza programada', 'bricks-cache' ),
			'status'  => $next ? self::OK : self::WARN,
			'message' => $next
				? sprintf(
					/* translators: %s: local date and time. */
					__( 'Próxima pasada: %s', 'bricks-cache' ),
					wp_date( 'd/m/Y H:i', (int) $next )
				)
				: __( 'Sin programar. Las copias caducadas se quedarán en disco hasta la siguiente purga.', 'bricks-cache' ),
		];

		$checks[] = [
			'label'   => __( 'Tema Bricks', 'bricks-cache' ),
			'status'  => Bricks::is_active() ? self::OK : self::WARN,
			'message' => Bricks::is_active()
				? sprintf(
					/* translators: %s: version number. */
					__( 'Activo (versión %s). Se purga todo cuando Bricks regenera su CSS.', 'bricks-cache' ),
					Bricks::version() ?? '—'
				)
				: __( 'No detectado. El plugin funciona igual, pero sin las purgas propias de Bricks.', 'bricks-cache' ),
		];

		$checks[] = [
			'label'   => __( 'WooCommerce', 'bricks-cache' ),
			'status'  => WooCommerce::is_active() ? self::OK : self::WARN,
			'message' => WooCommerce::is_active()
				? __( 'Activo. Carrito, caja y cuenta quedan fuera de la caché automáticamente.', 'bricks-cache' )
				: __( 'No detectado. Sin las exclusiones ni las purgas de tienda.', 'bricks-cache' ),
		];

		/**
		 * Filter the diagnostics list.
		 *
		 * @param array<int,array{label:string,status:string,message:string}> $checks Checks.
		 */
		return (array) apply_filters( 'bricks_cache_diagnostics', $checks );
	}

	/**
	 * State of the drop-in file.
	 *
	 * @param bool $enabled Whether the page cache is on.
	 *
	 * @return array{label:string,status:string,message:string}
	 */
	private function dropin_check( bool $enabled ): array {
		$label = __( 'Archivo advanced-cache.php', 'bricks-cache' );

		if ( ! Dropin::exists() ) {
			return [
				'label'   => $label,
				'status'  => $enabled ? self::FAIL : self::WARN,
				'message' => __( 'No está instalado. Se instala solo al activar la caché de página.', 'bricks-cache' ),
			];
		}

		if ( ! Dropin::is_ours() ) {
			return [
				'label'   => $label,
				'status'  => self::FAIL,
				'message' => __( 'Existe, pero pertenece a otro plugin de caché.', 'bricks-cache' ),
			];
		}

		if ( ! Dropin::is_current() ) {
			return [
				'label'   => $label,
				'status'  => self::WARN,
				'message' => __( 'Instalado, pero de una versión anterior. Usa «Reinstalar el archivo» en Herramientas.', 'bricks-cache' ),
			];
		}

		return [
			'label'   => $label,
			'status'  => self::OK,
			'message' => __( 'Instalado y al día.', 'bricks-cache' ),
		];
	}

	/**
	 * Active plugins that also install a page cache.
	 *
	 * @return string[]
	 */
	private function conflicting_plugins(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return Requirements::conflicting_plugins();
	}
}
