<?php
declare( strict_types=1 );

namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Snapshots the exchange rate onto an order the moment it's placed, via
 * the OrderRepository built earlier for HPOS compatibility. The order's
 * own currency (order->get_currency()) is already correct by this point
 * — PriceConverter made 'woocommerce_currency' return the shopper's
 * active currency during checkout, so WooCommerce itself created the
 * order in that currency. What this adds is the rate that was in effect
 * and the base-currency equivalent, both permanently attached to the
 * order — needed so historical reporting/refunds stay accurate even
 * after today's live rate moves on.
 */
class OrderCurrencyRecorder {

	public static function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'record' ), 10, 1 );
	}

	public static function record( int $orderId ): void {
		$order = wc_get_order( $orderId );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$container = Plugin::instance()->container();

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );
		/** @var \WCMCS\Services\PriceConversionService $priceConversion */
		$priceConversion = $container->get( 'price_conversion_service' );
		/** @var \WCMCS\Services\OrderRepository $orderRepository */
		$orderRepository = $container->get( 'order_repository' );

		$base     = $currencyService->baseCurrency()->code();
		$currency = $order->get_currency();
		$total    = (float) $order->get_total();

		/**
		 * Fires immediately before this plugin locks in the order's
		 * currency/rate/base-total metadata at checkout — the last point
		 * an integration could still inspect the order in its pre-locked
		 * state.
		 *
		 * @param int    $orderId  The order being locked.
		 * @param string $currency The currency the order was placed in.
		 * @param string $base     The store's base currency code.
		 */
		do_action( 'wcmcs_before_checkout_currency_lock', $orderId, $currency, $base );

		if ( $currency === $base ) {
			$orderRepository->record_order_currency( $orderId, $currency, 1.0, $base, $total );

			do_action( 'wcmcs_after_checkout_currency_lock', $orderId, $currency, $base, 1.0 );

			return;
		}

		$rate = $priceConversion->getEffectiveRate( $base, $currency );

		if ( null === $rate || $rate <= 0 ) {
			// No usable rate to record — leave the order's own currency as
			// WooCommerce set it and skip the extra metadata rather than
			// storing a nonsensical rate/base-total.
			return;
		}

		// Line items were already converted (and, per-item, rounded) at
		// display time, so this is the order's own total translated back
		// through the same rate — an approximation of the base-currency
		// equivalent, which is what reporting needs, not a cent-exact
		// inverse of per-item rounding.
		$baseTotal = $total / $rate;

		$orderRepository->record_order_currency( $orderId, $currency, $rate, $base, $baseTotal );

		/**
		 * Fires after this plugin has locked in the order's
		 * currency/rate/base-total metadata at checkout.
		 *
		 * @param int    $orderId  The order that was just locked.
		 * @param string $currency The currency the order was placed in.
		 * @param string $base     The store's base currency code.
		 * @param float  $rate     The rate that was recorded on the order.
		 */
		do_action( 'wcmcs_after_checkout_currency_lock', $orderId, $currency, $base, $rate );
	}
}
