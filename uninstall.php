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
require_once __DIR__ . '/includes/Core/AnalyticsCron.php';
require_once __DIR__ . '/includes/Core/CapabilityManager.php';

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
		'wcmcs_last_cron_run_at',
		'wcmcs_last_cron_result',
		'wcmcs_currency_format_overrides',
		'wcmcs_gateway_currency_overrides',
		'wcmcs_currency_remember_mode',
		'wcmcs_currency_switch_confirmation',
		'wcmcs_auto_detection_mode',
		'wcmcs_default_switcher_style',
		'wcmcs_floating_widget_enabled',
		'wcmcs_floating_widget_style',
		'wcmcs_menu_location',
		'wcmcs_rate_deviation_threshold_percent',
		'wcmcs_notification_recipients',
		'wcmcs_notification_summary_frequency',
		'wcmcs_notification_anomaly_alerts',
		'wcmcs_last_summary_sent_at',
		'wcmcs_webhooks',
		'wcmcs_base_currency_history',
		'wcmcs_headless_allowed_origins',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Day-bucketed stats counters and transients (including timeout
	// siblings — this also covers the rate-limiter's own 'wcmcs_rl_*'
	// transients, since they share the '_transient_wcmcs_' prefix).
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wcmcs\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wcmcs\\_%' OR option_name LIKE 'wcmcs\\_stats\\_conversions\\_%'"
	);

	// Cron.
	\WCMCS\Core\Cron::unschedule();
	\WCMCS\Core\AnalyticsCron::unschedule();

	// Capability granted to administrator/shop_manager at activation.
	\WCMCS\Core\CapabilityManager::removeFromAllRoles();
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		wcmcs_uninstall_single_site();
		restore_current_blog();
	}
} else {
	wcmcs_uninstall_single_site();
}
