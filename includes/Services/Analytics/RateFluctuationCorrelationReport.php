<?php
declare( strict_types=1 );

namespace WCMCS\Services\Analytics;

use WCMCS\Services\CurrencyService;
use WCMCS\Services\ExchangeRate\RateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lines up a currency's daily exchange rate against its daily sales for
 * the same date range, so a store owner can see for themselves whether
 * a sales dip coincided with (and was plausibly caused by) a rate spike
 * that made the local price jump — rather than the plugin guessing at
 * causation, which day-level correlation data genuinely can't prove on
 * its own.
 */
class RateFluctuationCorrelationReport {

	private RateRepository $rateRepository;
	private CurrencyStatsRepository $statsRepository;
	private CurrencyService $currencyService;

	public function __construct( RateRepository $rateRepository, CurrencyStatsRepository $statsRepository, CurrencyService $currencyService ) {
		$this->rateRepository  = $rateRepository;
		$this->statsRepository = $statsRepository;
		$this->currencyService = $currencyService;
	}

	/**
	 * @return array{date: string, rate: ?float, order_count: int, revenue: float}[]
	 */
	public function generate( string $currency, string $fromDate, string $toDate ): array {
		$base = $this->currencyService->baseCurrency()->code();

		$rates = $base === strtoupper( $currency )
			? array()
			: $this->rateRepository->dailyRates( $base, $currency, $fromDate, $toDate );

		$salesByDate = array();

		foreach ( $this->statsRepository->dailyTrend( $fromDate, $toDate ) as $row ) {
			if ( $row['currency'] !== strtoupper( $currency ) ) {
				continue;
			}

			$salesByDate[ $row['stat_date'] ] = $row;
		}

		$series  = array();
		$current = strtotime( $fromDate );
		$end     = strtotime( $toDate );

		while ( $current <= $end ) {
			$date = gmdate( 'Y-m-d', $current );

			$series[] = array(
				'date'        => $date,
				'rate'        => $rates[ $date ] ?? null,
				'order_count' => $salesByDate[ $date ]['order_count'] ?? 0,
				'revenue'     => $salesByDate[ $date ]['revenue'] ?? 0.0,
			);

			$current = strtotime( '+1 day', $current );
		}

		return $series;
	}
}
