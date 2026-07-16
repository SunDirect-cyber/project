<?php
declare( strict_types=1 );

namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place for the plugin's minimum requirements. Used both at
 * activation time (to block activation outright) and at runtime on every
 * page load (to show an admin notice if something changed later, e.g.
 * WooCommerce got deactivated after our plugin was already active).
 */
class Requirements {

	public const MIN_PHP_VERSION = '7.4';
	public const MIN_WP_VERSION  = '6.0';
	public const MIN_WC_VERSION  = '7.0';

	/**
	 * Returns an array of human-readable error strings, one per failed
	 * requirement. An empty array means all requirements are met.
	 *
	 * @return string[]
	 */
	public static function check(): array {
		$errors = array();

		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				__( 'WooCommerce Multi-Currency Switcher requires PHP %1$s or higher. You are running PHP %2$s.', 'wc-multicurrency-switcher' ),
				self::MIN_PHP_VERSION,
				PHP_VERSION
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), self::MIN_WP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version */
				__( 'WooCommerce Multi-Currency Switcher requires WordPress %1$s or higher. You are running WordPress %2$s.', 'wc-multicurrency-switcher' ),
				self::MIN_WP_VERSION,
				get_bloginfo( 'version' )
			);
		}

		if ( ! self::is_woocommerce_active() ) {
			$errors[] = __( 'WooCommerce Multi-Currency Switcher requires WooCommerce to be installed and active.', 'wc-multicurrency-switcher' );
		} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, self::MIN_WC_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: required WooCommerce version, 2: current WooCommerce version */
				__( 'WooCommerce Multi-Currency Switcher requires WooCommerce %1$s or higher. You are running WooCommerce %2$s.', 'wc-multicurrency-switcher' ),
				self::MIN_WC_VERSION,
				WC_VERSION
			);
		}

		return $errors;
	}

	public static function are_met(): bool {
		return empty( self::check() );
	}

	public static function is_woocommerce_active(): bool {
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		// class_exists() alone can be unreliable this early (e.g. during
		// network activation), so also check the active plugins list.
		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $active_plugins as $plugin ) {
			if ( false !== strpos( $plugin, '/woocommerce.php' ) ) {
				return true;
			}
		}

		return false;
	}
}
