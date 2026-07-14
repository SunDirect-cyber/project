<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs once, when the plugin is activated.
 */
class Activator {

	/**
	 * @param bool $network_wide True if activated network-wide on a multisite install.
	 *                           WordPress passes this automatically to the
	 *                           'activate_{plugin}' hook.
	 */
	public static function activate( bool $network_wide = false ): void {
		// Hard requirements: PHP and WordPress version. These can't be
		// worked around, so we block activation outright with a clear,
		// controlled message rather than letting the plugin activate into
		// a broken state.
		$blocking_errors = self::blocking_errors();

		if ( ! empty( $blocking_errors ) ) {
			deactivate_plugins( WCMCS_BASENAME );

			wp_die(
				wp_kses_post(
					'<p>' . implode( '</p><p>', $blocking_errors ) . '</p>' .
					'<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' .
					esc_html__( '&laquo; Return to Plugins', 'wc-multicurrency-switcher' ) . '</a></p>'
				),
				esc_html__( 'Plugin activation error', 'wc-multicurrency-switcher' ),
				array( 'back_link' => true )
			);
		}

		// WooCommerce missing is not a blocking error at this stage — we
		// allow activation and let Plugin::dependencies_met() show a
		// friendly admin notice + auto-deactivate on the next request if
		// it's still missing. This avoids a jarring wp_die() for something
		// the site owner may simply be about to fix (e.g. reactivating
		// WooCommerce right after).
		if ( $network_wide && is_multisite() ) {
			foreach ( self::get_site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				self::activate_single_site();
				restore_current_blog();
			}
		} else {
			self::activate_single_site();
		}
	}

	/**
	 * Runs whenever a new site is created on a network where the plugin
	 * is network-activated, so that site also gets the plugin's tables.
	 */
	public static function activate_new_site( \WP_Site $site ): void {
		if ( ! is_plugin_active_for_network( WCMCS_BASENAME ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::activate_single_site();
		restore_current_blog();
	}

	private static function activate_single_site(): void {
		Installer::install();
		self::schedule_cron_events();

		if ( false === get_option( 'wcmcs_settings' ) ) {
			add_option( 'wcmcs_settings', array() );
		}
	}

	private static function schedule_cron_events(): void {
		if ( ! wp_next_scheduled( 'wcmcs_refresh_exchange_rates' ) ) {
			wp_schedule_event( time(), 'hourly', 'wcmcs_refresh_exchange_rates' );
		}
	}

	/**
	 * @return string[]
	 */
	private static function blocking_errors(): array {
		$errors = array();

		if ( version_compare( PHP_VERSION, Requirements::MIN_PHP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				esc_html__( 'WooCommerce Multi-Currency Switcher requires PHP %1$s or higher. You are running PHP %2$s.', 'wc-multicurrency-switcher' ),
				Requirements::MIN_PHP_VERSION,
				PHP_VERSION
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), Requirements::MIN_WP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version */
				esc_html__( 'WooCommerce Multi-Currency Switcher requires WordPress %1$s or higher. You are running WordPress %2$s.', 'wc-multicurrency-switcher' ),
				Requirements::MIN_WP_VERSION,
				get_bloginfo( 'version' )
			);
		}

		return $errors;
	}

	/**
	 * @return int[]
	 */
	private static function get_site_ids(): array {
		if ( ! function_exists( 'get_sites' ) ) {
			return array( get_current_blog_id() );
		}

		return get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
	}
}
