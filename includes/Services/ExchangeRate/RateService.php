<?php
declare( strict_types=1 );

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
	private RateValidator $validator;

	public function __construct( RateProviderChain $chain, RateRepository $repository, CacheService $cache, RateValidator $validator ) {
		$this->chain      = $chain;
		$this->repository = $repository;
		$this->cache      = $cache;
		$this->validator  = $validator;
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
	 * Fetches straight from the provider chain, bypassing the cache,
	 * runs the result through RateValidator, and records it to history
	 * only if it passes. A rate that fails validation (out of bounds, or
	 * too big a swing from the last known rate) is treated exactly like
	 * a fetch failure — it's discarded, never stored, and null is
	 * returned so callers fall back to the last known-good rate instead.
	 * Used both by getRate() on a cache miss and by refreshAll() during a
	 * scheduled/manual refresh.
	 */
	public function fetchLive( string $base, string $target ): ?float {
		/**
		 * Fires immediately before this plugin asks its provider chain for
		 * a live rate — a hook point for logging, metrics, or short-
		 * circuiting the outbound API call entirely with a custom provider
		 * registered via the wcmcs_register_services action instead.
		 *
		 * @param string $base   Base currency code.
		 * @param string $target Target currency code.
		 */
		do_action( 'wcmcs_before_rate_fetch', $base, $target );

		$rate = $this->chain->getRate( $base, $target );

		if ( null !== $rate ) {
			/**
			 * Filters a freshly fetched rate before it's validated and
			 * stored — the hook point for a store-specific adjustment that
			 * needs to apply to every rate this plugin ever records, not
			 * just at display time (see wcmcs_effective_rate for that).
			 *
			 * @param float  $rate   The raw rate the provider chain returned.
			 * @param string $base   Base currency code.
			 * @param string $target Target currency code.
			 */
			$rate = (float) apply_filters( 'wcmcs_rate_before_apply', $rate, $base, $target );
		}

		if ( null === $rate ) {
			/**
			 * Fires after a rate fetch attempt completes, successfully or
			 * not. $rate is null on failure (every provider in the chain
			 * failed, or validation rejected the result below).
			 *
			 * @param string     $base   Base currency code.
			 * @param string     $target Target currency code.
			 * @param float|null $rate   The stored rate, or null on failure.
			 */
			do_action( 'wcmcs_after_rate_fetch', $base, $target, null );

			return null;
		}

		$previous   = $this->repository->latest( $base, $target );
		$validation = $this->validator->validate( $base, $target, $rate, $previous );

		if ( ! $validation['valid'] ) {
			do_action( 'wcmcs_after_rate_fetch', $base, $target, null );

			return null;
		}

		$this->repository->store( $base, $target, $rate, $this->chain->getSourceName() );

		do_action( 'wcmcs_after_rate_fetch', $base, $target, $rate );

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
	 * Reverts a currency pair to a previous value from its own history —
	 * e.g. after a bad API response corrupted the current rate. Rather
	 * than deleting the bad row(s), this appends a new history entry
	 * carrying the old rate forward, tagged with source "rollback", so
	 * the audit trail still shows exactly what happened and when. Also
	 * evicts the cached rate for that pair so the reverted value takes
	 * effect immediately instead of waiting out the cache TTL.
	 *
	 * @return float|null The restored rate, or null if $historyId doesn't exist.
	 */
	public function rollbackTo( int $historyId ): ?float {
		$row = $this->repository->findById( $historyId );

		if ( null === $row ) {
			return null;
		}

		$base   = $row['base_currency'];
		$target = $row['target_currency'];

		$this->repository->store( $base, $target, $row['rate'], 'rollback' );
		$this->cache->delete( "rate_{$base}_{$target}" );

		return $row['rate'];
	}

	/**
	 * @return array{id: int, rate: float, source: string, created_at: string}[]
	 */
	public function getHistory( string $base, string $target, int $limit = 30 ): array {
		return $this->repository->history( $base, $target, $limit );
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
