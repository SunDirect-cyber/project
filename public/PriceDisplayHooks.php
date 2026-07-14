<?php
namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hooks that make the switcher actually mean something: convert
 * every product price WooCommerce reads to the visitor's chosen
 * currency, and make WooCommerce's own currency setting (symbol,
 * decimals, and — critically — the currency new orders get created in)
 * follow that same choice.
 *
 * This is the single point of truth: because cart subtotals, checkout
 * totals, and new orders are all *calculated from* product prices at
 * runtime, converting the price here is what keeps currency consistent
 * everywhere downstream — shop, cart, checkout — rather than the
 * "converted on the shop page but reverts to base at checkout" bug the
 * spec calls out. Past orders are unaffected: WC_Order stores its own
 * currency at creation time and always formats itself with that, not
 * with whatever 'woocommerce_currency' returns on a later page view —
 * which is exactly the correct behavior for order history/emails.
 */
class PriceDisplayHooks {

	private static ?string $activeCurrencyCache = null;

	public static function register(): void {
		add_filter( 'woocommerce_currency', array( self::class, 'filter_currency' ) );
		add_filter( 'woocommerce_product_get_price', array( self::class, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( self::class, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( self::class, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( self::class, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( self::class, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( self::class, 'filter_price' ), 10, 2 );
	}

	public static function filter_currency( string $currency ): string {
		if ( ! self::shouldApply() ) {
			return $currency;
		}

		return self::activeCurrency() ?? $currency;
	}

	/**
	 * @param string|float $price
	 */
	public static function filter_price( $price, $product ): string {
		// Empty string means "no price"/"no sale price set" — never
		// convert that into 0 or any numeric value.
		if ( '' === $price || ! is_numeric( $price ) || ! self::shouldApply() ) {
			return $price;
		}

		$active = self::activeCurrency();
		$base   = self::baseCurrency();

		if ( null === $active || $active === $base ) {
			return $price;
		}

		/** @var \WCMCS\Services\PriceConversionService $priceConversion */
		$priceConversion = Plugin::instance()->container()->get( 'price_conversion_service' );
		$converted        = $priceConversion->convert( $price, $base, $active );

		return null === $converted ? $price : (string) $converted;
	}

	/**
	 * Deliberately conservative about *where* this applies: classic
	 * wp-admin screens (product edit, orders list, analytics) should
	 * keep showing base-currency prices regardless of what currency the
	 * logged-in admin's own browser session happens to have picked,
	 * since those are store-management views, not a shopping context.
	 * AJAX and REST requests (add-to-cart, cart/checkout updates,
	 * WooCommerce Blocks' Store API) are frontend shopping activity even
	 * though some of them route through admin-ajax.php, so those stay on.
	 */
	private static function shouldApply(): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		return true;
	}

	private static function activeCurrency(): ?string {
		if ( null !== self::$activeCurrencyCache ) {
			return self::$activeCurrencyCache;
		}

		/** @var \WCMCS\Services\CurrencyPersistenceService $persistence */
		$persistence = Plugin::instance()->container()->get( 'currency_persistence_service' );
		$currency    = $persistence->getCurrency();

		if ( null === $currency ) {
			return null;
		}

		$enabled = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );

		// A stale cookie/user-meta value for a currency the store no
		// longer offers should never be trusted.
		if ( ! empty( $enabled ) && ! in_array( $currency, $enabled, true ) ) {
			return null;
		}

		self::$activeCurrencyCache = $currency;

		return $currency;
	}

	private static function baseCurrency(): string {
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );

		return $currencyService->baseCurrency()->code();
	}
}
