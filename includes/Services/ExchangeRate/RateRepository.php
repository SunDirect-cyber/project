<?php
namespace WCMCS\Services\ExchangeRate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the wcmcs_exchange_rates table (created by
 * WCMCS\Core\Installer) — the permanent history of every rate the plugin
 * has used, which is what lets historical orders stay accurate even
 * after today's rate changes (see OrderRepository, which snapshots the
 * rate onto the order itself at time of purchase).
 */
class RateRepository {

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wcmcs_exchange_rates';
	}

	public function store( string $base, string $target, float $rate, string $source ): void {
		global $wpdb;

		$wpdb->insert(
			$this->table(),
			array(
				'base_currency'   => strtoupper( $base ),
				'target_currency' => strtoupper( $target ),
				'rate'            => $rate,
				'source'          => $source,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%f', '%s', '%s' )
		);
	}

	/**
	 * Most recently stored rate for this currency pair, regardless of
	 * age — callers that care about freshness should check the returned
	 * row's age themselves via latestWithAge().
	 */
	public function latest( string $base, string $target ): ?float {
		global $wpdb;

		$rate = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rate FROM {$this->table()} WHERE base_currency = %s AND target_currency = %s ORDER BY created_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				strtoupper( $base ),
				strtoupper( $target )
			)
		);

		return null === $rate ? null : (float) $rate;
	}

	/**
	 * @return array{rate: float, created_at: string}|null
	 */
	public function latestWithAge( string $base, string $target ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT rate, created_at FROM {$this->table()} WHERE base_currency = %s AND target_currency = %s ORDER BY created_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				strtoupper( $base ),
				strtoupper( $target )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'rate'       => (float) $row['rate'],
			'created_at' => (string) $row['created_at'],
		);
	}

	/**
	 * @return array{rate: float, source: string, created_at: string}[]
	 */
	public function history( string $base, string $target, int $limit = 30 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rate, source, created_at FROM {$this->table()} WHERE base_currency = %s AND target_currency = %s ORDER BY created_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				strtoupper( $base ),
				strtoupper( $target ),
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ) => array(
				'rate'       => (float) $row['rate'],
				'source'     => (string) $row['source'],
				'created_at' => (string) $row['created_at'],
			),
			$rows ?: array()
		);
	}
}
