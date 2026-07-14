<?php
/**
 * Fires only when the plugin is deleted from the Plugins screen (not on
 * simple deactivation), and only when WP_UNINSTALL_PLUGIN is defined,
 * which WordPress guarantees for files named exactly "uninstall.php".
 * Removes every trace of the plugin: tables, options, transients, cron.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/Core/Installer.php';
require_once __DIR__ . '/includes/Core/Cron.php';

function wcmcs_uninstall_single_site(): void {
	global $wpdb;

	\WCMCS\Core\Installer::drop_tables();

	// Options.
	$options = array(
		'wcmcs_settings',
		'wcmcs_db_version',
		'wcmcs_manual_rates',
		'wcmcs_provider_priority',
		'wcmcs_provider_exchangerateapi_key',
		'wcmcs_provider_openexchangerates_key',
		'wcmcs_provider_fixer_key',
		'wcmcs_rate_refresh_interval',
		'wcmcs_enabled_currencies',
		'wcmcs_last_rate_success_at',
		'wcmcs_rate_alert_sent',
		'wcmcs_rate_alert_threshold_hours',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Transients (including their timeout siblings).
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wcmcs\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wcmcs\\_%'"
	);

	// Cron.
	\WCMCS\Core\Cron::unschedule();
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		wcmcs_uninstall_single_site();
		restore_current_blog();
	}
} else {
	wcmcs_uninstall_single_site();
}
