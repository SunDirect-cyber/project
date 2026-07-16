<?php
namespace WCMCS\Services\Analytics;

use WCMCS\Core\Hpos;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rolls up real order data into the wcmcs_currency_stats_daily table —
 * one row per (date, currency) with order count and revenue. This is
 * what makes the analytics screens fast: they read this small
 * pre-aggregated table, never the full orders table, no matter how far
 * back a report's date range goes.
 *
 * "revenue_base_currency" comes from the _wcmcs_base_currency_total
 * order meta OrderCurrencyRecorder already snapshots at checkout (see
 * the Price Conversion Engine work) — falls back to the order's own
 * total for orders placed before that meta existed, or already in the
 * base currency.
 */
class CurrencyStatsAggregationService {

	private const PAID_STATUSES = array( 'wc-completed', 'wc-processing' );

	public function aggregateDate( string $date ): void {
		global $wpdb;

		$rows = Hpos::is_enabled() ? $this->queryHpos( $date ) : $this->queryLegacy( $date );

		foreach ( $rows as $row ) {
			$currency = strtoupper( (string) $row['currency'] );

			if ( '' === $currency ) {
				continue;
			}

			$table = $wpdb->prefix . 'wcmcs_currency_stats_daily';
			$now   = current_time( 'mysql' );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a hardcoded table name, never user data.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (stat_date, currency, order_count, revenue, revenue_base_currency, created_at, updated_at)
					VALUES (%s, %s, %d, %f, %f, %s, %s)
					ON DUPLICATE KEY UPDATE order_count = VALUES(order_count), revenue = VALUES(revenue), revenue_base_currency = VALUES(revenue_base_currency), updated_at = VALUES(updated_at)",
					$date,
					$currency,
					(int) $row['order_count'],
					(float) $row['revenue'],
					(float) $row['revenue_base_currency'],
					$now,
					$now
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Re-aggregates every day in [$fromDate, $toDate] inclusive — used to
	 * backfill history on first activation of this feature, or to catch
	 * up if the daily cron was missed for a stretch.
	 */
	public function aggregateRange( string $fromDate, string $toDate ): void {
		$current = strtotime( $fromDate );
		$end     = strtotime( $toDate );

		while ( $current <= $end ) {
			$this->aggregateDate( gmdate( 'Y-m-d', $current ) );
			$current = strtotime( '+1 day', $current );
		}
	}

	/**
	 * @return array{currency: string, order_count: int, revenue: float, revenue_base_currency: float}[]
	 */
	private function queryHpos( string $date ): array {
		global $wpdb;

		$statusPlaceholders = implode( ',', array_fill( 0, count( self::PAID_STATUSES ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$wpdb->prefix}/{$statusPlaceholders} are table names and a %s-per-status placeholder list, never user data; array_merge()'s element count (which the sniff can't statically resolve) is exactly count(self::PAID_STATUSES) status placeholders + 1 date placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.currency AS currency,
					COUNT(*) AS order_count,
					SUM(o.total_amount) AS revenue,
					SUM(COALESCE(m.meta_value + 0, o.total_amount)) AS revenue_base_currency
				FROM {$wpdb->prefix}wc_orders o
				LEFT JOIN {$wpdb->prefix}wc_orders_meta m ON m.order_id = o.id AND m.meta_key = '_wcmcs_base_currency_total'
				WHERE o.status IN ({$statusPlaceholders}) AND DATE(o.date_created_gmt) = %s
				GROUP BY o.currency",
				array_merge( self::PAID_STATUSES, array( $date ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return $this->normalize( $rows );
	}

	/**
	 * @return array{currency: string, order_count: int, revenue: float, revenue_base_currency: float}[]
	 */
	private function queryLegacy( string $date ): array {
		global $wpdb;

		$statusPlaceholders = implode( ',', array_fill( 0, count( self::PAID_STATUSES ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- see queryHpos() above, same reasoning.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm_currency.meta_value AS currency,
					COUNT(*) AS order_count,
					SUM(pm_total.meta_value + 0) AS revenue,
					SUM(COALESCE(pm_base.meta_value + 0, pm_total.meta_value + 0)) AS revenue_base_currency
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_currency ON pm_currency.post_id = p.ID AND pm_currency.meta_key = '_order_currency'
				INNER JOIN {$wpdb->postmeta} pm_total ON pm_total.post_id = p.ID AND pm_total.meta_key = '_order_total'
				LEFT JOIN {$wpdb->postmeta} pm_base ON pm_base.post_id = p.ID AND pm_base.meta_key = '_wcmcs_base_currency_total'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$statusPlaceholders}) AND DATE(p.post_date_gmt) = %s
				GROUP BY pm_currency.meta_value",
				array_merge( self::PAID_STATUSES, array( $date ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return $this->normalize( $rows );
	}

	/**
	 * @param array<int, array<string, mixed>>|null $rows
	 * @return array{currency: string, order_count: int, revenue: float, revenue_base_currency: float}[]
	 */
	private function normalize( ?array $rows ): array {
		return array_map(
			static fn ( array $row ) => array(
				'currency'              => (string) $row['currency'],
				'order_count'           => (int) $row['order_count'],
				'revenue'               => (float) $row['revenue'],
				'revenue_base_currency' => (float) $row['revenue_base_currency'],
			),
			$rows ?: array()
		);
	}
}
