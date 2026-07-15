<?php
namespace WCMCS\Services;

use WCMCS\Services\Currency\Currency;
use WCMCS\Services\Currency\CurrencyFormatter;
use WCMCS\Services\Currency\CurrencyRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main entry point for currency lookups and formatting. Everything else
 * in the plugin that needs a Currency object or a formatted price should
 * go through this service rather than touching CurrencyRepository or
 * CurrencyFormatter directly.
 */
class CurrencyService {

	private CurrencyRepository $repository;
	private CurrencyFormatter $formatter;
	private ?Currency $baseCurrencyCache = null;

	public function __construct( CurrencyRepository $repository, CurrencyFormatter $formatter ) {
		$this->repository = $repository;
		$this->formatter  = $formatter;
	}

	public function get( string $code ): ?Currency {
		return $this->repository->get( $code );
	}

	public function exists( string $code ): bool {
		return $this->repository->exists( $code );
	}

	/**
	 * @return Currency[]
	 */
	public function all(): array {
		return $this->repository->all();
	}

	/**
	 * The store's currently enabled currency codes — the authoritative
	 * "what can a shopper actually choose" list, used both to build the
	 * switcher UI and to validate an incoming currency switch request
	 * (?currency= links, the AJAX switch endpoint). Centralized here,
	 * filtered once, rather than each caller reading the raw option
	 * directly, so a third-party integration only has to hook one filter
	 * to reliably add/remove a currency everywhere this plugin enforces
	 * that list — not just from the visible UI.
	 *
	 * @return string[] Uppercase ISO 4217 codes.
	 */
	public function enabledCurrencyCodes(): array {
		$codes = array_values( array_unique( array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) ) ) );

		/**
		 * Filters the store's enabled currency codes.
		 *
		 * @param string[] $codes Uppercase ISO 4217 codes.
		 */
		return (array) apply_filters( 'wcmcs_supported_currencies', $codes );
	}

	/**
	 * The store's own currency, as configured under
	 * WooCommerce > Settings > General. Every other currency this plugin
	 * offers is priced relative to this one.
	 */
	public function baseCurrency(): Currency {
		if ( null !== $this->baseCurrencyCache ) {
			return $this->baseCurrencyCache;
		}

		$code = function_exists( 'get_woocommerce_currency' )
			? get_woocommerce_currency()
			: get_option( 'woocommerce_currency', 'USD' );

		$currency = $this->repository->get( (string) $code );

		// A WooCommerce install always has *some* currency configured, but
		// fall back to USD rather than returning null if that code isn't
		// one we recognise (e.g. a custom/legacy code another plugin added).
		$this->baseCurrencyCache = $currency ?? $this->repository->get( 'USD' );

		return $this->baseCurrencyCache;
	}

	public function isBaseCurrency( string $code ): bool {
		return strtoupper( trim( $code ) ) === $this->baseCurrency()->code();
	}

	/**
	 * @param float|int|string $amount
	 */
	public function format( $amount, ?string $code = null, bool $withSymbol = true ): string {
		$currency = null !== $code ? $this->repository->get( $code ) : $this->baseCurrency();

		if ( null === $currency ) {
			$currency = $this->baseCurrency();
		}

		return $this->formatter->format( $amount, $currency, $withSymbol );
	}

	/**
	 * @param float|int|string $amount
	 */
	public function round( $amount, ?string $code = null ): float {
		$currency = null !== $code ? $this->repository->get( $code ) : $this->baseCurrency();

		if ( null === $currency ) {
			$currency = $this->baseCurrency();
		}

		return $this->formatter->round( $amount, $currency );
	}
}
