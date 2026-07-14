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
}
