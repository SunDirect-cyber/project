<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and updates the plugin's custom database tables. Runs on
 * activation and, via wcmcs_db_version, on any future upgrade that needs
 * a schema change (dbDelta() is safe to re-run — it only adds what's
 * missing rather than dropping data).
 */
class Installer {

	// Bump this whenever the table schema changes, so run_if_needed() can
	// detect upgrades on existing installs, not just fresh activations.
	public const DB_VERSION = '1.1.0';

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$exchange_rates_table = $wpdb->prefix . 'wcmcs_exchange_rates';
		$currency_rules_table = $wpdb->prefix . 'wcmcs_currency_rules';
		$logs_table            = $wpdb->prefix . 'wcmcs_logs';
		$stats_daily_table     = $wpdb->prefix . 'wcmcs_currency_stats_daily';

		$sql = "
CREATE TABLE {$exchange_rates_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    base_currency CHAR(3) NOT NULL,
    target_currency CHAR(3) NOT NULL,
    rate DECIMAL(20,10) NOT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'manual',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY currency_pair (base_currency, target_currency),
    KEY created_at (created_at)
) {$charset_collate};

CREATE TABLE {$currency_rules_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    currency CHAR(3) NOT NULL,
    rule_type VARCHAR(30) NOT NULL,
    rule_value TEXT NOT NULL,
    priority SMALLINT NOT NULL DEFAULT 10,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY currency (currency),
    KEY is_active (is_active)
) {$charset_collate};

CREATE TABLE {$logs_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level VARCHAR(20) NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    context LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY level (level),
    KEY created_at (created_at)
) {$charset_collate};

CREATE TABLE {$stats_daily_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    stat_date DATE NOT NULL,
    currency CHAR(3) NOT NULL,
    order_count INT UNSIGNED NOT NULL DEFAULT 0,
    revenue DECIMAL(20,4) NOT NULL DEFAULT 0,
    revenue_base_currency DECIMAL(20,4) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY date_currency (stat_date, currency),
    KEY stat_date (stat_date)
) {$charset_collate};
";

		dbDelta( $sql );

		update_option( 'wcmcs_db_version', self::DB_VERSION );
	}

	/**
	 * Re-runs install() only if the stored DB version is behind the
	 * current one, so it's cheap to call on every 'plugins_loaded'.
	 */
	public static function run_if_needed(): void {
		if ( get_option( 'wcmcs_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function drop_tables(): void {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'wcmcs_exchange_rates',
			$wpdb->prefix . 'wcmcs_currency_rules',
			$wpdb->prefix . 'wcmcs_logs',
			$wpdb->prefix . 'wcmcs_currency_stats_daily',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}
}
