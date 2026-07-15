<?php
namespace WCMCS\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes to the wcmcs_logs table (created by WCMCS\Core\Installer).
 * Used mainly by the exchange-rate providers to record fetch failures,
 * so a store owner can see *why* rates stopped updating instead of just
 * noticing stale prices.
 */
class LoggerService {

	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	public function log( string $level, string $message, array $context = array() ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wcmcs_logs',
			array(
				'level'      => $level,
				'message'    => $message,
				'context'    => empty( $context ) ? null : wp_json_encode( $context ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Most recent log entries, optionally filtered to one level — used by
	 * the Rate Providers admin screen to show "last error, if any"
	 * without the admin needing to dig through raw table data.
	 *
	 * @return array{level: string, message: string, created_at: string}[]
	 */
	public function recent( ?string $level = null, int $limit = 5 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wcmcs_logs';

		if ( null !== $level ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT level, message, created_at FROM {$table} WHERE level = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$level,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT level, message, created_at FROM {$table} ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$limit
				),
				ARRAY_A
			);
		}

		return array_map(
			static fn ( array $row ) => array(
				'level'      => (string) $row['level'],
				'message'    => (string) $row['message'],
				'created_at' => (string) $row['created_at'],
			),
			$rows ?: array()
		);
	}
}
