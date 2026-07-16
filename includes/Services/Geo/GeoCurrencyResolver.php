<?php
namespace WCMCS\Services\Geo;

use WCMCS\Services\CacheService;
use WCMCS\Services\CurrencyService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns "detect this visitor's country" into "what currency should we
 * suggest them", applying the full fallback chain: geolocation ->
 * Accept-Language -> the store's base currency. Also the one place that
 * makes sure a detected currency is actually one the store has enabled
 * — geolocation might correctly identify a Brazilian visitor, but if the
 * store never turned BRL on, there's nothing useful to do with that.
 *
 * Caches the result per IP address (not per visitor session) so two
 * different visitors from the same office/ISP share one lookup, and so
 * the same visitor doesn't re-run detection on every single page load.
 */
class GeoCurrencyResolver {

	private const CACHE_TTL_SECONDS = HOUR_IN_SECONDS * 12;

	private GeoCountryDetector $detector;
	private CacheService $cache;
	private CurrencyService $currencyService;

	public function __construct( GeoCountryDetector $detector, CacheService $cache, CurrencyService $currencyService ) {
		$this->detector        = $detector;
		$this->cache           = $cache;
		$this->currencyService = $currencyService;
	}

	/**
	 * @return array{country: ?string, currency: string, detected: bool}
	 */
	public function resolve(): array {
		$cacheKey = $this->cacheKeyForCurrentVisitor();

		if ( null !== $cacheKey ) {
			$cached = $this->cache->get( $cacheKey );

			if ( is_array( $cached ) && isset( $cached['currency'] ) ) {
				return $cached;
			}
		}

		$result = $this->resolveFresh();

		if ( null !== $cacheKey ) {
			$this->cache->set( $cacheKey, $result, self::CACHE_TTL_SECONDS );
		}

		return $result;
	}

	/**
	 * @return array{country: ?string, currency: string, detected: bool}
	 */
	private function resolveFresh(): array {
		$country = $this->detector->detect();
		$base    = $this->currencyService->baseCurrency()->code();

		if ( null === $country ) {
			return $this->filterResult(
				array(
					'country'  => null,
					'currency' => $base,
					'detected' => false,
				),
				$base
			);
		}

		$currency = CountryCurrencyMap::currencyForCountry( $country );

		if ( null === $currency ) {
			return $this->filterResult(
				array(
					'country'  => $country,
					'currency' => $base,
					'detected' => true,
				),
				$base
			);
		}

		$enabled = $this->currencyService->enabledCurrencyCodes();

		// A currency the store hasn't turned on isn't a usable suggestion
		// — fall back to base rather than offering something checkout
		// can't actually price in.
		if ( ! empty( $enabled ) && ! in_array( $currency, $enabled, true ) ) {
			$currency = $base;
		}

		return $this->filterResult(
			array(
				'country'  => $country,
				'currency' => $currency,
				'detected' => true,
			),
			$base
		);
	}

	/**
	 * @param array{country: ?string, currency: string, detected: bool} $result
	 * @return array{country: ?string, currency: string, detected: bool}
	 */
	private function filterResult( array $result, string $base ): array {
		/**
		 * Filters the geolocation result before it's cached and used to
		 * suggest or auto-apply a currency — the hook point for a custom
		 * IP-to-country source, a country/currency override for a
		 * specific market, or forcing detection off for certain visitors.
		 *
		 * @param array{country: ?string, currency: string, detected: bool} $result
		 * @param string $base The store's base currency code.
		 */
		return (array) apply_filters( 'wcmcs_geolocation_result', $result, $base );
	}

	private function cacheKeyForCurrentVisitor(): ?string {
		$ip = class_exists( \WC_Geolocation::class )
			? \WC_Geolocation::get_ip_address()
			: ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

		if ( empty( $ip ) ) {
			return null;
		}

		return 'geo_' . md5( $ip );
	}
}
