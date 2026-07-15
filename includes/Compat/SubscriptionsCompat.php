<?php
namespace WCMCS\Compat;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Subscriptions integration.
 *
 * Recurring price conversion needs no dedicated code here: a
 * subscription product's recurring price is read through the same
 * get_price()/get_regular_price() getters every other product uses,
 * which PriceConverter already filters — see also the sign-up-fee
 * filter it registers specifically for
 * woocommerce_subscriptions_product_sign_up_fee, since that's a
 * separate meta field Subscriptions reads through its own accessor.
 *
 * Renewal handling is the part that genuinely needs its own logic: a
 * renewal order must always be created in the *subscription's own*
 * currency (locked in when the subscription was first purchased), never
 * whatever currency happens to be active for the current request — a
 * renewal is very often triggered by WP-Cron (no shopper browsing
 * session at all) or, for a manual "renew now"/retry-payment flow, by
 * whichever currency the *shopper's current visit* happens to have
 * selected, which has no relationship to the currency the subscription
 * was actually sold in. Silently creating a renewal order priced in
 * today's active currency instead of the subscription's own would be a
 * serious billing bug, not a display glitch.
 *
 * Exact hook timing for "before a renewal order is built" isn't
 * something this environment can verify against a live install of
 * Subscriptions, so rather than guess at a specific before-hook name,
 * this takes the more robust approach of checking the *result*: after
 * WC Subscriptions creates a renewal order, verify its currency matches
 * the parent subscription's own currency. wcs_renewal_order_created is
 * a long-standing, documented WC Subscriptions hook, so this part is on
 * firmer ground — worth confirming against a staging site running
 * Subscriptions before relying on it in production, per the same
 * caveat as this plugin's other best-effort third-party integrations.
 *
 * Deliberately does NOT try to silently fix a detected mismatch: by the
 * time the renewal order exists, its line-item totals were already
 * computed in whatever currency was active during creation — simply
 * relabeling the order's currency code without recalculating every
 * total would leave numerically wrong figures under a different label,
 * which is worse than the original problem. This logs loudly instead,
 * the same "detect and warn rather than guess" policy this plugin
 * applies everywhere real money is at stake (see also
 * BaseCurrencyChangeGuard, which refuses to auto-convert product prices
 * for the identical reason).
 */
class SubscriptionsCompat {

	public static function register(): void {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		add_action( 'wcs_renewal_order_created', array( self::class, 'check_renewal_currency_matches_subscription' ), 10, 2 );
	}

	/**
	 * @param \WC_Order          $renewalOrder
	 * @param \WC_Subscription   $subscription
	 */
	public static function check_renewal_currency_matches_subscription( $renewalOrder, $subscription ): void {
		if ( ! $renewalOrder instanceof \WC_Order || ! is_object( $subscription ) || ! method_exists( $subscription, 'get_currency' ) ) {
			return;
		}

		$subscriptionCurrency = $subscription->get_currency();
		$renewalCurrency      = $renewalOrder->get_currency();

		if ( '' === $subscriptionCurrency || $subscriptionCurrency === $renewalCurrency ) {
			return;
		}

		$container = Plugin::instance()->container();

		if ( $container->has( 'logger_service' ) ) {
			/** @var \WCMCS\Services\LoggerService $logger */
			$logger = $container->get( 'logger_service' );
			$logger->error(
				sprintf(
					'Renewal order #%d was created in %s but its subscription #%s is in %s — totals on this order need manual review, they were not auto-corrected.',
					$renewalOrder->get_id(),
					$renewalCurrency,
					method_exists( $subscription, 'get_id' ) ? (string) $subscription->get_id() : 'unknown',
					$subscriptionCurrency
				)
			);
		}

		/**
		 * Fires when a renewal order's currency doesn't match its
		 * subscription's currency — this plugin doesn't attempt to fix
		 * it automatically (see the class docblock), so a store that
		 * wants automated handling should hook this.
		 *
		 * @param \WC_Order        $renewalOrder
		 * @param \WC_Subscription $subscription
		 */
		do_action( 'wcmcs_renewal_currency_mismatch', $renewalOrder, $subscription );
	}
}
