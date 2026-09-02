<?php
/**
 * Container and boot order.
 *
 * @package BricksCache
 */

namespace BricksCache;

use BricksCache\Store\Factory;
use BricksCache\Store\Store_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the services once, in a fixed order, and hands them to whoever asks.
 * Modules and compatibility layers never construct each other: they ask the
 * container, which is what keeps a new optimisation from having to know how
 * the page cache is wired.
 */
final class Plugin {

	/**
	 * Singleton.
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings service.
	 */
	private Settings $settings;

	/**
	 * Logger service.
	 */
	private Logger $logger;

	/**
	 * Config file writer.
	 */
	private Config $config;

	/**
	 * Active backend.
	 */
	private Store_Interface $store;

	/**
	 * Eligibility rules.
	 */
	private Rules $rules;

	/**
	 * Invalidation.
	 */
	private Purge $purge;

	/**
	 * Registered modules, keyed by id.
	 *
	 * @var array<string,Module_Interface>
	 */
	private array $modules = [];

	/**
	 * Whether boot() already ran.
	 */
	private bool $booted = false;

	/**
	 * Container accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private on purpose: use instance().
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->logger   = new Logger( $this->settings );
		$this->config   = new Config( $this->settings );
		$this->store    = Factory::make();
		$this->rules    = new Rules( $this->settings );
		$this->purge    = new Purge( $this->settings, $this->logger, $this->store );
	}

	/**
	 * Wire everything up. Safe to call twice.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'bricks-cache', false, dirname( BRICKS_CACHE_BASENAME ) . '/languages' );

		// Modules first: they declare their own settings sections, and anything
		// that reads a setting freezes the schema as it finds it.
		$this->register_modules();

		// Then compatibility: it declares the exclusions the rest depends on.
		( new Compat\WooCommerce( $this ) )->boot();
		( new Compat\Bricks( $this ) )->boot();
		( new Compat\Bricks_Ecommerce( $this ) )->boot();

		$this->purge->boot();

		foreach ( $this->modules as $module ) {
			if ( $module->is_enabled() ) {
				$module->boot();
			}
		}

		if ( is_admin() ) {
			( new Admin\Admin( $this ) )->boot();
		}

		( new Admin\Admin_Bar( $this ) )->boot();

		add_action( 'bricks_cache_settings_updated', [ $this, 'on_settings_updated' ], 10, 2 );

		// The config file the drop-in reads is built partly from what other
		// plugins declare. Activating one changes the answer, and the drop-in
		// would keep applying yesterday's exclusions until the next save.
		foreach ( [ 'activated_plugin', 'deactivated_plugin', 'switch_theme', 'upgrader_process_complete' ] as $hook ) {
			add_action( $hook, [ $this, 'rebuild_config' ], 20, 0 );
		}

		add_action( 'updated_option', [ $this, 'maybe_rebuild_config' ] );
		add_action( 'init', [ $this, 'maybe_upgrade' ], 1 );
		add_action( 'admin_init', [ $this, 'heal_dropin' ] );

		/**
		 * Fires once every service and module is registered.
		 *
		 * @param Plugin $plugin Container.
		 */
		do_action( 'bricks_cache_booted', $this );
	}

	/**
	 * Build the module list. New optimisations are added here, or from the
	 * outside through the filter.
	 */
	private function register_modules(): void {
		$modules = [
			new Modules\Page_Cache( $this ),
			new Modules\Css( $this ),
		];

		/**
		 * Filter the registered modules.
		 *
		 * @param Module_Interface[] $modules Module instances.
		 * @param Plugin             $plugin  Container.
		 */
		$modules = (array) apply_filters( 'bricks_cache_modules', $modules, $this );

		foreach ( $modules as $module ) {
			if ( ! $module instanceof Module_Interface ) {
				continue;
			}

			$this->modules[ $module->id() ] = $module;

			if ( $module instanceof Module ) {
				$module->register_settings();
			}
		}

		Settings::reset_schema_cache();
	}

	/**
	 * Bring the filesystem in line with the settings after a save: the config
	 * file, the drop-in, the WP_CACHE constant and the scheduled cleanup.
	 *
	 * @param array<string,mixed> $after  New values.
	 * @param array<string,mixed> $before Previous values.
	 */
	public function on_settings_updated( array $after, array $before ): void {
		$was_on = ! empty( $before['page_cache']['enabled'] );
		$is_on  = ! empty( $after['page_cache']['enabled'] );

		Filesystem::prepare();
		$this->config->write();

		if ( $is_on && ! $was_on ) {
			$this->enable_page_cache();
		}

		if ( ! $is_on && $was_on ) {
			$this->disable_page_cache();
		}

		$this->sync_schedule();

		// Rules changed, so anything already stored was stored under the old
		// ones. Cheaper to rebuild than to reason about it.
		$this->purge->all( 'settings_updated' );
	}

	/**
	 * Install the drop-in and switch WP_CACHE on, reporting any failure to the
	 * admin instead of pretending the cache is running.
	 */
	public function enable_page_cache(): void {
		$installed = Dropin::install();

		if ( is_wp_error( $installed ) ) {
			$this->notice( $installed->get_error_message(), 'error' );
			$this->settings->set( 'page_cache.enabled', false );
			$this->logger->error( 'Drop-in install failed.', [ 'error' => $installed->get_error_code() ] );

			return;
		}

		$constant = Dropin::set_wp_cache( true );

		if ( is_wp_error( $constant ) ) {
			$this->notice(
				$constant->get_error_message() . ' ' . sprintf(
					/* translators: %s: PHP line to add. */
					__( 'Añade esta línea al principio de wp-config.php: %s', 'bricks-cache' ),
					Dropin::manual_line()
				),
				'warning'
			);
		}

		$this->logger->info( 'Page cache enabled.' );
	}

	/**
	 * Remove the drop-in, switch WP_CACHE off and empty the cache.
	 */
	public function disable_page_cache(): void {
		Dropin::uninstall();
		Dropin::set_wp_cache( false );
		$this->store->flush();

		$this->logger->info( 'Page cache disabled.' );
	}

	/**
	 * Keep the hourly cleanup scheduled only while it is wanted.
	 */
	public function sync_schedule(): void {
		$wanted    = $this->settings->on( 'invalidation.cleanup_expired' ) && $this->settings->on( 'page_cache.enabled' );
		$scheduled = (bool) wp_next_scheduled( 'bricks_cache_cleanup' );

		if ( $wanted && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'bricks_cache_cleanup' );
		}

		if ( ! $wanted && $scheduled ) {
			wp_clear_scheduled_hook( 'bricks_cache_cleanup' );
		}
	}

	/**
	 * Put the drop-in back when the settings say the cache is on and the file
	 * is missing or old.
	 *
	 * The drop-in lives outside the plugin, so anything can take it away: a
	 * deactivation cycle during an update, a migration, a host cleaning
	 * wp-content. Without this check the admin would keep saying the cache is
	 * on while nothing at all is being cached, which is the worst of both
	 * worlds. Only runs in the admin, so it costs a visitor nothing.
	 */
	public function heal_dropin(): void {
		if ( ! $this->settings->on( 'page_cache.enabled' ) || Dropin::is_current() ) {
			return;
		}

		if ( Dropin::exists() && ! Dropin::is_ours() ) {
			return;
		}

		$this->config->write();

		$installed = Dropin::install();

		if ( is_wp_error( $installed ) ) {
			$this->logger->error( 'Drop-in could not be restored.', [ 'error' => $installed->get_error_code() ] );

			return;
		}

		if ( ! Dropin::wp_cache_enabled() ) {
			Dropin::set_wp_cache( true );
		}

		$this->logger->warning( 'Drop-in was missing and has been restored.' );

		$this->notice( __( 'Faltaba el archivo advanced-cache.php y se ha vuelto a instalar.', 'bricks-cache' ), 'warning' );
	}

	/**
	 * Rewrite the drop-in configuration from the current state of the site.
	 */
	public function rebuild_config(): void {
		Filesystem::prepare();
		$this->config->write();
	}

	/**
	 * Options that move a page the drop-in must never cache, or change how
	 * URLs are built.
	 *
	 * @param string $option Option name.
	 */
	public function maybe_rebuild_config( $option ): void {
		$watched = [
			'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id',
			'woocommerce_myaccount_page_id',
			'woocommerce_shop_page_id',
			'permalink_structure',
			'home',
			'siteurl',
		];

		if ( in_array( (string) $option, $watched, true ) ) {
			$this->rebuild_config();
		}
	}

	/**
	 * Refresh the generated files after a plugin update, since a new version
	 * can ship a new drop-in or new config keys.
	 */
	public function maybe_upgrade(): void {
		if ( get_option( 'bricks_cache_version' ) === BRICKS_CACHE_VERSION ) {
			return;
		}

		Filesystem::prepare();
		$this->config->write();

		if ( $this->settings->on( 'page_cache.enabled' ) && ! Dropin::is_current() ) {
			Dropin::install();
		}

		$this->purge->all( 'plugin_upgraded' );
		$this->sync_schedule();

		update_option( 'bricks_cache_version', BRICKS_CACHE_VERSION, true );

		$this->logger->info( 'Plugin upgraded.', [ 'version' => BRICKS_CACHE_VERSION ] );
	}

	/**
	 * Queue an admin notice for the next screen the user sees.
	 *
	 * @param string $message Message in Spanish.
	 * @param string $type    error|warning|success|info.
	 */
	public function notice( string $message, string $type = 'info' ): void {
		$notices   = get_transient( 'bricks_cache_notices' );
		$notices   = is_array( $notices ) ? $notices : [];
		$notices[] = [
			'message' => $message,
			'type'    => $type,
		];

		set_transient( 'bricks_cache_notices', $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Settings service.
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Logger service.
	 */
	public function logger(): Logger {
		return $this->logger;
	}

	/**
	 * Config writer.
	 */
	public function config(): Config {
		return $this->config;
	}

	/**
	 * Active backend.
	 */
	public function store(): Store_Interface {
		return $this->store;
	}

	/**
	 * Eligibility rules.
	 */
	public function rules(): Rules {
		return $this->rules;
	}

	/**
	 * Invalidation.
	 */
	public function purge(): Purge {
		return $this->purge;
	}

	/**
	 * Registered modules.
	 *
	 * @return array<string,Module_Interface>
	 */
	public function modules(): array {
		return $this->modules;
	}

	/**
	 * One module by id.
	 *
	 * @param string $id Module identifier.
	 */
	public function module( string $id ): ?Module_Interface {
		return $this->modules[ $id ] ?? null;
	}
}
