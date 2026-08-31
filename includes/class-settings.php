<?php
/**
 * Settings: one option, one schema.
 *
 * @package BricksCache
 */

namespace BricksCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every setting lives in a single option and is declared once, in schema().
 * Defaults, sanitising and the admin form are all derived from that schema, so
 * a new module adds a section through the `bricks_cache_settings_schema`
 * filter and gets storage plus a rendered form for free.
 */
final class Settings {

	public const OPTION = 'bricks_cache_settings';

	/**
	 * Loaded values, merged over the defaults.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $values = null;

	/**
	 * Cached schema for this request.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static ?array $schema = null;

	/**
	 * Field declarations, grouped in the sections the admin renders as tabs.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function schema(): array {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		$schema = [
			'page_cache'  => [
				'label'       => __( 'Caché de página', 'bricks-cache' ),
				'description' => __( 'Guarda el HTML terminado y lo sirve antes de que WordPress arranque.', 'bricks-cache' ),
				'fields'      => [
					'enabled'            => [
						'type'        => 'toggle',
						'label'       => __( 'Activar la caché de página', 'bricks-cache' ),
						'description' => __( 'Instala el archivo advanced-cache.php y empieza a guardar páginas para visitantes anónimos.', 'bricks-cache' ),
						'default'     => false,
					],
					'ttl'                => [
						'type'        => 'number',
						'label'       => __( 'Duración de una página guardada (segundos)', 'bricks-cache' ),
						'description' => __( 'Pasado este tiempo la página se vuelve a generar. 0 significa que solo caduca al purgar.', 'bricks-cache' ),
						'default'     => 43200,
						'min'         => 0,
						'max'         => 2592000,
					],
					'gzip'               => [
						'type'        => 'toggle',
						'label'       => __( 'Guardar también una copia comprimida', 'bricks-cache' ),
						'description' => __( 'Ocupa algo más de disco y ahorra la compresión en cada visita.', 'bricks-cache' ),
						'default'     => true,
					],
					'mobile_variant'     => [
						'type'        => 'toggle',
						'label'       => __( 'Copia aparte para móviles', 'bricks-cache' ),
						'description' => __( 'Actívalo solo si el HTML cambia entre móvil y escritorio. Con un diseño responsive normal no hace falta y duplica el disco usado.', 'bricks-cache' ),
						'default'     => false,
					],
					'ignored_query_args' => [
						'type'        => 'list',
						'label'       => __( 'Parámetros de URL que se ignoran', 'bricks-cache' ),
						'description' => __( 'Uno por línea. Con estos parámetros la página se sirve igual que sin ellos. Admite * al final.', 'bricks-cache' ),
						'default'     => [ 'utm_*', 'fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid', 'mc_cid', 'mc_eid', '_ga', 'ref', 'age-verified', 'usqp' ],
					],
					'cached_query_args'  => [
						'type'        => 'list',
						'label'       => __( 'Parámetros que sí generan copia propia', 'bricks-cache' ),
						'description' => __( 'Uno por línea. Por ejemplo el número de página o un orden de catálogo. Cualquier otro parámetro no listado desactiva la caché en esa visita.', 'bricks-cache' ),
						'default'     => [ 'orderby', 'paged', 'page', 'product_page', 'min_price', 'max_price' ],
					],
					'footer_signature'   => [
						'type'        => 'toggle',
						'label'       => __( 'Firmar el HTML guardado', 'bricks-cache' ),
						'description' => __( 'Añade un comentario invisible al final de la página con la fecha en que se guardó. Útil para comprobar que la caché funciona.', 'bricks-cache' ),
						'default'     => true,
					],
				],
			],
			'exclusions'  => [
				'label'       => __( 'Exclusiones', 'bricks-cache' ),
				'description' => __( 'El carrito, la caja, la cuenta y cualquier petición con sesión iniciada quedan fuera siempre, sin necesidad de configurarlo.', 'bricks-cache' ),
				'fields'      => [
					'urls'        => [
						'type'        => 'list',
						'label'       => __( 'Rutas que nunca se guardan', 'bricks-cache' ),
						'description' => __( 'Una por línea, empezando por /. Admite * como comodín. Ejemplo: /pedido-recibido/*', 'bricks-cache' ),
						'default'     => [],
					],
					'cookies'     => [
						'type'        => 'list',
						'label'       => __( 'Cookies que desactivan la caché', 'bricks-cache' ),
						'description' => __( 'Una por línea. Si el visitante trae una de estas cookies, siempre verá la página generada al momento.', 'bricks-cache' ),
						'default'     => [],
					],
					'user_agents' => [
						'type'        => 'list',
						'label'       => __( 'Navegadores o bots excluidos', 'bricks-cache' ),
						'description' => __( 'Una por línea. Coincidencia parcial sobre el identificador del navegador.', 'bricks-cache' ),
						'default'     => [],
					],
				],
			],
			'invalidation' => [
				'label'       => __( 'Purga automática', 'bricks-cache' ),
				'description' => __( 'Cuándo se tira la copia guardada para que el visitante no vea información vieja. En una tienda esto importa más que la propia caché.', 'bricks-cache' ),
				'fields'      => [
					'on_content_save'  => [
						'type'        => 'toggle',
						'label'       => __( 'Al guardar contenido', 'bricks-cache' ),
						'description' => __( 'Purga la entrada o producto guardado, su archivo, sus categorías y la portada.', 'bricks-cache' ),
						'default'     => true,
					],
					'on_stock_change'  => [
						'type'        => 'toggle',
						'label'       => __( 'Al cambiar el stock o el precio', 'bricks-cache' ),
						'description' => __( 'Imprescindible en una tienda: evita mostrar «disponible» un producto agotado.', 'bricks-cache' ),
						'default'     => true,
					],
					'on_order_paid'    => [
						'type'        => 'toggle',
						'label'       => __( 'Al pagarse un pedido', 'bricks-cache' ),
						'description' => __( 'Purga los productos del pedido, porque su stock acaba de bajar.', 'bricks-cache' ),
						'default'     => true,
					],
					'on_comment'       => [
						'type'        => 'toggle',
						'label'       => __( 'Al aprobarse un comentario o una valoración', 'bricks-cache' ),
						'default'     => true,
					],
					'on_design_change' => [
						'type'        => 'toggle',
						'label'       => __( 'Al cambiar el diseño en Bricks', 'bricks-cache' ),
						'description' => __( 'Purga todo cuando Bricks regenera sus archivos CSS o se guarda una plantilla.', 'bricks-cache' ),
						'default'     => true,
					],
					'purge_home'       => [
						'type'        => 'toggle',
						'label'       => __( 'Incluir siempre la portada', 'bricks-cache' ),
						'default'     => true,
					],
					'cleanup_expired'  => [
						'type'        => 'toggle',
						'label'       => __( 'Limpiar copias caducadas cada hora', 'bricks-cache' ),
						'description' => __( 'Tarea programada que borra del disco lo que ya no se puede servir.', 'bricks-cache' ),
						'default'     => true,
					],
				],
			],
			'logging'     => [
				'label'       => __( 'Registro', 'bricks-cache' ),
				'description' => __( 'Deja rastro de lo que hace el plugin. Con «Depuración» crece rápido: úsalo solo mientras investigas algo.', 'bricks-cache' ),
				'fields'      => [
					'enabled' => [
						'type'    => 'toggle',
						'label'   => __( 'Guardar registro', 'bricks-cache' ),
						'default' => true,
					],
					'level'   => [
						'type'    => 'select',
						'label'   => __( 'Detalle', 'bricks-cache' ),
						'default' => 'warning',
						'options' => [
							'error'   => __( 'Solo errores', 'bricks-cache' ),
							'warning' => __( 'Errores y avisos', 'bricks-cache' ),
							'info'    => __( 'Actividad normal', 'bricks-cache' ),
							'debug'   => __( 'Depuración', 'bricks-cache' ),
						],
					],
				],
			],
		];

		/**
		 * Filter the settings schema. Modules use this to add their own section.
		 *
		 * @param array<string,array<string,mixed>> $schema Section declarations.
		 */
		self::$schema = apply_filters( 'bricks_cache_settings_schema', $schema );

