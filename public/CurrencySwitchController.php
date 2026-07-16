<?php
declare( strict_types=1 );

namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;
use WCMCS\Services\SessionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-facing (not admin) AJAX endpoint the switcher widget/shortcode
 * /block's JS calls when a shopper picks a currency. Registered for both
 * logged-in and guest users, since currency switching has to work for
 * anonymous shoppers too.
 */
class CurrencySwitchController {

	public const ACTION = 'wcmcs_switch_currency';
	public const NONCE  = 'wcmcs_switch_currency_nonce';

	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		check_ajax_referer( self::ACTION, 'nonce' );

		$requested = isset( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : '';

		if ( '' === $requested || ! preg_match( '/^[A-Z]{3}$/', $requested ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid currency.', 'wc-multicurrency-switcher' ) ) );
		}

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );

		if ( ! in_array( $requested, $currencyService->enabledCurrencyCodes(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'That currency is not available.', 'wc-multicurrency-switcher' ) ) );
		}

		/** @var \WCMCS\Services\CurrencyPersistenceService $persistence */
		$persistence = Plugin::instance()->container()->get( 'currency_persistence_service' );
		// setCurrency() itself fires wcmcs_before_currency_switch /
		// wcmcs_currency_switched — see CurrencyPersistenceService.
		$persistence->setCurrency( $requested, SessionService::SOURCE_MANUAL );

		wp_send_json_success( array( 'currency' => $requested ) );
	}
}
