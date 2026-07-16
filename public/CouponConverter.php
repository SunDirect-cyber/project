<?php
declare( strict_types=1 );

namespace WCMCS\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts fixed-amount coupons (e.g. "$10 off") to the shopper's
 * active currency, while leaving percentage coupons (e.g. "10% off")
 * completely untouched — a percentage is already currency-agnostic, so
 * converting it would be wrong, not just unnecessary. The two coupon
 * types are distinguished automatically via WC_Coupon::get_discount_type().
 */
class CouponConverter {

	private const FIXED_TYPES = array( 'fixed_cart', 'fixed_product' );

	public static function register(): void {
		add_filter( 'woocommerce_coupon_get_amount', array( self::class, 'filter_amount' ), 10, 2 );
	}

	/**
	 * @param string|float $amount
	 */
	public static function filter_amount( $amount, $coupon ) {
		if ( '' === $amount || ! is_numeric( $amount ) || (float) $amount <= 0 || ! PriceConverter::isApplicable() ) {
			return $amount;
		}

		$type = $coupon instanceof \WC_Coupon ? $coupon->get_discount_type() : '';

		if ( ! in_array( $type, self::FIXED_TYPES, true ) ) {
			return $amount;
		}

		return PriceConverter::convert_amount( $amount );
	}
}
