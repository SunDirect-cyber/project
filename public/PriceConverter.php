<?php
declare( strict_types=1 );

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
 *
 * Performance: a shop/category page loop of dozens of products was the
 * actual N+1 risk here — not from any external API call (there isn't
 * one on this path), but from CurrencyRuleRepository re-querying the
 * database for the same currency's markup/rounding/lock rule on every
 * single price read. Two things close that off: the exchange rate is
 * resolved once per request (effectiveRate()'s memo, below) rather than
 * once per price, and CurrencyRuleRepository itself now memoizes its
 * own lookups per request too — so a 20-product shop page runs at most
 * one rate lookup and one rule lookup per rule type, total, not one of
 * each per product. convertAndRound() additionally caches the final
 * converted+rounded result *across* requests (cache_service, below),
 * so repeat visitors in the same currency reuse a previous computation
 * outright. There's deliberately no separate "batch convert this whole
 * product list in one query" step beyond that: once the rate and rules
 * are each resolved exactly once, converting each individual product's
 * price is pure in-memory arithmetic (amount * rate, then round) with
 * no further database or network access per product to batch away.
 */
class PriceConverter {

	private static ?string $activeCurrencyCache = null;

	/** @var array<string, float|null> Resolved rate per "BASE_TARGET" pair, for this request only. */
	private static array $rateMemo = array();

	/** Set once, the first time this request actually converts a price — read by the shutdown hook above. */
	private static bool $conversionServedThisRequest = false;

	public static function register(): void {
		add_action(
			'shutdown',
			static function () {
				if ( self::$conversionServedThisRequest ) {
					Plugin::instance()->container()->get( 'stats_service' )->recordConversionServed();
				}
			}
		);

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

		// Best-effort WooCommerce Composite Products support, same
		// honesty caveat as Bundles above — not verified against a live
		// install of the extension in this environment.
		if ( class_exists( 'WC_Product_Composite' ) ) {
			add_filter( 'woocommerce_composite_calculated_price', array( self::class, 'filter_price' ), 10, 2 );
		}

		// A no-op unless tax-then-convert mode is on (see
		// TAX_MODE_TAX_THEN_CONVERT below) — registered unconditionally
		// so switching the setting takes effect without a page reload of
		// hook registration.
		add_filter( 'woocommerce_get_price_html', array( self::class, 'append_display_equivalent' ), 10, 2 );

		// WooCommerce's decimal count is a single sitewide option
		// ('woocommerce_price_num_decimals', normally 2) — without this
		// filter, switching currency via 'woocommerce_currency' above
		// would still format a zero-decimal currency like JPY as
		// "¥100.00" and a three-decimal one like BHD as "د.ب10.500"
		// instead of "د.ب10.500" — i.e. this is the fix for exactly the
		// "must not show ¥100.00" edge case, not a cosmetic nicety.
		add_filter( 'wc_get_price_decimals', array( self::class, 'filter_price_decimals' ) );
	}

	public static function filter_price_decimals( int $decimals ): int {
		if ( ! self::shouldApply() ) {
			return $decimals;
		}

		$active = self::activeCurrency();

		if ( null === $active || $active === self::baseCurrency() ) {
			return $decimals;
		}

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );
		$currency        = $currencyService->get( $active );

		return null !== $currency ? $currency->decimals() : $decimals;
	}

	public const TAX_MODE_CONVERT_THEN_TAX = 'convert_then_tax';
	public const TAX_MODE_TAX_THEN_CONVERT = 'tax_then_convert';

	/**
	 * Two fundamentally different, both legitimate, ways a store might
	 * need this to work — which one is correct is a business/accounting
	 * decision for the store owner, not something this plugin should
	 * assume:
	 *
	 *  - "convert_then_tax" (default): the product price is converted
	 *    first, and WooCommerce's own tax engine then calculates tax on
	 *    that already-converted number — exactly what every filter in
	 *    this class already does, since they all intercept the raw
	 *    price before WooCommerce ever calculates a total from it. The
	 *    order is created and charged in the shopper's chosen currency.
	 *  - "tax_then_convert": WooCommerce calculates everything —
	 *    including tax — entirely in the base currency, completely
	 *    untouched (every conversion filter in this class becomes a
	 *    no-op via shouldApply() below), so the actual order and charge
	 *    stay in base currency with tax computed on real base-currency
	 *    amounts. The shopper still sees their chosen currency, but only
	 *    as an informational "(≈ X)" equivalent appended to the product
	 *    price (append_display_equivalent() below) — deliberately
	 *    display-only, since re-deriving WooCommerce's own tax
	 *    calculation externally (multiple tax classes, compound tax,
	 *    coupon interactions) to convert a *post-tax* total correctly
	 *    would risk quietly computing the wrong tax, which is a far
	 *    worse failure than a slightly different rounding order.
	 */
	public static function taxConversionMode(): string {
		$mode = (string) get_option( 'wcmcs_tax_conversion_mode', self::TAX_MODE_CONVERT_THEN_TAX );

		return self::TAX_MODE_TAX_THEN_CONVERT === $mode ? self::TAX_MODE_TAX_THEN_CONVERT : self::TAX_MODE_CONVERT_THEN_TAX;
	}

	/**
	 * Appends a converted-equivalent hint to a product's displayed price
	 * — the only thing that happens in tax-then-convert mode, since
	 * every other filter in this class is disabled in that mode via
	 * shouldApply().
	 */
	public static function append_display_equivalent( string $html, $product ): string {
		if ( self::TAX_MODE_TAX_THEN_CONVERT !== self::taxConversionMode() ) {
			return $html;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html;
		}

		$active = self::activeCurrency();
		$base   = self::baseCurrency();

		if ( null === $active || $active === $base || ! is_object( $product ) || ! method_exists( $product, 'get_price' ) ) {
			return $html;
		}

		$price = $product->get_price();

		if ( '' === $price || ! is_numeric( $price ) || (float) $price <= 0 ) {
			return $html;
		}

		$converted = self::convertAndRound( (float) $price, null, $active );

		if ( null === $converted ) {
			return $html;
		}

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );

		return $html . ' <span class="wcmcs-price-equivalent">(&asymp; ' . esc_html( $currencyService->format( $converted, $active ) ) . ')</span>';
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

		/**
		 * Fires before a single price is converted — every product price,
		 * shipping cost, coupon amount, or other value this plugin
		 * converts passes through here, including values later served
		 * from the per-request/cross-request cache below.
		 *
		 * @param float  $amount The raw (base-currency) amount about to be converted.
		 * @param string $base   Base currency code.
		 * @param string $active Target (active) currency code.
		 * @param float  $rate   The effective (locked/marked-up) rate about to be applied.
		 */
		do_action( 'wcmcs_before_price_conversion', $amount, $base, $active, $rate );

		/**
		 * Filters the exchange rate immediately before it's applied to
		 * this specific price — distinct from wcmcs_rate_before_apply
		 * (RateService), which affects the rate as it's fetched and
		 * stored to history. This filter only affects this one
		 * conversion's arithmetic, not what gets recorded as history.
		 *
		 * @param float  $rate   The effective rate about to be applied.
		 * @param string $base   Base currency code.
		 * @param string $active Target (active) currency code.
		 * @param float  $amount The raw (base-currency) amount being converted.
		 */
		$rate = (float) apply_filters( 'wcmcs_conversion_rate', $rate, $base, $active, $amount );

		self::$conversionServedThisRequest = true;

		// Cross-request cache: conversion is a pure function of (amount,
		// currency, rate, rounding config) — every one of those is stable
		// for the TTL below, so two different shoppers viewing the same
		// price in the same currency reuse one cached result instead of
		// each separately hitting the rounding-rule lookup and redoing
		// the arithmetic. Keyed by the raw amount rather than a product
		// ID: two products that happen to share a price correctly share
		// a cache entry too, since the result only ever depends on the
		// number itself.
		/** @var \WCMCS\Services\CacheService $cache */
		$cache    = Plugin::instance()->container()->get( 'cache_service' );
		$cacheKey = 'conv_' . $active . '_' . md5( (string) $amount );

		$result = $cache->remember(
			$cacheKey,
			self::cacheTtl(),
			static function () use ( $amount, $rate, $active ) {
				/** @var \WCMCS\Services\CurrencyRule\PricingRuleService $pricingRules */
				$pricingRules = Plugin::instance()->container()->get( 'pricing_rule_service' );
				/** @var \WCMCS\Services\CurrencyService $currencyService */
				$currencyService = Plugin::instance()->container()->get( 'currency_service' );

				$rounded = $pricingRules->roundPrice( $active, $amount * $rate );

				// The psychological-pricing rule above (nearest/charm/none)
				// operates on the raw converted number and doesn't know this
				// currency's actual precision — MODE_NONE in particular
				// returns the raw float untouched. Rounding to the
				// currency's real decimal count here, as the final step, is
				// what stops a zero-decimal currency like JPY from carrying
				// invisible fractional yen into cart/order math (which would
				// otherwise silently drift from what the rounded display
				// price shows), and stops a three-decimal currency like BHD
				// from losing its third decimal to a naive two-decimal
				// assumption elsewhere.
				$targetCurrency = $currencyService->get( $active );
				$decimals       = null !== $targetCurrency ? $targetCurrency->decimals() : 2;

				return (string) round( $rounded, $decimals );
			}
		);

		/**
		 * Filters the final converted, rounded price — fires on every
		 * call, including one served from cache, so this is the reliable
		 * place to adjust what a shopper actually sees regardless of
		 * whether this particular request happened to compute it fresh.
		 *
		 * @param string $result The converted price as a numeric string.
		 * @param float  $amount The original (base-currency) amount.
		 * @param string $active Target (active) currency code.
		 * @param float  $rate   The effective rate that was applied.
		 */
		$result = (string) apply_filters( 'wcmcs_rounded_price', $result, $amount, $active, $rate );

		/**
		 * Fires after a price has been converted (and possibly served
		 * from cache) — the counterpart to wcmcs_before_price_conversion.
		 *
		 * @param string $result The final converted price as a numeric string.
		 * @param float  $amount The original (base-currency) amount.
		 * @param string $base   Base currency code.
		 * @param string $active Target (active) currency code.
		 */
		do_action( 'wcmcs_after_price_conversion', $result, $amount, $base, $active );

		return $result;
	}

	/**
	 * Tied to the configured rate-refresh interval, same reasoning as
	 * RateService's own cache TTL: a cached converted price shouldn't
	 * outlive the next scheduled rate refresh, since the rate it was
	 * computed from could be stale by then.
	 */
	private static function cacheTtl(): int {
		return \WCMCS\Core\Cron::intervalToSeconds( \WCMCS\Core\Cron::currentIntervalSlug() );
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
	/**
	 * Public so related converters (ShippingCostConverter,
	 * CouponConverter) can gate on the exact same rule instead of each
	 * re-implementing it slightly differently.
	 */
	public static function isApplicable(): bool {
		return self::shouldApply();
	}

	private static function shouldApply(): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		// A multi-vendor plugin's front-end vendor dashboard is a
		// store-management view exactly like wp-admin's product editor —
		// it just happens to run outside wp-admin. Converting prices
		// there risks a vendor saving a converted number back as their
		// product's real base-currency price. See VendorDashboardDetector.
		if ( \WCMCS\Compat\VendorDashboardDetector::isVendorDashboard() ) {
			return false;
		}

		// In tax-then-convert mode, every conversion filter that touches
		// an actual calculation (price, currency, variation ranges,
		// sign-up fees, shipping, coupons) is switched off — only
		// append_display_equivalent() is still active, adding an
		// informational equivalent without changing what WooCommerce
		// actually calculates or charges.
		if ( self::TAX_MODE_TAX_THEN_CONVERT === self::taxConversionMode() ) {
			return false;
		}

		return true;
	}

	private static bool $forceBaseCurrency = false;

	/**
	 * Used by GatewayCurrencyGuard: when the shopper's chosen payment
	 * gateway doesn't support their active currency, this forces every
	 * price/currency filter in this class (and, since they gate through
	 * isApplicable()/activeCurrency() too, ShippingCostConverter and
	 * CouponConverter) to behave exactly as if the store's base currency
	 * were active — for this request only. Nothing persists; the
	 * shopper's actual currency selection is untouched for their next
	 * page view.
	 */
	public static function forceBaseCurrency( bool $force = true ): void {
		self::$forceBaseCurrency   = $force;
		self::$activeCurrencyCache = null;
	}

	private static function activeCurrency(): ?string {
		if ( self::$forceBaseCurrency ) {
			return null;
		}

		if ( null !== self::$activeCurrencyCache ) {
			return self::$activeCurrencyCache;
		}

		/** @var \WCMCS\Services\CurrencyPersistenceService $persistence */
		$persistence = Plugin::instance()->container()->get( 'currency_persistence_service' );
		$currency    = $persistence->getCurrency();

		if ( null === $currency ) {
			return null;
		}

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );
		$enabled         = $currencyService->enabledCurrencyCodes();

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
