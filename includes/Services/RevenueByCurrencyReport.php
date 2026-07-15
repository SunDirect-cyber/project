<?php
namespace WCMCS\Services;

use WCMCS\Core\Hpos;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which currencies are actually driving sales — queries paid orders
 * grouped by currency, aware of both HPOS (custom order tables) and
 * legacy post-based order storage, matching the dual support declared
 * back in WCMCS\Core\Hpos.
 */
class RevenueByCurrencyReport {

	private const PAID_STATUSES = array( 'wc-completed', 'wc-processing' );

	/**
	 * @return array<string, float> Currency code => summed order total, richest first.
	 */
	public function revenueByCurrency( int $days = 30 ): array {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days", current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$rows  = Hpos::is_enabled() ? $this->queryHpos( $since ) : $this->queryLegacy( $since );

		$totals = array();

		foreach ( $rows as $row ) {
			$currency = strtoupper( (string) $row['currency'] );

			if ( '' === $currency ) {
				continue;
			}

			$totals[ $currency ] = ( $totals[ $currency ] ?? 0.0 ) + (float) $row['total'];
		}

		arsort( $totals );

		return $totals;
	}

	/**
	 * @return array{currency: string, total: float}[]
	 */
	private function queryHpos( string $since ): array {
		global $wpdb;

		$statusPlaceholders = implode( ',', array_fill( 0, count( self::PAID_STATUSES ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT currency, SUM(total_amount) AS total FROM {$wpdb->prefix}wc_orders WHERE status IN ({$statusPlaceholders}) AND date_created_gmt >= %s GROUP BY currency", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( self::PAID_STATUSES, array( $since ) )
			),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ) => array( 'currency' => (string) $row['currency'], 'total' => (float) $row['total'] ),
			$rows ?: array()
		);
	}

	/**
	 * @return array{currency: string, total: float}[]
	 */
	private function queryLegacy( string $since ): array {
		global $wpdb;

		$statusPlaceholders = implode( ',', array_fill( 0, count( self::PAID_STATUSES ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm_currency.meta_value AS currency, SUM(pm_total.meta_value + 0) AS total
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_currency ON pm_currency.post_id = p.ID AND pm_currency.meta_key = '_order_currency'
				INNER JOIN {$wpdb->postmeta} pm_total ON pm_total.post_id = p.ID AND pm_total.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$statusPlaceholders}) AND p.post_date_gmt >= %s
				GROUP BY pm_currency.meta_value", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( self::PAID_STATUSES, array( $since ) )
			),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ) => array( 'currency' => (string) $row['currency'], 'total' => (float) $row['total'] ),
			$rows ?: array()
		);
	}
}
