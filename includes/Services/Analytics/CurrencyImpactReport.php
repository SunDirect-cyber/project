<?php
declare( strict_types=1 );

namespace WCMCS\Services\Analytics;

use WCMCS\Core\Hpos;
use WCMCS\Services\CurrencyService;
use WCMCS\Services\ExchangeRate\RateService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The accounting reconciliation report: for every order placed in a
 * foreign currency, compares what it was actually worth in base
 * currency at the rate active when it was placed (the
 * _wcmcs_exchange_rate / _wcmcs_base_currency_total meta
 * OrderCurrencyRecorder snapshots at checkout — see the Price
 * Conversion Engine work) against what that same foreign-currency
 * amount would be worth today, at today's live rate. The difference is
 * the theoretical currency gain or loss — not something the store
 * actually gained or lost in the bank (the money was already settled at
 * the historical rate), but the standard "what if" figure an accountant
 * uses for currency exposure reconciliation.
 *
 * Deliberately uses RateService's raw market rate for "today", not
 * PriceConversionService's business-adjusted one (locked rate/markup) —
 * this report is about real currency market movement, not the store's
 * own pricing decisions.
 */
class CurrencyImpactReport {

	private const PAID_STATUSES = array( 'wc-completed', 'wc-processing' );

	private RateService $rateService;
	private CurrencyService $currencyService;

	public function __construct( RateService $rateService, CurrencyService $currencyService ) {
		$this->rateService     = $rateService;
		$this->currencyService = $currencyService;
	}

	/**
	 * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
	 */
	public function generate( string $fromDate, string $toDate ): array {
		$orders = Hpos::is_enabled() ? $this->queryHpos( $fromDate, $toDate ) : $this->queryLegacy( $fromDate, $toDate );
		$base   = $this->currencyService->baseCurrency()->code();

		// Resolve today's live rate once per distinct currency present in
		// the result set, not once per order — the same "resolve once,
		// reuse many times" principle as the storefront price converter.
		$currentRates = array();
		foreach ( $orders as $order ) {
			$currency = $order['currency'];

			if ( $currency !== $base && ! array_key_exists( $currency, $currentRates ) ) {
				$currentRates[ $currency ] = $this->rateService->getRate( $base, $currency );
			}
		}

		$rows             = array();
		$totalHistorical  = 0.0;
		$totalCurrent     = 0.0;
		$missingDataCount = 0;

		foreach ( $orders as $order ) {
			$currency = $order['currency'];

			if ( $currency === $base ) {
				$rows[]           = array_merge(
					$order,
					array(
						'historical_rate'       => 1.0,
						'historical_base_value' => (float) $order['total'],
						'current_rate'          => 1.0,
						'current_base_value'    => (float) $order['total'],
						'gain_loss'             => 0.0,
					)
				);
				$totalHistorical += (float) $order['total'];
				$totalCurrent    += (float) $order['total'];
				continue;
			}

			$historicalRate = null !== $order['historical_rate'] ? (float) $order['historical_rate'] : null;
			$historicalBase = null !== $order['historical_base_value'] ? (float) $order['historical_base_value'] : null;
			$currentRate    = $currentRates[ $currency ] ?? null;

			if ( null === $historicalRate || null === $historicalBase || null === $currentRate || $currentRate <= 0 ) {
				++$missingDataCount;
				$rows[] = array_merge(
					$order,
					array(
						'historical_rate'       => $historicalRate,
						'historical_base_value' => $historicalBase,
						'current_rate'          => $currentRate,
						'current_base_value'    => null,
						'gain_loss'             => null,
					)
				);
				continue;
			}

			$currentBaseValue = (float) $order['total'] / $currentRate;
			$gainLoss         = $currentBaseValue - $historicalBase;

			$rows[] = array_merge(
				$order,
				array(
					'historical_rate'       => $historicalRate,
					'historical_base_value' => $historicalBase,
					'current_rate'          => $currentRate,
					'current_base_value'    => $currentBaseValue,
					'gain_loss'             => $gainLoss,
				)
			);

			$totalHistorical += $historicalBase;
			$totalCurrent    += $currentBaseValue;
		}

		return array(
			'rows'    => $rows,
			'summary' => array(
				'base_currency'          => $base,
				'total_historical_value' => $totalHistorical,
				'total_current_value'    => $totalCurrent,
				'total_gain_loss'        => $totalCurrent - $totalHistorical,
				'orders_missing_data'    => $missingDataCount,
				'order_count'            => count( $orders ),
			),
		);
	}

