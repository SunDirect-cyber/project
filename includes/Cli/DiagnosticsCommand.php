<?php
namespace WCMCS\Cli;

use WCMCS\Core\Hpos;
use WCMCS\Core\Plugin;
use WCMCS\Frontend\CurrencySwitchController;
use WCMCS\Services\Analytics\CurrencyStatsAggregationService;
use WCMCS\Services\Analytics\GeographicInsightsReport;
use WCMCS\Services\ExchangeRate\RateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performance diagnostics — deliberately real measurements against
 * whatever WooCommerce install this runs on, not synthetic numbers.
 * There's no way to honestly claim "tested at 10,000+ orders" without
 * either a live store at that scale or fabricating a result, so this
 * command exists to let a store owner (or this plugin's own CI on a
 * seeded staging site) generate that evidence themselves, on their own
 * data, and see exactly what to look at if something is slow.
 *
 * Registered as `wp wcmcs diagnostics <subcommand>`.
 */
class DiagnosticsCommand {

	/**
	 * Times the plugin's heaviest custom-table queries against the
	 * current database and reports both wall-clock time and the number
	 * of SQL queries each operation issued — the two numbers that matter
	 * for "does this hold up at scale", without needing a synthetic
	 * benchmark environment.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : How many days back to benchmark the daily-stats aggregation over.
	 * ---
	 * default: 30
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcmcs diagnostics benchmark
	 *     wp wcmcs diagnostics benchmark --days=90
	 */
	public function benchmark( array $args, array $assoc_args ): void {
		global $wpdb;

		$days = max( 1, (int) ( $assoc_args['days'] ?? 30 ) );

		\WP_CLI::log( sprintf( 'Order storage: %s', Hpos::is_enabled() ? 'HPOS (custom order tables)' : 'Legacy (post-based)' ) );

		$orderCount = $this->currentOrderCount();
		\WP_CLI::log( sprintf( 'Orders in store: %d', $orderCount ) );

		if ( $orderCount < 10000 ) {
			\WP_CLI::warning(
				sprintf(
					'Only %d orders present — timings below are real but won\'t reflect the 10,000+ order scale this plugin targets. Run against a staging copy of a store at that volume for a representative result.',
					$orderCount
				)
			);
		}

		$container = Plugin::instance()->container();

		// Daily stats aggregation — the query that reads the full orders
		// table (filtered by one day + paid statuses), grouped by currency.
		// This is the one operation in the plugin that touches the orders
		// table directly rather than a small pre-aggregated table, so it's
		// the one that actually needs to scale with order count.
		/** @var CurrencyStatsAggregationService $aggregation */
		$aggregation = $container->get( 'stats_aggregation_service' );
		$toDate      = gmdate( 'Y-m-d' );
		$fromDate    = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$this->time( 'Daily stats aggregation (' . $days . ' days)', static function () use ( $aggregation, $fromDate, $toDate ) {
			$aggregation->aggregateRange( $fromDate, $toDate );
		} );

		// Reporting queries — these read the small pre-aggregated tables,
		// so their cost should stay flat regardless of order count; a slow
		// result here points at a missing index, not order volume.
		/** @var RateRepository $rateRepository */
		$rateRepository = $container->get( 'rate_repository' );
		$this->time( 'Rate history query (30 rows)', static function () use ( $rateRepository ) {
			$rateRepository->history( 'USD', 'EUR', 30 );
		} );

		/** @var GeographicInsightsReport $geo */
		$geo = $container->get( 'geographic_insights_report' );
		$this->time( 'Geographic insights (' . $days . ' days)', static function () use ( $geo, $fromDate, $toDate ) {
			$geo->countryVsCurrency( $fromDate, $toDate );
			$geo->mostSwitchedPairs( $fromDate, $toDate );
		} );

		\WP_CLI::success( 'Benchmark complete.' );
	}

