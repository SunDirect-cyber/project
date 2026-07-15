<?php
namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central price-conversion service. Everywhere WooCommerce renders a
 * price ultimately traces back to one of a small number of getters on
 * WC_Product/WC_Product_Variation (or, for currency symbol/decimals,
 * get_woocommerce_currency()) — converting the value at that single low
 * level is what gives every downstream display point correct, consistent
 * prices without needing a separate hook per screen:
 *
 *  - Product listing pages & single product page: read get_price() /
 *    get_regular_price() / get_sale_price() directly — converted here.
 *  - Variable products: each WC_Product_Variation goes through the
 *    _variation_ equivalents of the same filters, so per-variation
 *    prices (and the "$10 - $25" range WooCommerce builds from them)
 *    are each converted individually before the range is computed —
 *    never converting a single pre-computed range number.
 *  - Cart & mini-cart: WC_Cart calculates line totals and the cart
 *    subtotal/total from each item's (already-converted) product price
 *    at runtime — nothing extra needed.
 *  - Checkout & order review: WC_Checkout builds the order from the
 *    same WC_Cart, so it inherits already-converted totals; the
 *    'woocommerce_currency' filter below makes the new order get
 *    created with the correct currency code, which is what
 *    OrderCurrencyRecorder then permanently snapshots.
 *  - Order confirmation, My Account > Orders, invoices/packing slips,
 *    emails: all read from a *persisted* WC_Order, which always
 *    formats itself using its own stored currency (set at creation, see
 *    above) — never the live 'woocommerce_currency' filter. That's
 *    exactly correct: a past order must keep showing what the customer
 *    was actually charged, regardless of the currency a visitor
 *    browsing today has selected. This class intentionally does nothing
 *    extra for these — doing more would be the bug, not the fix.
 *  - WooCommerce Subscriptions: the recurring price is the product's
 *    regular price (already converted above); the sign-up fee is a
 *    separate meta field with its own filter, added below, guarded so
 *    it's a complete no-op unless Subscriptions is actually active.
 *  - REST API / Store API (cart & checkout blocks): these requests
 *    don't set is_admin() to true, so shouldApply() already allows
 *    them — product/cart responses are serialized from the same
 *    getters this class already converts.
 */
class PriceConverter {

	private static ?string $activeCurrencyCache = null;

	/** @var array<string, float|null> Resolved rate per "BASE_TARGET" pair, for this request only. */
	private static array $rateMemo = array();

	public static function register(): void {
		add_filter( 'woocommerce_currency', array( self::class, 'filter_currency' ) );
		add_filter( 'woocommerce_product_get_price', array( self::class, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( self::class, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( self::class, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( self::class, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( self::class, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( self::class, 'filter_price' ), 10, 2 );

		// WooCommerce Subscriptions stores the sign-up fee as its own meta
		// field, read through WC_Subscriptions_Product::get_sign_up_fee()
		// rather than any of the price getters above — it needs its own
		// filter, and only makes sense to register if Subscriptions is
		// actually installed.
		if ( class_exists( 'WC_Subscriptions_Product' ) ) {
			add_filter( 'woocommerce_subscriptions_product_sign_up_fee', array( self::class, 'filter_signup_fee' ), 10, 2 );
		}
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
		// Empty string means "no price"/"no sale price set", and 0 means
		// "free" — neither should ever be converted or run through
		// psychological rounding (charm rounding in particular would turn
		// a genuinely free 0 into something like -0.01).
		if ( '' === $price || ! is_numeric( $price ) || (float) $price <= 0 || ! self::shouldApply() ) {
			return $price;
		}

		return self::convertAndRound( (float) $price, $price );
	}

	/**
	 * @param float|string $fee
	 */
	public static function filter_signup_fee( $fee, $product ) {
		if ( '' === $fee || ! is_numeric( $fee ) || (float) $fee <= 0 || ! self::shouldApply() ) {
			return $fee;
		}

		return (float) self::convertAndRound( (float) $fee, $fee );
	}

	/**
	 * @param string|float $original Returned unchanged if conversion isn't applicable.
	 */
	private static function convertAndRound( float $amount, $original ) {
		$active = self::activeCurrency();
		$base   = self::baseCurrency();

		if ( null === $active || $active === $base ) {
			return $original;
		}

		$rate = self::effectiveRate( $base, $active );

		if ( null === $rate ) {
			return $original;
		}

		/** @var \WCMCS\Services\CurrencyRule\PricingRuleService $pricingRules */
		$pricingRules = Plugin::instance()->container()->get( 'pricing_rule_service' );

		return (string) $pricingRules->roundPrice( $active, $amount * $rate );
	}

	/**
	 * The effective (locked/marked-up) rate for a currency pair is
	 * resolved once per request and reused for every price on the page,
	 * instead of every single get_price()/get_regular_price()/
	 * get_sale_price() call across a shop loop of dozens of products each
	 * separately re-reading the rate cache — the same rate value, fetched
	 * once. Full batch/bulk query optimization for listing pages is a
	 * further step on top of this; this is the request-level floor under
	 * it.
	 */
	private static function effectiveRate( string $base, string $target ): ?float {
		$key = $base . '_' . $target;

		if ( array_key_exists( $key, self::$rateMemo ) ) {
			return self::$rateMemo[ $key ];
		}

		/** @var \WCMCS\Services\PriceConversionService $priceConversion */
		$priceConversion = Plugin::instance()->container()->get( 'price_conversion_service' );

		self::$rateMemo[ $key ] = $priceConversion->getEffectiveRate( $base, $target );

		return self::$rateMemo[ $key ];
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
