<?php
declare( strict_types=1 );

namespace WCMCS\Services\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the pre-aggregated wcmcs_currency_stats_daily table — every
 * query here is against a small, indexed, day-bucketed table, never the
 * orders table itself, regardless of how wide a date range is requested.
 */
class CurrencyStatsRepository {

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wcmcs_currency_stats_daily';
	}

	/**
	 * Totals per currency for a date range: order count, revenue (in
	 * that currency), revenue in base-currency terms, and average order
	 * value — the core "revenue by currency" breakdown.
	 *
	 * @return array<string, array{order_count: int, revenue: float, revenue_base_currency: float, average_order_value: float}>
	 */
	public function totalsByCurrency( string $fromDate, string $toDate ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT currency, SUM(order_count) AS order_count, SUM(revenue) AS revenue, SUM(revenue_base_currency) AS revenue_base_currency
				FROM {$this->table()} WHERE stat_date BETWEEN %s AND %s GROUP BY currency", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$fromDate,
				$toDate
			),
			ARRAY_A
		);

		$totals = array();

		foreach ( $rows ?: array() as $row ) {
			$orderCount = (int) $row['order_count'];
			$revenue    = (float) $row['revenue'];

			$totals[ strtoupper( (string) $row['currency'] ) ] = array(
				'order_count'           => $orderCount,
				'revenue'               => $revenue,
				'revenue_base_currency' => (float) $row['revenue_base_currency'],
				'average_order_value'   => $orderCount > 0 ? $revenue / $orderCount : 0.0,
			);
		}

		return $totals;
	}

	/**
	 * Day-by-day revenue per currency — the line chart's data source.
	 *
	 * @return array{stat_date: string, currency: string, revenue: float}[]
	 */
	public function dailyTrend( string $fromDate, string $toDate ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, currency, revenue, order_count FROM {$this->table()} WHERE stat_date BETWEEN %s AND %s ORDER BY stat_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$fromDate,
				$toDate
			),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ) => array(
				'stat_date'   => (string) $row['stat_date'],
				'currency'    => strtoupper( (string) $row['currency'] ),
				'revenue'     => (float) $row['revenue'],
				'order_count' => (int) $row['order_count'],
			),
			$rows ?: array()
		);
	}

	public function hasAnyData(): bool {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} LIMIT 1" ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, not user data
	}

	public function earliestDate(): ?string {
		global $wpdb;

		$date = $wpdb->get_var( "SELECT MIN(stat_date) FROM {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, not user data

		return $date ?: null;
	}
}
