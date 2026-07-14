<?php
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

		$enabled = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );

		if ( ! in_array( $requested, $enabled, true ) ) {
			wp_send_json_error( array( 'message' => __( 'That currency is not available.', 'wc-multicurrency-switcher' ) ) );
		}

		/** @var SessionService $session */
		$session = Plugin::instance()->container()->get( 'session_service' );
		$session->setCurrency( $requested, SessionService::SOURCE_MANUAL );

		do_action( 'wcmcs_currency_switched', $requested, SessionService::SOURCE_MANUAL );

		wp_send_json_success( array( 'currency' => $requested ) );
	}
}
