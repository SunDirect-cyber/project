<?php
namespace WCMCS\Services\ExchangeRate;

use WCMCS\Services\CacheService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The service everything else in the plugin should use to get an
 * exchange rate or convert an amount — orchestrates the cache, the rate
 * history table, and the provider fallback chain, so callers never talk
 * to any of those three directly.
 *
 * Lookup order for a single rate: transient cache -> live provider chain
 * (result gets cached and stored to history) -> last resort, the most
 * recent rate already in the history table, in case every provider is
 * down and there's nothing fresher to serve.
 */
class RateService {

	private RateProviderChain $chain;
	private RateRepository $repository;
	private CacheService $cache;

	public function __construct( RateProviderChain $chain, RateRepository $repository, CacheService $cache ) {
		$this->chain      = $chain;
		$this->repository = $repository;
		$this->cache      = $cache;
	}

	public function getRate( string $base, string $target ): ?float {
		$base   = strtoupper( $base );
		$target = strtoupper( $target );

		if ( $base === $target ) {
			return 1.0;
		}

		$cacheKey = "rate_{$base}_{$target}";
		$cached   = $this->cache->get( $cacheKey );

		if ( false !== $cached ) {
			return (float) $cached;
		}

		$rate = $this->fetchLive( $base, $target );

		if ( null !== $rate ) {
			$this->cache->set( $cacheKey, $rate, $this->cacheTtl() );
			return $rate;
		}

		// Every provider failed (or none configured beyond a chain that
		// itself came back empty) — serve the last rate we actually
		// stored, however old, rather than breaking checkout entirely.
		$stale = $this->repository->latest( $base, $target );

		if ( null !== $stale ) {
			// Cache it too, but briefly — we don't want a stale fallback
			// to keep getting served for the full TTL once providers
			// recover.
			$this->cache->set( $cacheKey, $stale, MINUTE_IN_SECONDS * 5 );
		}

		return $stale;
	}

	/**
	 * @param float|int|string $amount
	 */
	public function convert( $amount, string $base, string $target ): ?float {
		$rate = $this->getRate( $base, $target );

		return null === $rate ? null : ( (float) $amount ) * $rate;
	}

	/**
	 * Fetches straight from the provider chain, bypassing the cache, and
	 * records the result to history on success. Used both by getRate()
	 * on a cache miss and by refreshAll() during a scheduled/manual refresh.
	 */
	public function fetchLive( string $base, string $target ): ?float {
		$rate = $this->chain->getRate( $base, $target );

		if ( null !== $rate ) {
			$this->repository->store( $base, $target, $rate, $this->chain->getSourceName() );
		}

		return $rate;
	}

	/**
	 * Refreshes the base currency's rate against every enabled currency.
	 * This is what the cron job and the "Refresh Rates Now" admin button
	 * both call.
	 *
	 * @return array<string, array{rate: float|null, source: ?string}> Keyed by target currency code.
	 */
	public function refreshAll( string $baseCurrency, array $targetCurrencies ): array {
		$results = array();

		foreach ( $targetCurrencies as $target ) {
			$target = strtoupper( $target );

			if ( $target === strtoupper( $baseCurrency ) ) {
				continue;
			}

			$rate = $this->fetchLive( $baseCurrency, $target );

			if ( null !== $rate ) {
				$this->cache->set( "rate_{$baseCurrency}_{$target}", $rate, $this->cacheTtl() );
			}

			$results[ $target ] = array(
				'rate'   => $rate,
				'source' => null === $rate ? null : $this->chain->getSourceName(),
			);
		}

		return $results;
	}

	/**
	 * Cache lifetime, tied to the configured refresh interval by default
	 * so cached rates don't outlive the next scheduled refresh, but
	 * filterable for stores that want a shorter/longer window.
	 */
	private function cacheTtl(): int {
		$seconds = \WCMCS\Core\Cron::intervalToSeconds( (string) get_option( 'wcmcs_rate_refresh_interval', 'hourly' ) );

		return (int) apply_filters( 'wcmcs_rate_cache_ttl', $seconds );
	}
}
