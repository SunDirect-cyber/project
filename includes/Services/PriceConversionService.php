<?php
namespace WCMCS\Services;

use WCMCS\Services\CurrencyRule\PricingRuleService;
use WCMCS\Services\ExchangeRate\RateService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rate customers/checkout should actually see: composes the raw
 * market rate from RateService with the store owner's pricing rules
 * (lock / markup / rounding) from PricingRuleService.
 *
 * The split matters — RateService's cache and history stay a truthful
 * record of real market rates (needed for accurate rollback and rate
 * charts), while the *effective* rate applied to prices can differ from
 * that whenever a currency is locked or has a markup configured. This
 * class is the one place those two are combined.
 */
class PriceConversionService {

	private RateService $rateService;
	private PricingRuleService $pricingRules;
	private CacheService $cache;

	public function __construct( RateService $rateService, PricingRuleService $pricingRules, CacheService $cache ) {
		$this->rateService  = $rateService;
		$this->pricingRules = $pricingRules;
		$this->cache        = $cache;
	}

	/**
	 * The rate to actually use for converting prices into $target: a
	 * locked rate if one is configured (which never touches live
	 * providers at all), otherwise the live/cached market rate with
	 * this currency's markup applied on top.
	 */
	public function getEffectiveRate( string $base, string $target ): ?float {
		$base   = strtoupper( $base );
		$target = strtoupper( $target );

		if ( $base === $target ) {
			return 1.0;
		}

		$locked = $this->pricingRules->getLockedRate( $target );

		if ( null !== $locked ) {
			return $locked;
		}

		$cacheKey = "effective_rate_{$base}_{$target}";
		$cached   = $this->cache->get( $cacheKey );

		if ( false !== $cached ) {
			return (float) $cached;
		}

		$raw = $this->rateService->getRate( $base, $target );

		if ( null === $raw ) {
			return null;
		}

		$effective = $this->pricingRules->applyMarkup( $target, $raw );

		// Short TTL: this is a derived value on top of RateService's own
		// cache, so it just needs to survive a burst of page loads, not
		// outlive a markup-percent change the admin just made.
		$this->cache->set( $cacheKey, $effective, MINUTE_IN_SECONDS * 15 );

		return $effective;
	}

	/**
	 * Converts an amount and applies this currency's rounding rule
	 * (e.g. charm pricing to X.99) to the result — what should actually
	 * be shown to a customer, as opposed to raw arithmetic.
	 *
	 * @param float|int|string $amount
	 */
	public function convert( $amount, string $base, string $target ): ?float {
		$rate = $this->getEffectiveRate( $base, $target );

		if ( null === $rate ) {
			return null;
		}

		return $this->pricingRules->roundPrice( $target, ( (float) $amount ) * $rate );
	}

	/**
	 * Clears the cached effective rate for a pair — call this whenever a
	 * lock/markup/rounding rule changes, so the new rule takes effect on
	 * the very next request instead of waiting out the cache TTL.
	 */
	public function invalidate( string $base, string $target ): void {
		$this->cache->delete( 'effective_rate_' . strtoupper( $base ) . '_' . strtoupper( $target ) );
	}
}
