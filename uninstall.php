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

function wcmcs_uninstall_single_site(): void {
	global $wpdb;

	\WCMCS\Core\Installer::drop_tables();

	// Options.
	delete_option( 'wcmcs_settings' );
	delete_option( 'wcmcs_db_version' );

	// Transients (including their timeout siblings).
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wcmcs\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wcmcs\\_%'"
	);

	// Cron.
	wp_clear_scheduled_hook( 'wcmcs_refresh_exchange_rates' );
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
