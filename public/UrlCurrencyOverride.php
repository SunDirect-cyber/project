<?php
namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;
use WCMCS\Services\SessionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supports ?currency=EUR style links (marketing campaigns, affiliate
 * links) — applying and persisting the requested currency for the rest
 * of the visit, not just the one page the link pointed at.
 *
 * Runs on 'init', early enough that the currency is already switched
 * before WooCommerce/PriceConverter render anything on this same
 * request. The value is validated against a strict 3-letter pattern and
 * against the store's actually-enabled currencies before it's ever used
 * anywhere — an arbitrary query string is attacker-controlled input, so
 * it never gets treated as a trusted currency code just because it
 * looks like one.
 */
class UrlCurrencyOverride {

	public const PARAM = 'currency';

	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_apply' ) );
	}

	public static function maybe_apply(): void {
		if ( ! isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$requested = strtoupper( sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! preg_match( '/^[A-Z]{3}$/', $requested ) ) {
			return;
		}

		$enabled = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );

		if ( ! in_array( $requested, $enabled, true ) ) {
			return;
		}

		/** @var \WCMCS\Services\CurrencyPersistenceService $persistence */
		$persistence = Plugin::instance()->container()->get( 'currency_persistence_service' );

		if ( $persistence->getCurrency() === $requested ) {
			return;
		}

		$persistence->setCurrency( $requested, SessionService::SOURCE_URL );

		do_action( 'wcmcs_currency_switched', $requested, SessionService::SOURCE_URL );
	}
}
