<?php
declare( strict_types=1 );

namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;
use WCMCS\Services\Gateway\GatewayCurrencyMatrix;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The safety feature the spec calls "critical": if the payment gateway
 * a shopper is about to pay with doesn't support their selected display
 * currency, silently attempting the charge anyway risks the processor
 * rejecting it outright — this instead falls back to charging in the
 * store's base currency, with a clear on-screen notice, rather than
 * letting that failure reach checkout submission.
 *
 * Two points where this has to take effect, both driving
 * PriceConverter::forceBaseCurrency() for that one request only:
 *  - woocommerce_checkout_update_order_review: fires on the AJAX
 *    request WooCommerce's own checkout.js sends whenever the shopper
 *    changes payment method (as well as shipping/coupon/address), so
 *    the recalculated review totals already reflect base currency
 *    before they submit.
 *  - woocommerce_checkout_process: fires on the actual form submission,
 *    before the order is built from the cart — the same check runs
 *    again here so the order that actually gets created and charged is
 *    consistent with whatever was last shown, even if update_checkout
 *    didn't fire for some reason (e.g. JS disabled).
 */
class GatewayCurrencyGuard {

	public static function register(): void {
		add_action( 'woocommerce_checkout_update_order_review', array( self::class, 'maybe_force_base_currency' ) );
		add_action( 'woocommerce_checkout_process', array( self::class, 'maybe_force_base_currency' ) );
		add_action( 'woocommerce_review_order_before_payment', array( self::class, 'render_notice' ) );
	}

	public static function maybe_force_base_currency(): void {
		if ( null !== self::mismatch() ) {
			PriceConverter::forceBaseCurrency( true );
		}
	}

	public static function render_notice(): void {
		$mismatch = self::mismatch();

		if ( null === $mismatch ) {
			return;
		}

		printf(
			'<div class="woocommerce-info wcmcs-gateway-currency-notice">%s</div>',
			esc_html(
				sprintf(
					/* translators: 1: gateway title, 2: selected currency, 3: base currency */
					__( '%1$s doesn\'t support charging in %2$s. You will be charged in %3$s instead, for the equivalent amount.', 'wc-multicurrency-switcher' ),
					$mismatch['gateway_title'],
					$mismatch['currency'],
					$mismatch['base']
				)
			)
		);
	}

	/**
	 * @return array{gateway_id: string, gateway_title: string, currency: string, base: string}|null
	 */
	private static function mismatch(): ?array {
		$container = Plugin::instance()->container();

		/** @var \WCMCS\Services\CurrencyPersistenceService $persistence */
		$persistence = $container->get( 'currency_persistence_service' );
		$currency    = $persistence->getCurrency();

		if ( null === $currency ) {
			return null;
		}

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );
		$base            = $currencyService->baseCurrency()->code();

		if ( $currency === $base ) {
			return null;
		}

		$gateway = self::currentGateway();

		if ( null === $gateway ) {
			return null;
		}

		if ( GatewayCurrencyMatrix::supports( $gateway->id, $currency ) ) {
			return null;
		}

		return array(
			'gateway_id'    => $gateway->id,
			'gateway_title' => $gateway->get_title(),
			'currency'      => $currency,
			'base'          => $base,
		);
	}

	private static function currentGateway(): ?\WC_Payment_Gateway {
		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return null;
		}

		$available = WC()->payment_gateways()->get_available_payment_gateways();

		if ( empty( $available ) ) {
			return null;
		}

		$chosen = isset( $_POST['payment_method'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: ( WC()->session instanceof \WC_Session ? WC()->session->get( 'chosen_payment_method' ) : null );

		if ( $chosen && isset( $available[ $chosen ] ) ) {
			return $available[ $chosen ];
		}

		// No explicit choice yet (first page load, nothing posted) — the
		// first available gateway is what WooCommerce's own checkout
		// template pre-selects by default.
		return reset( $available ) ?: null;
	}
}