	/**
	 * @return array<int, array{order_id: int, date: string, currency: string, total: float, historical_rate: ?float, historical_base_value: ?float}>
	 */
	private function queryHpos( string $fromDate, string $toDate ): array {
		global $wpdb;

		$statusPlaceholders = implode( ',', array_fill( 0, count( self::PAID_STATUSES ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names/status placeholder list, never user data; array_merge()'s count is count(self::PAID_STATUSES) + 2 dates, which the sniff can't statically resolve.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id AS order_id, o.date_created_gmt AS date, o.currency AS currency, o.total_amount AS total,
					m_rate.meta_value AS historical_rate, m_base.meta_value AS historical_base_value
				FROM {$wpdb->prefix}wc_orders o
				LEFT JOIN {$wpdb->prefix}wc_orders_meta m_rate ON m_rate.order_id = o.id AND m_rate.meta_key = '_wcmcs_exchange_rate'
				LEFT JOIN {$wpdb->prefix}wc_orders_meta m_base ON m_base.order_id = o.id AND m_base.meta_key = '_wcmcs_base_currency_total'
				WHERE o.status IN ({$statusPlaceholders}) AND DATE(o.date_created_gmt) BETWEEN %s AND %s
				ORDER BY o.date_created_gmt DESC",
				array_merge( self::PAID_STATUSES, array( $fromDate, $toDate ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return $this->normalize( $rows );
	}

	/**
	 * @return array<int, array{order_id: int, date: string, currency: string, total: float, historical_rate: ?float, historical_base_value: ?float}>
	 */
	private function queryLegacy( string $fromDate, string $toDate ): array {
		global $wpdb;

		$statusPlaceholders = implode( ',', array_fill( 0, count( self::PAID_STATUSES ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- see queryHpos() above, same reasoning.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS order_id, p.post_date_gmt AS date, pm_currency.meta_value AS currency, pm_total.meta_value AS total,
					pm_rate.meta_value AS historical_rate, pm_base.meta_value AS historical_base_value
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_currency ON pm_currency.post_id = p.ID AND pm_currency.meta_key = '_order_currency'
				INNER JOIN {$wpdb->postmeta} pm_total ON pm_total.post_id = p.ID AND pm_total.meta_key = '_order_total'
				LEFT JOIN {$wpdb->postmeta} pm_rate ON pm_rate.post_id = p.ID AND pm_rate.meta_key = '_wcmcs_exchange_rate'
				LEFT JOIN {$wpdb->postmeta} pm_base ON pm_base.post_id = p.ID AND pm_base.meta_key = '_wcmcs_base_currency_total'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$statusPlaceholders}) AND DATE(p.post_date_gmt) BETWEEN %s AND %s
				ORDER BY p.post_date_gmt DESC",
				array_merge( self::PAID_STATUSES, array( $fromDate, $toDate ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return $this->normalize( $rows );
	}

	/**
	 * @param array<int, array<string, mixed>>|null $rows
	 * @return array<int, array{order_id: int, date: string, currency: string, total: float, historical_rate: ?float, historical_base_value: ?float}>
	 */
	private function normalize( ?array $rows ): array {
		return array_map(
			static fn ( array $row ) => array(
				'order_id'              => (int) $row['order_id'],
				'date'                  => (string) $row['date'],
				'currency'              => strtoupper( (string) $row['currency'] ),
				'total'                 => (float) $row['total'],
				'historical_rate'       => null !== $row['historical_rate'] ? (float) $row['historical_rate'] : null,
				'historical_base_value' => null !== $row['historical_base_value'] ? (float) $row['historical_base_value'] : null,
			),
			$rows ?: array()
		);
	}
}
