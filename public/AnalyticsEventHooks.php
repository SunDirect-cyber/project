<?php
namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens for the 'wcmcs_currency_switched' action (fired by
 * CurrencySwitchController and UrlCurrencyOverride) and records it as a
 * behavioral event for the Geographic & Behavioral Insights report.
 * Also tags each new order with the session that placed it, which is
 * what lets the abandonment report later tell whether a session that
 * switched currency went on to actually buy anything.
 */
class AnalyticsEventHooks {

	public static function register(): void {
		add_action( 'wcmcs_currency_switched', array( self::class, 'on_switch' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_order_processed' ) );
	}

	public static function on_switch( string $to, string $source, ?string $from ): void {
		if ( null === $from ) {
			return;
		}

		/** @var \WCMCS\Services\Analytics\EventTracker $tracker */
		$tracker = Plugin::instance()->container()->get( 'event_tracker' );
		$tracker->recordSwitch( $from, $to );
	}

	public static function on_order_processed( int $orderId ): void {
		/** @var \WCMCS\Services\Analytics\EventTracker $tracker */
		$tracker = Plugin::instance()->container()->get( 'event_tracker' );
		$tracker->tagOrderWithSession( $orderId );
	}
}
