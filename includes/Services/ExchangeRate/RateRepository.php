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
	 * @return array{id: int, rate: float, source: string, created_at: string}[] Newest first.
	 */
	public function history( string $base, string $target, int $limit = 30 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, rate, source, created_at FROM {$this->table()} WHERE base_currency = %s AND target_currency = %s ORDER BY created_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				strtoupper( $base ),
				strtoupper( $target ),
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ) => array(
				'id'         => (int) $row['id'],
				'rate'       => (float) $row['rate'],
				'source'     => (string) $row['source'],
				'created_at' => (string) $row['created_at'],
			),
			$rows ?: array()
		);
	}

	/**
	 * Looks up a single history row by its own id — used by rollback, so
	 * the rate being restored is always one that genuinely exists in
	 * history for that exact currency pair, never an arbitrary
	 * client-supplied number.
	 *
	 * @return array{id: int, base_currency: string, target_currency: string, rate: float, source: string, created_at: string}|null
	 */
	public function findById( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, base_currency, target_currency, rate, source, created_at FROM {$this->table()} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'id'              => (int) $row['id'],
			'base_currency'   => (string) $row['base_currency'],
			'target_currency' => (string) $row['target_currency'],
			'rate'            => (float) $row['rate'],
			'source'          => (string) $row['source'],
			'created_at'      => (string) $row['created_at'],
		);
	}

	/**
	 * One rate per day (the last one recorded that day — a "closing
	 * rate", matching financial convention) for a date range — used to
	 * line up against CurrencyStatsRepository's daily sales figures at
	 * the same granularity, since history() can have many rows per day
	 * (one per scheduled refresh) which doesn't align with day-bucketed
	 * sales data. Written as a MAX(id)-per-day self-join rather than a
	 * window function (ROW_NUMBER), since window functions need MySQL 8
	 * / MariaDB 10.2+ and this needs to work on older hosts too.
	 *
	 * @return array<string, float> Date (Y-m-d) => rate.
	 */
	public function dailyRates( string $base, string $target, string $fromDate, string $toDate ): array {
		global $wpdb;

		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is this repository's own table name, never user data.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(t1.created_at) AS day, t1.rate AS rate
				FROM {$table} t1
				WHERE t1.base_currency = %s AND t1.target_currency = %s
					AND DATE(t1.created_at) BETWEEN %s AND %s
					AND t1.id = (
						SELECT MAX(t2.id) FROM {$table} t2
						WHERE t2.base_currency = t1.base_currency AND t2.target_currency = t1.target_currency
							AND DATE(t2.created_at) = DATE(t1.created_at)
					)
				ORDER BY day ASC",
				strtoupper( $base ),
				strtoupper( $target ),
				$fromDate,
				$toDate
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = array();

		foreach ( $rows ?: array() as $row ) {
			$result[ (string) $row['day'] ] = (float) $row['rate'];
		}

		return $result;
	}
}