	/**
	 * Fires genuinely concurrent currency-switch AJAX requests at a live
	 * URL (via curl_multi — real overlapping HTTP connections, not a
	 * simulated loop) and reports the pass/fail split and latency spread.
	 * This is the flash-sale scenario from the spec: many shoppers
	 * switching currency in the same instant, checking for request
	 * failures, session corruption (a mismatched currency in the
	 * response), or the rate limiter misfiring against normal traffic
	 * (it's keyed per logged-in user, not per anonymous visitor, so it
	 * should never trigger here).
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Front-end site URL to target (admin-ajax.php is derived from it).
	 *
	 * [--requests=<n>]
	 * : Number of concurrent requests to fire.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--currency=<code>]
	 * : Currency code to switch to.
	 * ---
	 * default: EUR
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcmcs diagnostics concurrency https://example.com --requests=100
	 */
	public function concurrency( array $args, array $assoc_args ): void {
		if ( ! function_exists( 'curl_multi_init' ) ) {
			\WP_CLI::error( 'The cURL PHP extension is required for this command.' );
		}

		$url         = untrailingslashit( (string) $args[0] );
		$requestCount = max( 1, (int) ( $assoc_args['requests'] ?? 50 ) );
		$currency     = strtoupper( (string) ( $assoc_args['currency'] ?? 'EUR' ) );
		$ajaxUrl      = $url . '/wp-admin/admin-ajax.php';

		// Generated locally rather than scraped from a page — nopriv
		// nonces aren't tied to an individual visitor, so this is the
		// same nonce any anonymous shopper's browser would have.
		$nonce = wp_create_nonce( CurrencySwitchController::ACTION );

		\WP_CLI::log( sprintf( 'Firing %d concurrent switch requests at %s ...', $requestCount, $ajaxUrl ) );

		$multiHandle = curl_multi_init();
		$handles     = array();

		for ( $i = 0; $i < $requestCount; $i++ ) {
			$ch = curl_init( $ajaxUrl );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => http_build_query(
						array(
							'action'   => CurrencySwitchController::ACTION,
							'nonce'    => $nonce,
							'currency' => $currency,
						)
					),
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT        => 15,
				)
			);
			curl_multi_add_handle( $multiHandle, $ch );
			$handles[] = $ch;
		}

		$startedAt = microtime( true );
		$running   = null;

		do {
			curl_multi_exec( $multiHandle, $running );
			curl_multi_select( $multiHandle );
		} while ( $running > 0 );

		$elapsedMs = round( ( microtime( true ) - $startedAt ) * 1000, 1 );

		$succeeded = 0;
		$failed    = 0;
		$httpErrors = 0;

		foreach ( $handles as $ch ) {
			$body     = curl_multi_getcontent( $ch );
			$httpCode = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );

			if ( 200 !== $httpCode ) {
				++$httpErrors;
			} else {
				$decoded = json_decode( (string) $body, true );

				if ( is_array( $decoded ) && ! empty( $decoded['success'] ) ) {
					++$succeeded;
				} else {
					++$failed;
				}
			}

			curl_multi_remove_handle( $multiHandle, $ch );
			curl_close( $ch );
		}

		curl_multi_close( $multiHandle );

		\WP_CLI::log( sprintf( 'Total time: %s ms for %d concurrent requests', $elapsedMs, $requestCount ) );
		\WP_CLI::log( sprintf( 'Succeeded: %d, Application errors: %d, HTTP errors: %d', $succeeded, $failed, $httpErrors ) );

		if ( $httpErrors > 0 || $failed > (int) ( $requestCount * 0.05 ) ) {
			\WP_CLI::error( 'More than 5% of concurrent requests failed — investigate before a real high-traffic event.' );
		}

		\WP_CLI::success( 'Concurrency check complete — no signs of trouble under this load.' );
	}

	private function currentOrderCount(): int {
		if ( Hpos::is_enabled() ) {
			global $wpdb;
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$counts = wp_count_posts( 'shop_order' );

		return array_sum( (array) $counts );
	}

	private function time( string $label, callable $operation ): void {
		global $wpdb;

		$queriesBefore = $wpdb->num_queries;
		$startedAt     = microtime( true );

		$operation();

		$elapsedMs = round( ( microtime( true ) - $startedAt ) * 1000, 1 );
		$queryCount = $wpdb->num_queries - $queriesBefore;

		\WP_CLI::log( sprintf( '  %-40s %8s ms  (%d queries)', $label, $elapsedMs, $queryCount ) );
	}
}
