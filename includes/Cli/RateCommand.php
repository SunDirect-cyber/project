<?php
declare( strict_types=1 );

namespace WCMCS\Cli;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage exchange rates from the command line.
 *
 * Registered as `wp wcmcs rate <subcommand>`.
 */
class RateCommand {

	/**
	 * Refreshes exchange rates now, exactly as the cron job or the
	 * admin "Refresh Rates Now" button would.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcmcs rate refresh
	 */
	public function refresh(): void {
		$container = Plugin::instance()->container();

		/** @var \WCMCS\Services\ExchangeRate\RateService $rateService */
		$rateService = $container->get( 'rate_service' );
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );

		$base    = $currencyService->baseCurrency()->code();
		$targets = (array) get_option( 'wcmcs_enabled_currencies', array() );

		if ( empty( $targets ) ) {
			\WP_CLI::warning( 'No currencies are enabled — nothing to refresh.' );
			return;
		}

		$results = $rateService->refreshAll( $base, $targets );

		$rows = array();
		foreach ( $results as $currency => $result ) {
			$rows[] = array(
				'currency' => $currency,
				'rate'     => null === $result['rate'] ? 'FAILED' : $result['rate'],
				'source'   => $result['source'] ?? '—',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'currency', 'rate', 'source' ) );

		$failures = count( array_filter( $results, static fn ( $r ) => null === $r['rate'] ) );

		if ( $failures > 0 ) {
			\WP_CLI::warning( "{$failures} currency(ies) failed to refresh." );
		} else {
			\WP_CLI::success( 'All rates refreshed.' );
		}
	}

	/**
	 * Shows the current rate for one currency pair.
	 *
	 * ## OPTIONS
	 *
	 * <target>
	 * : Target currency code, e.g. EUR.
	 *
	 * [--base=<base>]
	 * : Base currency code. Defaults to the store's base currency.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcmcs rate show EUR
	 *     wp wcmcs rate show EUR --base=GBP
	 */
	public function show( array $args, array $assoc_args ): void {
		$container = Plugin::instance()->container();
		/** @var \WCMCS\Services\ExchangeRate\RateService $rateService */
		$rateService = $container->get( 'rate_service' );
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );

		$target = strtoupper( $args[0] ?? '' );
		$base   = strtoupper( $assoc_args['base'] ?? $currencyService->baseCurrency()->code() );

		$rate = $rateService->getRate( $base, $target );

		if ( null === $rate ) {
			\WP_CLI::error( "No rate available for {$base} -> {$target}." );
			return;
		}

		\WP_CLI::log( "1 {$base} = {$rate} {$target}" );
	}
}
