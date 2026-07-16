<?php
declare( strict_types=1 );

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
	public const DB_VERSION = '1.3.0';

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$exchange_rates_table = $wpdb->prefix . 'wcmcs_exchange_rates';
		$currency_rules_table = $wpdb->prefix . 'wcmcs_currency_rules';
		$logs_table           = $wpdb->prefix . 'wcmcs_logs';
		$stats_daily_table    = $wpdb->prefix . 'wcmcs_currency_stats_daily';
		$events_table         = $wpdb->prefix . 'wcmcs_currency_events';

		$sql = "
CREATE TABLE {$exchange_rates_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    base_currency CHAR(3) NOT NULL,
    target_currency CHAR(3) NOT NULL,
    rate DECIMAL(20,10) NOT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'manual',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY currency_pair (base_currency, target_currency, created_at),
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

CREATE TABLE {$events_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(20) NOT NULL,
    session_id VARCHAR(64) NULL,
    country CHAR(2) NULL,
    from_currency CHAR(3) NULL,
    to_currency CHAR(3) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY event_type (event_type),
    KEY session_id (session_id),
    KEY created_at (created_at),
    KEY event_type_created (event_type, created_at),
    KEY to_currency_created (to_currency, created_at)
) {$charset_collate};
";

		dbDelta( $sql );

		// dbDelta() reliably creates missing tables/columns but is well
		// known not to reliably add or modify indexes on tables that
		// already exist — so on an upgrade (not a fresh install) the two
		// composite indexes added in 1.3.0 need to be added explicitly.
		self::ensure_index(
			$exchange_rates_table,
			'currency_pair',
			'(base_currency, target_currency, created_at)'
		);
		self::ensure_index( $events_table, 'event_type_created', '(event_type, created_at)' );
		self::ensure_index( $events_table, 'to_currency_created', '(to_currency, created_at)' );

		update_option( 'wcmcs_db_version', self::DB_VERSION );
	}

	/**
	 * Adds an index if it doesn't already exist, and replaces it if it
	 * exists with different columns (e.g. the pre-1.3.0 two-column
	 * currency_pair index being widened to three columns) — dbDelta()
	 * won't do either of these reliably for tables that already exist.
	 */
	private static function ensure_index( string $table, string $indexName, string $columnsSql ): void {
		global $wpdb;

		$existingColumns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX',
				$table,
				$indexName
			)
		);

		$wantedColumns = array_map( 'trim', explode( ',', trim( $columnsSql, '()' ) ) );

		if ( $existingColumns === $wantedColumns ) {
			return; // Already exactly right — nothing to do.
		}

		if ( ! empty( $existingColumns ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX {$indexName}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/index name only, not user data
		}

		$wpdb->query( "ALTER TABLE {$table} ADD INDEX {$indexName} {$columnsSql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/index name only, not user data
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
			$wpdb->prefix . 'wcmcs_currency_events',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/index name only, not user data
		}
	}
}