		return self::$schema;
	}

	/**
	 * Default value of every declared field, keyed section => field.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function defaults(): array {
		$defaults = [];

		foreach ( self::schema() as $section => $definition ) {
			foreach ( $definition['fields'] as $field => $args ) {
				$defaults[ $section ][ $field ] = $args['default'] ?? null;
			}
		}

		return $defaults;
	}

	/**
	 * All settings, defaults included.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		if ( null === $this->values ) {
			$stored = get_option( self::OPTION, [] );
			$stored = is_array( $stored ) ? $stored : [];

			$this->values = [];

			foreach ( self::defaults() as $section => $fields ) {
				$this->values[ $section ] = array_merge( $fields, is_array( $stored[ $section ] ?? null ) ? $stored[ $section ] : [] );
			}
		}

		return $this->values;
	}

	/**
	 * Read one value with a "section.field" path.
	 *
	 * @param string $path    Dotted path.
	 * @param mixed  $default Returned when the field does not exist.
	 *
	 * @return mixed
	 */
	public function get( string $path, mixed $default = null ): mixed {
		[ $section, $field ] = array_pad( explode( '.', $path, 2 ), 2, null );

		$values = $this->all();

		if ( null === $field ) {
			return $values[ $section ] ?? $default;
		}

		return $values[ $section ][ $field ] ?? $default;
	}

	/**
	 * Convenience boolean read.
	 *
	 * @param string $path Dotted path.
	 */
	public function on( string $path ): bool {
		return (bool) $this->get( $path, false );
	}

	/**
	 * Persist a full or partial set of raw values after sanitising them.
	 *
	 * @param array<string,mixed> $raw       Untrusted input, usually $_POST.
	 * @param bool                $from_form Whether $raw is a complete form
	 *                                       submission of the sections it
	 *                                       contains. Only then does a missing
	 *                                       checkbox mean "off".
	 */
	public function update( array $raw, bool $from_form = false ): bool {
		$clean  = $this->sanitize( $raw, $from_form );
		$before = $this->all();
		$after  = [];

		foreach ( self::defaults() as $section => $fields ) {
			$after[ $section ] = array_merge( $fields, $before[ $section ] ?? [], $clean[ $section ] ?? [] );
		}

		$this->values = $after;

		update_option( self::OPTION, $after, true );

		/**
		 * Fires after the settings are saved.
		 *
		 * @param array<string,mixed> $after  New values.
		 * @param array<string,mixed> $before Previous values.
		 */
		do_action( 'bricks_cache_settings_updated', $after, $before );

		return true;
	}

	/**
	 * Force one value without going through a form.
	 *
	 * Used by code, not by the form, so it never touches the fields it was not
	 * given.
	 *
	 * @param string $path  Dotted path.
	 * @param mixed  $value New value, already of the right type.
	 */
	public function set( string $path, mixed $value ): bool {
		[ $section, $field ] = array_pad( explode( '.', $path, 2 ), 2, null );

		if ( null === $field ) {
			return false;
		}

		return $this->update( [ $section => [ $field => $value ] ] );
	}

	/**
	 * Cast and clean raw input against the schema. Anything not declared is
	 * dropped, so a crafted request cannot inject its own keys.
	 *
	 * @param array<string,mixed> $raw       Untrusted input.
	 * @param bool                $from_form Whether an absent checkbox should
	 *                                       be read as "off".
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function sanitize( array $raw, bool $from_form = false ): array {
		$clean = [];

		foreach ( self::schema() as $section => $definition ) {
			if ( ! isset( $raw[ $section ] ) || ! is_array( $raw[ $section ] ) ) {
				continue;
			}

			foreach ( $definition['fields'] as $field => $args ) {
				if ( ! array_key_exists( $field, $raw[ $section ] ) ) {
					// An unchecked checkbox is simply absent from the POST, so
					// a form submission means "off". A partial update from code
					// means nothing at all: reading it as "off" there would let
					// switching one option quietly switch off its neighbours.
					if ( $from_form && 'toggle' === ( $args['type'] ?? '' ) ) {
						$clean[ $section ][ $field ] = false;
					}

					continue;
				}

				$clean[ $section ][ $field ] = self::sanitize_field( $raw[ $section ][ $field ], $args );
			}
		}

		return $clean;
	}

	/**
	 * Cast one field according to its declared type.
	 *
	 * @param mixed                $value Raw value.
	 * @param array<string,mixed>  $args  Field declaration.
	 *
	 * @return mixed
	 */
	private static function sanitize_field( mixed $value, array $args ): mixed {
		switch ( $args['type'] ?? 'text' ) {
			case 'toggle':
				return (bool) $value && '0' !== $value;

			case 'number':
				$number = (int) $value;
				$number = isset( $args['min'] ) ? max( (int) $args['min'], $number ) : $number;

				return isset( $args['max'] ) ? min( (int) $args['max'], $number ) : $number;

			case 'select':
				$options = array_keys( $args['options'] ?? [] );

				return in_array( (string) $value, $options, true ) ? (string) $value : (string) ( $args['default'] ?? '' );

			case 'list':
				$lines = is_array( $value ) ? $value : preg_split( '/\R/', (string) $value );
				$lines = array_map( 'trim', (array) $lines );
				$lines = array_filter( $lines, static fn( $line ) => '' !== $line );

				return array_values( array_unique( array_map( 'sanitize_text_field', $lines ) ) );

			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
