<?php
declare( strict_types=1 );

namespace WCMCS\Services\Analytics;

use WCMCS\Services\CacheService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records the raw behavioral events the Geographic & Behavioral
 * Insights report is built from: a visitor's country being detected,
 * and a currency switch happening. Both are tied to a session
 * identifier (WooCommerce's own customer session ID — already exactly
 * "one stable ID per shopper, whether logged in or a guest", no need to
 * invent a separate tracking mechanism) so a later report can connect
 * "this session switched currency" to "this session did/didn't place
 * an order" for abandonment tracking.
 */
class EventTracker {

	private CacheService $cache;

	public function __construct( CacheService $cache ) {
		$this->cache = $cache;
	}

	public function currentSessionId(): ?string {
		if ( ! function_exists( 'WC' ) || ! ( WC()->session instanceof \WC_Session ) ) {
			return null;
		}

		$id = WC()->session->get_customer_id();

		return $id ? (string) $id : null;
	}

	/**
	 * One row per switch — every explicit currency change, whether from
	 * the switcher widget, a confirmed geo-suggestion, or a ?currency=
	 * link (see the 'wcmcs_currency_switched' action fired from those).
	 */
	public function recordSwitch( string $from, string $to ): void {
		if ( strtoupper( $from ) === strtoupper( $to ) ) {
			return;
		}

		$this->insert(
			'switch',
			array(
				'from_currency' => strtoupper( $from ),
				'to_currency'   => strtoupper( $to ),
			)
		);
	}

	/**
	 * One row per *new* visitor per day per country — deduped via a
	 * short-lived cache flag rather than a database lookup, so this
	 * stays cheap to call from the auto-detection path that already
	 * runs on every first-time visitor's page load.
	 */
	public function recordCountryVisit( string $country, ?string $resolvedCurrency ): void {
		$sessionId = $this->currentSessionId();
		$dedupeKey = 'geo_visit_logged_' . ( $sessionId ?? 'anon' ) . '_' . gmdate( 'Y-m-d' );

		if ( false !== $this->cache->get( $dedupeKey ) ) {
			return;
		}

		$this->cache->set( $dedupeKey, 1, DAY_IN_SECONDS );

		$this->insert(
			'country_visit',
			array(
				'country'     => strtoupper( $country ),
				'to_currency' => $resolvedCurrency ? strtoupper( $resolvedCurrency ) : null,
			)
		);
	}

	/**
	 * Tags an order with the session that placed it — the link
	 * GeographicInsightsReport's abandonment query uses to tell whether
	 * a session that switched currency went on to actually buy anything.
	 */
	public function tagOrderWithSession( int $orderId ): void {
		$sessionId = $this->currentSessionId();

		if ( null === $sessionId ) {
			return;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $orderId ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$order->update_meta_data( '_wcmcs_session_id', $sessionId );
		$order->save();
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function insert( string $eventType, array $fields ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wcmcs_currency_events',
			array_merge(
				array(
					'event_type' => $eventType,
					'session_id' => $this->currentSessionId(),
					'created_at' => current_time( 'mysql' ),
				),
				$fields
			)
		);
	}
}
