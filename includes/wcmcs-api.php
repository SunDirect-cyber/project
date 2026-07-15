<?php
/**
 * Public PHP API — the "simple, memorable" static entry point other
 * plugins or a theme's functions.php can use without digging into this
 * plugin's internals or its DI container. Deliberately a plain global
 * class (not namespaced) so calling it needs no `use` statement, the
 * same reasoning WooCommerce itself uses for the global `WC()` function.
 *
 * Every method here is a thin, defensive wrapper around the same
 * services the plugin's own code uses internally — this file adds no
 * new business logic of its own, only a stable, documented surface on
 * top of it. See docs/api-reference.md for full documentation and
 * examples of each method.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WCMCS' ) ) {

	final class WCMCS {

		private function __construct() {}

		/**
		 * Whether the plugin is active and its services are available —
		 * every other method on this class returns a safe empty/null
		 * value automatically when this is false, so callers don't have
		 * to check it themselves before every call, but it's here for
		 * anyone who wants to branch on it directly (e.g. to hide a
		 * currency-dependent UI element entirely rather than let it
		 * render with fallback values).
		 */
		public static function isActive(): bool {
			return class_exists( \WCMCS\Core\Plugin::class )
				&& \WCMCS\Core\Plugin::instance()->container()->has( 'currency_service' );
		}

		/**
		 * The currency the current visitor is shopping in — their own
		 * choice if they've made one, otherwise the store's base currency.
		 */
		public static function currentCurrency(): string {
			if ( ! self::isActive() ) {
				return get_option( 'woocommerce_currency', 'USD' );
			}

			$container   = \WCMCS\Core\Plugin::instance()->container();
			$persistence = $container->get( 'currency_persistence_service' );
			$currency    = $persistence->getCurrency();

			return $currency ?? self::baseCurrency();
		}

		/**
		 * The store's base currency (WooCommerce > Settings > General),
		 * regardless of what any visitor has selected.
		 */
		public static function baseCurrency(): string {
			if ( ! self::isActive() ) {
				return get_option( 'woocommerce_currency', 'USD' );
			}

			/** @var \WCMCS\Services\CurrencyService $currencyService */
			$currencyService = \WCMCS\Core\Plugin::instance()->container()->get( 'currency_service' );

			return $currencyService->baseCurrency()->code();
		}

		/**
		 * The store's currently enabled currency codes (uppercase ISO
		 * 4217) — filterable via the wcmcs_supported_currencies filter.
		 *
		 * @return string[]
		 */
		public static function currencies(): array {
			if ( ! self::isActive() ) {
				return array();
			}

			/** @var \WCMCS\Services\CurrencyService $currencyService */
			$currencyService = \WCMCS\Core\Plugin::instance()->container()->get( 'currency_service' );

			return $currencyService->enabledCurrencyCodes();
		}

		/**
		 * Full details (name, symbol, decimals, formatting) for every
		 * enabled currency.
		 *
		 * @return array<string, array{code: string, name: string, symbol: string, decimals: int, symbol_position: string, thousand_separator: string, decimal_separator: string}>
		 */
		public static function allCurrencies(): array {
			if ( ! self::isActive() ) {
				return array();
			}

			/** @var \WCMCS\Services\CurrencyService $currencyService */
			$currencyService = \WCMCS\Core\Plugin::instance()->container()->get( 'currency_service' );

			$result = array();

			foreach ( self::currencies() as $code ) {
				$currency = $currencyService->get( $code );

				if ( null !== $currency ) {
					$result[ $code ] = $currency->toArray();
				}
			}

			return $result;
		}

		/**
		 * Converts an amount from the base currency (or an explicit
		 * $fromCurrency) into $toCurrency (default: the current visitor's
		 * active currency), applying this plugin's exact rate, markup,
		 * rounding, and decimal-precision rules — the same result a
		 * shopper would actually see on a product page.
		 *
		 * Returns null if conversion isn't currently possible (no rate
		 * available for that pair, plugin inactive), rather than a
		 * silently wrong number — callers should treat null as "fall
		 * back to displaying the original amount", never as zero.
		 */
		public static function convert( float $amount, ?string $toCurrency = null, ?string $fromCurrency = null ): ?float {
			if ( ! self::isActive() ) {
				return null;
			}

			$container = \WCMCS\Core\Plugin::instance()->container();

			/** @var \WCMCS\Services\CurrencyService $currencyService */
			$currencyService = $container->get( 'currency_service' );
			/** @var \WCMCS\Services\PriceConversionService $priceConversion */
			$priceConversion = $container->get( 'price_conversion_service' );

			$from = $fromCurrency ? strtoupper( $fromCurrency ) : self::baseCurrency();
			$to   = $toCurrency ? strtoupper( $toCurrency ) : self::currentCurrency();

			if ( $from === $to ) {
				return $amount;
			}

			$converted = $priceConversion->convert( $amount, $from, $to );

			if ( null === $converted ) {
				return null;
			}

			// Match the same final decimal-precision step PriceConverter
			// applies on the storefront (see PriceConverter::convertAndRound)
			// — without this, a zero-decimal currency like JPY would come
			// back from this API with fractional yen a shopper never sees.
			$currency = $currencyService->get( $to );
			$decimals = null !== $currency ? $currency->decimals() : 2;

			return round( $converted, $decimals );
		}

		/**
		 * Formats an amount using a currency's native symbol/position/
		 * separators — e.g. 1234.5 in EUR becomes "€1.234,50" or
		 * "1.234,50 €" depending on that currency's configured formatting.
		 * Defaults to the current visitor's active currency.
		 */
		public static function format( float $amount, ?string $currency = null ): string {
			if ( ! self::isActive() ) {
				return (string) $amount;
			}

			/** @var \WCMCS\Services\CurrencyService $currencyService */
			$currencyService = \WCMCS\Core\Plugin::instance()->container()->get( 'currency_service' );

			return $currencyService->format( $amount, $currency ?? self::currentCurrency() );
		}

		/**
		 * Force-sets the current visitor's active currency (persisted the
		 * same way a manual switcher click is: user meta if logged in,
		 * session/cookie either way), firing the same
		 * wcmcs_before_currency_switch / wcmcs_currency_switched hooks a
		 * normal switch does. Returns false without doing anything if
		 * $code isn't one of the store's currently enabled currencies.
		 */
		public static function setCurrency( string $code ): bool {
			if ( ! self::isActive() ) {
				return false;
			}

			$code = strtoupper( trim( $code ) );

			if ( ! in_array( $code, self::currencies(), true ) ) {
				return false;
			}

			/** @var \WCMCS\Services\CurrencyPersistenceService $persistence */
			$persistence = \WCMCS\Core\Plugin::instance()->container()->get( 'currency_persistence_service' );
			$persistence->setCurrency( $code, \WCMCS\Services\SessionService::SOURCE_MANUAL );

			return true;
		}

		/**
		 * The exchange rate recorded for $targetCurrency (against the
		 * store's base currency, or an explicit $baseCurrency) on a
		 * specific calendar date — the closing (last-recorded-that-day)
		 * rate, matching what the rate history charts show. Returns null
		 * if no rate was ever recorded for that pair on that date.
		 */
		public static function historicalRate( string $targetCurrency, string $date, ?string $baseCurrency = null ): ?float {
			if ( ! self::isActive() ) {
				return null;
			}

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return null;
			}

			/** @var \WCMCS\Services\ExchangeRate\RateRepository $rateRepository */
			$rateRepository = \WCMCS\Core\Plugin::instance()->container()->get( 'rate_repository' );

			$base  = $baseCurrency ? strtoupper( $baseCurrency ) : self::baseCurrency();
			$rates = $rateRepository->dailyRates( $base, strtoupper( $targetCurrency ), $date, $date );

			return $rates[ $date ] ?? null;
		}
	}
}
