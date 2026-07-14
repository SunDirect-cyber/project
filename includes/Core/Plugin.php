<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class. Singleton so the plugin is only ever bootstrapped
 * once, even if another script accidentally includes the main file twice.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Container $container;

	private bool $has_run = false;

	private function __construct() {
		$this->container = new Container();
	}

	// Prevent cloning and unserializing, both of which would create a
	// second instance of a class that is meant to be a singleton.
	private function __clone() {}

	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize a singleton.' );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * Boots the plugin. Safe to call multiple times — it only does
	 * anything on the first call.
	 */
	public function run(): void {
		if ( $this->has_run ) {
			return;
		}
		$this->has_run = true;

		// Registered unconditionally: this is what notices the site owner
		// and self-deactivates gracefully if a requirement stops being met
		// (e.g. WooCommerce gets deactivated later), instead of letting
		// missing WooCommerce classes cause a fatal error further down.
		Compatibility::register();

		Installer::run_if_needed();

		if ( ! Requirements::are_met() ) {
			return;
		}

		$this->register_services();
		$this->load_textdomain();

		do_action( 'wcmcs_loaded', $this );
	}

	/**
	 * Registers the internal services in the DI container. Each service
	 * is added here as it's built out; the container only instantiates a
	 * service the first time something actually asks for it.
	 */
	private function register_services(): void {
		do_action( 'wcmcs_register_services', $this->container );
	}

	private function load_textdomain(): void {
		load_plugin_textdomain( 'wc-multicurrency-switcher', false, dirname( WCMCS_BASENAME ) . '/languages' );
	}
}
