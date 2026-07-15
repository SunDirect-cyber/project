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
 *    _variation_ equivalents of the same filters when its own price is
 *    read directly. The "$10 - $25" range shown on the parent product
 *    is a *different* code path, though — WooCommerce computes it via
 *    WC_Product_Variable::get_variation_prices(), which reads raw
 *    price meta straight from the database (for performance) into a
 *    transient, bypassing the per-variation getters entirely. That
 *    needs its own filter (woocommerce_variation_prices, below) to
 *    convert the range correctly — and WooCommerce's own cache key for
 *    that transient needs the active currency added to it
 *    (woocommerce_get_variation_prices_hash, below), otherwise the
 *    first currency to view a variable product would get its range
 *    cached and served to every other currency afterward.
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
 *  - Grouped products: WC_Product_Grouped has no price of its own — its
 *    displayed range is built by looping its child products and calling
 *    each child's own get_price()/get_price_html(), which already goes
 *    through the standard filters above. Nothing extra needed.
 *  - WooCommerce Product Bundles: this extension isn't installed in
 *    this environment, so the hook below (guarded to be a no-op unless
 *    the extension is active) is a best-effort integration against its
 *    publicly documented filter, not something verified end-to-end
 *    against the real plugin — worth confirming on a store that has it
 *    installed. A bundle's own base price (in its "static pricing"
 *    mode) is stored as ordinary product price meta and already goes
 *    through the standard filters with no extra code.
 *  - Third-party quantity-discount/tiered-pricing plugins: there's no
 *    single hook that covers all of them generically — some read
 *    get_price() live (and so already see a converted price, with
 *    their percentage/tier logic applying correctly on top of it), but
 *    others store their own *absolute* tier prices (e.g. "buy 10 for
 *    $90 flat") in separate meta this class has no way to know about.
 *    convert_amount() below is the public extension point: a specific
 *    plugin integration (or a store owner's own snippet) can call it,
 *    or apply the 'wcmcs_convert_price' filter, to convert an arbitrary
 *    number using the exact same rate + rounding rules as everything
 *    else here, rather than each integration reinventing conversion.
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

		// Variable product price ranges bypass the per-variation getters
		// above entirely (see the class docblock) — these two cover that
		// separate code path.
		add_filter( 'woocommerce_variation_prices', array( self::class, 'filter_variation_prices' ), 10, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( self::class, 'add_currency_to_variation_prices_hash' ), 10, 3 );

		add_filter( 'wcmcs_convert_price', array( self::class, 'filter_convert_price' ), 10, 2 );

		// WooCommerce Subscriptions stores the sign-up fee as its own meta
		// field, read through WC_Subscriptions_Product::get_sign_up_fee()
		// rather than any of the price getters above — it needs its own
		// filter, and only makes sense to register if Subscriptions is
		// actually installed.
		if ( class_exists( 'WC_Subscriptions_Product' ) ) {
			add_filter( 'woocommerce_subscriptions_product_sign_up_fee', array( self::class, 'filter_signup_fee' ), 10, 2 );
		}

		// Best-effort WooCommerce Product Bundles support — see the class
		// docblock for the honesty caveat on this one.
		if ( class_exists( 'WC_Product_Bundle' ) ) {
			add_filter( 'woocommerce_bundle_calculated_price', array( self::class, 'filter_price' ), 10, 2 );
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
	 * @param array{price?: array<int,mixed>, regular_price?: array<int,mixed>, sale_price?: array<int,mixed>} $prices
	 */
	public static function filter_variation_prices( $prices, $product, $for_display ) {
		if ( ! is_array( $prices ) || ! self::shouldApply() ) {
			return $prices;
		}

		foreach ( array( 'price', 'regular_price', 'sale_price' ) as $key ) {
			if ( empty( $prices[ $key ] ) || ! is_array( $prices[ $key ] ) ) {
				continue;
			}

			foreach ( $prices[ $key ] as $variationId => $amount ) {
				if ( '' === $amount || ! is_numeric( $amount ) || (float) $amount <= 0 ) {
					continue;
				}

				$prices[ $key ][ $variationId ] = self::convertAndRound( (float) $amount, $amount );
			}
		}

		return $prices;
	}

	/**
	 * @param string[] $hash
	 * @return string[]
	 */
	public static function add_currency_to_variation_prices_hash( $hash, $product, $for_display ) {
		if ( ! is_array( $hash ) ) {
			return $hash;
		}

		$hash[] = self::activeCurrency() ?? self::baseCurrency();

		return $hash;
	}

	/**
	 * Public extension point for code this class has no way to hook into
	 * directly — a specific third-party pricing plugin integration, or a
	 * store owner's own snippet — that needs to convert a raw number
	 * using the exact same rate and rounding rules as everything else.
	 *
	 * @param float|int|string $amount
	 * @return float|int|string
	 */
	public static function convert_amount( $amount, ?string $targetCurrency = null ) {
		if ( '' === $amount || ! is_numeric( $amount ) || (float) $amount <= 0 || ! self::shouldApply() ) {
			return $amount;
		}

		return self::convertAndRound( (float) $amount, $amount, $targetCurrency );
	}

	/**
	 * @param float|int|string $amount
	 * @return float|int|string
	 */
	public static function filter_convert_price( $amount, ?string $targetCurrency = null ) {
		return self::convert_amount( $amount, $targetCurrency );
	}

	/**
	 * @param string|float $original Returned unchanged if conversion isn't applicable.
	 */
	private static function convertAndRound( float $amount, $original, ?string $targetCurrency = null ) {
		$active = $targetCurrency ?? self::activeCurrency();
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
