<?php
declare( strict_types=1 );

namespace WCMCS\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin's order-level currency meta.
 *
 * Every method goes through WooCommerce's CRUD (wc_get_order(),
 * $order->get_meta()/update_meta_data()/save()) instead of get_post_meta()
 * /update_post_meta() or direct $wpdb queries. CRUD is what makes this
 * automatically HPOS-safe: WooCommerce itself decides whether that meta
 * ends up in the legacy postmeta table or the new orders-meta table, so
 * this class needs no HPOS-specific branching and works unchanged either
 * way.
 */
class OrderRepository {

	private const META_CURRENCY      = '_wcmcs_currency';
	private const META_EXCHANGE_RATE = '_wcmcs_exchange_rate';
	private const META_BASE_CURRENCY = '_wcmcs_base_currency';
	private const META_BASE_TOTAL    = '_wcmcs_base_currency_total';

	/**
	 * Records which currency an order was placed in, the exchange rate
	 * used at the time, and what the order total would have been in the
	 * store's base currency — kept on the order itself so it stays
	 * accurate historically even if exchange rates change later.
	 */
	public function record_order_currency( int $order_id, string $currency, float $exchange_rate, string $base_currency, float $base_currency_total ): bool {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		$order->update_meta_data( self::META_CURRENCY, $currency );
		$order->update_meta_data( self::META_EXCHANGE_RATE, $exchange_rate );
		$order->update_meta_data( self::META_BASE_CURRENCY, $base_currency );
		$order->update_meta_data( self::META_BASE_TOTAL, $base_currency_total );
		$order->save();

		return true;
	}

	/**
	 * @return array{currency: ?string, exchange_rate: ?float, base_currency: ?string, base_currency_total: ?float}
	 */
	public function get_order_currency_data( int $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return array(
				'currency'            => null,
				'exchange_rate'       => null,
				'base_currency'       => null,
				'base_currency_total' => null,
			);
		}

		$rate  = $order->get_meta( self::META_EXCHANGE_RATE );
		$total = $order->get_meta( self::META_BASE_TOTAL );

		return array(
			'currency'            => $order->get_meta( self::META_CURRENCY ) ?: null,
			'exchange_rate'       => '' === $rate ? null : (float) $rate,
			'base_currency'       => $order->get_meta( self::META_BASE_CURRENCY ) ?: null,
			'base_currency_total' => '' === $total ? null : (float) $total,
		);
	}

	/**
	 * Finds order IDs placed in a given currency. Works under both
	 * storage modes because wc_get_orders() itself abstracts the query —
	 * under HPOS it queries the orders-meta table, under legacy storage
	 * it queries postmeta, without this class needing to know which.
	 *
	 * @return int[]
	 */
	public function get_order_ids_by_currency( string $currency, int $limit = 20 ): array {
		return wc_get_orders(
			array(
				'limit'      => $limit,
				'return'     => 'ids',
				'meta_key'   => self::META_CURRENCY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $currency, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}
}
