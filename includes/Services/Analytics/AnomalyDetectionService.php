<?php
namespace WCMCS\Services\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The two anomaly types the spec asks for that aren't already covered
 * by RateFailureMonitor (which already handles "unusual rate fetch
 * failures" — no need to duplicate that here). Both read
 * CurrencyStatsRepository, the same pre-aggregated table the rest of
 * the analytics screens use.
 */
class AnomalyDetectionService {

	private CurrencyStatsRepository $statsRepository;

	public function __construct( CurrencyStatsRepository $statsRepository ) {
		$this->statsRepository = $statsRepository;
	}

	/**
	 * A currency whose recent daily order volume has dropped sharply
	 * compared to its own recent baseline — e.g. a rate spike having
	 * pushed local prices up (see the Rate Correlation report for
	 * checking that specific theory).
	 *
	 * @return array{currency: string, recent_avg: float, baseline_avg: float, drop_percent: float}[]
	 */
	public function checkConversionDrops( array $currencies, int $recentDays = 3, int $baselineDays = 14, float $dropThresholdPercent = 50.0 ): array {
		$today = gmdate( 'Y-m-d' );

		$recentFrom   = gmdate( 'Y-m-d', strtotime( "-{$recentDays} days" ) );
		$baselineTo   = gmdate( 'Y-m-d', strtotime( '-' . ( $recentDays + 1 ) . ' days' ) );
		$baselineFrom = gmdate( 'Y-m-d', strtotime( '-' . ( $recentDays + $baselineDays ) . ' days' ) );

		$recentTotals   = $this->statsRepository->totalsByCurrency( $recentFrom, $today );
		$baselineTotals = $this->statsRepository->totalsByCurrency( $baselineFrom, $baselineTo );

		$anomalies = array();

		foreach ( $currencies as $currency ) {
			$currency = strtoupper( $currency );

			$recentAvg   = ( $recentTotals[ $currency ]['order_count'] ?? 0 ) / max( 1, $recentDays );
			$baselineAvg = ( $baselineTotals[ $currency ]['order_count'] ?? 0 ) / max( 1, $baselineDays );

			// No meaningful baseline (a new or very low-volume currency) —
			// nothing to compare a "drop" against.
			if ( $baselineAvg < 0.2 ) {
				continue;
			}

			$dropPercent = ( ( $baselineAvg - $recentAvg ) / $baselineAvg ) * 100;

			if ( $dropPercent >= $dropThresholdPercent ) {
				$anomalies[] = array(
					'currency'     => $currency,
					'recent_avg'   => round( $recentAvg, 2 ),
					'baseline_avg' => round( $baselineAvg, 2 ),
					'drop_percent' => round( $dropPercent, 1 ),
				);
			}
		}

		return $anomalies;
	}

	/**
	 * A currency that's had visible interest (switches to it, or
	 * visitors from its country) but zero completed orders for a
	 * stretch — evidence something in that market's checkout flow isn't
	 * working, not just "nobody's shopping there".
	 *
	 * @return array{currency: string, days: int}[]
	 */
	public function checkZeroSalesDespiteTraffic( array $currencies, string $baseCurrency, int $days = 7 ): array {
		global $wpdb;

		$from = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$to   = gmdate( 'Y-m-d' );

		$totals = $this->statsRepository->totalsByCurrency( $from, $to );
		$events = $wpdb->prefix . 'wcmcs_currency_events';

		// wcmcs_currency_events.created_at is written via current_time()
		// (site-local), unlike stat_date above (a UTC day bucket, matching
		// order date_created_gmt) — the traffic-check below needs its own
		// site-local boundary, not the UTC one used for the stats query.
		$trafficFrom = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

		$anomalies = array();

		foreach ( $currencies as $currency ) {
			$currency = strtoupper( $currency );

			if ( $currency === strtoupper( $baseCurrency ) ) {
				continue;
			}

			$orderCount = $totals[ $currency ]['order_count'] ?? 0;

			if ( $orderCount > 0 ) {
				continue;
			}

			$hasTraffic = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$events} WHERE to_currency = %s AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$currency,
					$trafficFrom
				)
			) > 0;

			if ( $hasTraffic ) {
				$anomalies[] = array(
					'currency' => $currency,
					'days'     => $days,
				);
			}
		}

		return $anomalies;
	}
}
