<?php
declare( strict_types=1 );

namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the "graceful deactivation" path: if WooCommerce (or another
 * requirement) is no longer met on a site where our plugin is already
 * active — e.g. the site owner deactivated WooCommerce afterwards — we
 * deactivate ourselves and show a plain admin notice instead of letting
 * any WooCommerce class reference blow up with a fatal error.
 */
class Compatibility {

	public static function register(): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		add_action( 'admin_init', array( self::class, 'maybe_self_deactivate' ) );
		add_action( 'admin_notices', array( self::class, 'render_notices' ) );

		// Keep new sites on a network-activated install in sync with the
		// rest of the network.
		add_action( 'wp_initialize_site', array( Activator::class, 'activate_new_site' ) );
	}

	public static function maybe_self_deactivate(): void {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( Requirements::are_met() ) {
			return;
		}

		// Don't deactivate on the very request where the admin is looking
		// at the plugin they just activated — avoids surprising redirects.
		if ( ! is_plugin_active( WCMCS_BASENAME ) ) {
			return;
		}

		deactivate_plugins( WCMCS_BASENAME );
		set_transient( 'wcmcs_deactivation_notice', Requirements::check(), MINUTE_IN_SECONDS * 5 );

		unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function render_notices(): void {
		$errors = get_transient( 'wcmcs_deactivation_notice' );

		if ( empty( $errors ) || ! is_array( $errors ) ) {
			return;
		}

		delete_transient( 'wcmcs_deactivation_notice' );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p></div>',
			esc_html__( 'WooCommerce Multi-Currency Switcher was deactivated.', 'wc-multicurrency-switcher' ),
			wp_kses_post( implode( '<br>', array_map( 'esc_html', $errors ) ) )
		);
	}
}
