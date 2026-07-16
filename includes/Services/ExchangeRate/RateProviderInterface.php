<?php
declare( strict_types=1 );

namespace WCMCS\Services\ExchangeRate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for anything that can produce an exchange rate between two
 * currencies. RateService depends only on this interface, not on a
 * concrete provider, so a live-API provider (e.g. an HTTP client hitting
 * a rates API) can be swapped in later via the 'wcmcs_rate_provider'
 * filter without changing RateService itself.
 */
interface RateProviderInterface {

	/**
	 * Returns the number of $target currency units that one unit of
	 * $base currency buys, or null if this provider has no rate for
	 * that pair.
	 */
	public function getRate( string $base, string $target ): ?float;

	/**
	 * A short identifier stored alongside the rate in the exchange_rates
	 * table (e.g. 'manual', 'ecb', 'openexchangerates'), so the rate
	 * history records where each rate came from.
	 */
	public function getSourceName(): string;

	/**
	 * Whether this provider has everything it needs to be tried at all
	 * (e.g. an API key is set). Lets RateProviderChain skip a provider
	 * silently instead of wasting an HTTP call it already knows will fail.
	 */
	public function isConfigured(): bool;
}
