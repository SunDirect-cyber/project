<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on deactivation. Deliberately leaves database tables and settings
 * in place — deactivation is often temporary (e.g. troubleshooting a
 * conflict), so only truly transient state is cleared here. Permanent
 * removal happens in uninstall.php, and only on actual uninstall.
 */
class Deactivator {

	public static function deactivate(): void {
		Cron::unschedule();
		AnalyticsCron::unschedule();
	}
}
