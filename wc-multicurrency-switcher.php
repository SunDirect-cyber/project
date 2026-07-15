<?php
/**
 * Plugin Name: WooCommerce Multi-Currency Switcher
 * Plugin URI:  https://example.com/wc-multicurrency-switcher
 * Description: Adds full multi-currency support to WooCommerce with live exchange rates, currency rules, and native currency formatting for 150+ world currencies.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * Author:      Your Company
 * Text Domain: wc-multicurrency-switcher
 * Domain Path: /languages
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants, used throughout the codebase instead of hardcoded paths.
define( 'WCMCS_VERSION', '1.0.0' );
define( 'WCMCS_FILE', __FILE__ );
define( 'WCMCS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WCMCS_URL', plugin_dir_url( __FILE__ ) );
define( 'WCMCS_BASENAME', plugin_basename( __FILE__ ) );

// Load Composer's autoloader if it exists, otherwise fall back to our own
// lightweight PSR-4 autoloader so the plugin still works without `composer install`.
if ( file_exists( WCMCS_PATH . 'vendor/autoload.php' ) ) {
	require_once WCMCS_PATH . 'vendor/autoload.php';
} else {
	require_once WCMCS_PATH . 'includes/Core/Autoloader.php';
	( new \WCMCS\Core\Autoloader() )->register();
}

// The public, memorable static API (the global WCMCS class) — deliberately
// not autoloaded like the rest of the plugin, since it's a plain global
// class rather than a namespaced one. Safe to require unconditionally:
// every method on it checks WCMCS::isActive() before touching anything.
require_once WCMCS_PATH . 'includes/wcmcs-api.php';

// Must be registered now, not inside plugins_loaded — WooCommerce reads
// the feature compatibility list on 'before_woocommerce_init', which can
// fire before our own 'plugins_loaded' callback runs.
\WCMCS\Core\Hpos::register();

/**
 * Boots the plugin once all plugins are loaded, so we can safely check
 * whether WooCommerce is active before touching any WooCommerce classes.
 */
function wcmcs_init() {
	\WCMCS\Core\Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'wcmcs_init' );

// Activation / deactivation hooks must be registered from the main plugin
// file — WordPress will not detect them if they live in an included file.
register_activation_hook( __FILE__, array( '\WCMCS\Core\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\WCMCS\Core\Deactivator', 'deactivate' ) );
