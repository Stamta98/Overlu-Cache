<?php
/**
 * Installs, verifies and removes wp-content/advanced-cache.php.
 *
 * @package BricksCache
 */

namespace BricksCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The drop-in is the only piece of the plugin that lives outside the plugin
 * folder, and the only one that can outlive it. Everything about its lifecycle
 * is here so it is never left behind: a stale drop-in pointing at a deleted
 * plugin would serve pages nobody can purge.
 */
final class Dropin {

	private const SIGNATURE = 'Bricks Cache — advanced-cache.php';

	/**
	 * Absolute path of the installed drop-in.
	 */
	public static function path(): string {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * Absolute path of the template shipped with the plugin.
	 */
	public static function template(): string {
		return BRICKS_CACHE_PATH . 'dropin/advanced-cache.php';
	}

	/**
	 * Whether any advanced-cache.php exists.
	 */
	public static function exists(): bool {
		return is_file( self::path() );
	}

	/**
	 * Whether the installed drop-in is ours and not another cache plugin's.
	 */
	public static function is_ours(): bool {
		if ( ! self::exists() ) {
			return false;
		}

		$head = (string) @file_get_contents( self::path(), false, null, 0, 400 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions

		return str_contains( $head, self::SIGNATURE );
	}

	/**
	 * Version declared by the installed drop-in, or null.
	 */
	public static function installed_version(): ?string {
		if ( ! self::is_ours() ) {
			return null;
		}

		$head = (string) @file_get_contents( self::path(), false, null, 0, 400 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions

		return preg_match( '/Dropin Version:\s*([\w.\-]+)/', $head, $match ) ? $match[1] : null;
	}

	/**
	 * Whether the installed drop-in matches the one this plugin ships.
	 */
	public static function is_current(): bool {
		return self::is_ours() && BRICKS_CACHE_DROPIN_VERSION === self::installed_version();
	}

	/**
	 * Copy the template into wp-content, with the real config path baked in.
	 * Refuses to overwrite a drop-in belonging to another plugin.
	 *
	 * @return true|\WP_Error
	 */
	public static function install(): true|\WP_Error {
		if ( self::exists() && ! self::is_ours() ) {
			return new \WP_Error(
				'bricks_cache_foreign_dropin',
				__( 'Ya existe un archivo advanced-cache.php de otro plugin de caché. Desactiva ese plugin antes de continuar.', 'bricks-cache' )
			);
		}

		$template = @file_get_contents( self::template() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions

		if ( false === $template ) {
			return new \WP_Error(
				'bricks_cache_missing_template',
				__( 'No se encuentra la plantilla del archivo advanced-cache.php dentro del plugin.', 'bricks-cache' )
			);
		}

		$body = strtr(
			$template,
			[
				'{{CONFIG_FILE}}'     => Config::file(),
				'{{DROPIN_VERSION}}'  => BRICKS_CACHE_DROPIN_VERSION,
			]
		);

		if ( ! Filesystem::write( self::path(), $body ) ) {
			return new \WP_Error(
				'bricks_cache_dropin_write',
				__( 'No se ha podido escribir wp-content/advanced-cache.php. Revisa los permisos de la carpeta wp-content.', 'bricks-cache' )
			);
		}

		return true;
	}

	/**
	 * Remove our drop-in. Another plugin's file is left untouched.
	 */
	public static function uninstall(): bool {
		if ( ! self::is_ours() ) {
			return false;
		}

		return @unlink( self::path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Whether WP_CACHE is on, which is what makes WordPress load the drop-in.
	 */
	public static function wp_cache_enabled(): bool {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * Path of wp-config.php, supporting the layout where it sits one level
	 * above the WordPress directory.
	 */
	public static function wp_config_path(): ?string {
		$candidates = [
			ABSPATH . 'wp-config.php',
			dirname( ABSPATH ) . '/wp-config.php',
		];

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Add or remove the WP_CACHE constant in wp-config.php.
	 *
	 * Editing wp-config.php is the one thing here that can take a site down,
	 * so the write is atomic and the result is parsed back before it is kept.
	 *
	 * @param bool $enable Desired state.
	 *
	 * @return true|\WP_Error
	 */
	public static function set_wp_cache( bool $enable ): true|\WP_Error {
		$file = self::wp_config_path();

		if ( null === $file ) {
			return new \WP_Error( 'bricks_cache_no_wp_config', __( 'No se encuentra el archivo wp-config.php.', 'bricks-cache' ) );
		}

		if ( ! is_writable( $file ) ) {
			return new \WP_Error(
				'bricks_cache_wp_config_readonly',
				__( 'El archivo wp-config.php no se puede escribir. Añade la línea a mano.', 'bricks-cache' )
			);
		}

		$source = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions

		if ( '' === $source ) {
			return new \WP_Error( 'bricks_cache_wp_config_empty', __( 'No se ha podido leer wp-config.php.', 'bricks-cache' ) );
		}

		// Drop any existing definition, ours or not, so there is exactly one.
		$updated = (string) preg_replace(
			'/^[ \t]*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,.*?\)\s*;[ \t]*(\r?\n)?/mi',
			'',
			$source
		);

		if ( $enable ) {
			$line = "define( 'WP_CACHE', true ); // Bricks Cache" . PHP_EOL;

			$updated = (string) preg_replace(
				'/^<\?php\s*(\r?\n)/',
				'<?php' . PHP_EOL . $line,
				$updated,
				1
			);

			if ( ! str_contains( $updated, "define( 'WP_CACHE', true ); // Bricks Cache" ) ) {
				return new \WP_Error(
					'bricks_cache_wp_config_pattern',
					__( 'No se ha podido añadir la línea WP_CACHE automáticamente. Añádela a mano al principio de wp-config.php.', 'bricks-cache' )
				);
			}
		}

		// Never write a file that does not parse.
		$check = self::lint( $updated );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$backup = $file . '.bricks-cache-backup';

		@copy( $file, $backup ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! Filesystem::write( $file, $updated ) ) {
			return new \WP_Error( 'bricks_cache_wp_config_write', __( 'No se ha podido guardar wp-config.php.', 'bricks-cache' ) );
		}

		return true;
	}

	/**
	 * Parse PHP source without executing it. Falls back to trusting the source
	 * when the host disabled the tokenizer.
	 *
	 * @param string $source PHP source.
	 *
	 * @return true|\WP_Error
	 */
	private static function lint( string $source ): true|\WP_Error {
		if ( ! function_exists( 'token_get_all' ) ) {
			return true;
		}

		try {
			token_get_all( $source, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			return new \WP_Error(
				'bricks_cache_wp_config_parse',
				sprintf(
					/* translators: %s: parser error message. */
					__( 'El wp-config.php resultante no es válido, no se ha tocado nada (%s).', 'bricks-cache' ),
					$error->getMessage()
				)
			);
		}

		return true;
	}

	/**
	 * The line to add by hand when the file cannot be written.
	 */
	public static function manual_line(): string {
		return "define( 'WP_CACHE', true );";
	}
}
