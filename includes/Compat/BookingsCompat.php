<?php
namespace WCMCS\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bookings integration: converts a booking's calculated
 * cost (base cost plus block/resource/person-type costs — the total
 * WC Bookings itself computed at "add to cart" time) into the shopper's
 * active currency.
 *
 * A booking's own base product price already goes through the normal
 * get_price() getters PriceConverter filters — that part needs no
 * special handling. What does is the *final calculated cost*: WC
 * Bookings works out block/resource/person pricing itself (a custom
 * calculation, not just reading the product's price) and stores the
 * result directly on the cart item rather than on the product object,
 * which means it bypasses every product-price filter entirely.
 *
 * Rather than guess at a Bookings-internal filter name for that
 * calculation (this environment has no live install of the extension
 * to verify one against), this hooks WooCommerce core's own
 * woocommerce_before_calculate_totals — a stable, well-documented hook
 * that fires for every cart item right before cart totals are computed,
 * regardless of which plugin added that item or how its price was
 * calculated. $cart_item['booking']['_cost'] is WC Bookings' own
 * documented cart-item data structure for the calculated cost; worth
 * confirming that key is still current on a staging site running
 * Bookings before relying on this in production, per this plugin's
 * usual caveat for best-effort third-party integrations.
 */
class BookingsCompat {

	public static function register(): void {
		if ( ! class_exists( 'WC_Product_Booking' ) ) {
			return;
		}

		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'convert_booking_costs' ), 20, 1 );
	}

	/**
	 * @param \WC_Cart $cart
	 */
	public static function convert_booking_costs( $cart ): void {
		if ( ! \WCMCS\Frontend\PriceConverter::isApplicable() || ! $cart instanceof \WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cartItem ) {
			if ( empty( $cartItem['booking'] ) || ! is_array( $cartItem['booking'] ) || empty( $cartItem['booking']['_cost'] ) ) {
				continue;
			}

			$rawCost = $cartItem['booking']['_cost'];

			if ( ! is_numeric( $rawCost ) || (float) $rawCost <= 0 ) {
				continue;
			}

			$converted = \WCMCS\Frontend\PriceConverter::convert_amount( (float) $rawCost );

			if ( ! is_numeric( $converted ) || ! isset( $cartItem['data'] ) || ! method_exists( $cartItem['data'], 'set_price' ) ) {
				continue;
			}

			$cartItem['data']->set_price( (float) $converted );
		}
	}
}
