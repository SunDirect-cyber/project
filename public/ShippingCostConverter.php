<?php
namespace WCMCS\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts shipping costs — configured by the store owner in the base
 * currency — to the shopper's active currency. Two separate mechanisms,
 * chosen deliberately for how safe each is to implement without a live
 * WooCommerce install with real shipping zones to test against:
 *
 *  - Rate costs (flat rate, table rate, or any other shipping method):
 *    converted via 'woocommerce_package_rates', which fires *after*
 *    every shipping method has already calculated its final numeric
 *    cost. This sidesteps entirely having to understand how each method
 *    got that number (flat rate "cost" can itself be a formula string
 *    like "10 + ( 2 * [qty] )" — converting the *result* avoids ever
 *    needing to parse or re-evaluate that formula).
 *  - Free-shipping minimum order amount: converted at the settings-read
 *    level (WordPress's own generic option_{name} filter on each
 *    free-shipping method instance's stored settings), which changes
 *    only the configured threshold *number* — not WC_Shipping_Free_
 *    Shipping's own eligibility logic (coupon interaction, tax
 *    inclusion, etc.), which stays exactly as WooCommerce implements it.
 *    Deliberately not attempting to override the eligibility decision
 *    itself, since getting subtle interactions like "requires: either
 *    a minimum amount or a free-shipping coupon" wrong would be a worse
 *    bug than leaving it alone.
 */
class ShippingCostConverter {

	public static function register(): void {
		add_filter( 'woocommerce_package_rates', array( self::class, 'convert_rate_costs' ), 10, 2 );
		add_action( 'woocommerce_shipping_init', array( self::class, 'hook_free_shipping_thresholds' ) );
	}

	/**
	 * @param \WC_Shipping_Rate[] $rates
	 */
	public static function convert_rate_costs( $rates, $package ) {
		if ( ! is_array( $rates ) || ! PriceConverter::isApplicable() ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate ) {
				continue;
			}

			$cost = $rate->get_cost();

			if ( is_numeric( $cost ) && (float) $cost > 0 ) {
				$rate->set_cost( (float) PriceConverter::convert_amount( $cost ) );
			}

			$taxes = $rate->get_taxes();

			if ( is_array( $taxes ) ) {
				foreach ( $taxes as $taxId => $taxAmount ) {
					if ( is_numeric( $taxAmount ) && (float) $taxAmount > 0 ) {
						$taxes[ $taxId ] = PriceConverter::convert_amount( $taxAmount );
					}
				}
				$rate->set_taxes( $taxes );
			}
		}

		return $rates;
	}

	/**
	 * Finds every free-shipping method instance across all shipping
	 * zones (including "Locations not covered by your other zones") and
	 * filters its stored settings option so min_amount reads back
	 * already converted — run once per request, early, well before any
	 * shipping calculation happens.
	 */
	public static function hook_free_shipping_thresholds(): void {
		if ( ! class_exists( \WC_Shipping_Zones::class ) ) {
			return;
		}

		$zones   = \WC_Shipping_Zones::get_zones();
		$zoneIds = array_merge( array( 0 ), wp_list_pluck( $zones, 'id' ) );

		foreach ( $zoneIds as $zoneId ) {
			$zone = new \WC_Shipping_Zone( $zoneId );

			foreach ( $zone->get_shipping_methods() as $method ) {
				if ( ! $method instanceof \WC_Shipping_Method || 'free_shipping' !== $method->id ) {
					continue;
				}

				$optionName = $method->get_instance_option_key();

				add_filter(
					'option_' . $optionName,
					array( self::class, 'convert_free_shipping_settings' )
				);
			}
		}
	}

	/**
	 * @param mixed $settings
	 * @return mixed
	 */
	public static function convert_free_shipping_settings( $settings ) {
		if ( ! is_array( $settings ) || empty( $settings['min_amount'] ) || ! PriceConverter::isApplicable() ) {
			return $settings;
		}

		if ( ! is_numeric( $settings['min_amount'] ) || (float) $settings['min_amount'] <= 0 ) {
			return $settings;
		}

		$settings['min_amount'] = (string) PriceConverter::convert_amount( $settings['min_amount'] );

		return $settings;
	}
}
