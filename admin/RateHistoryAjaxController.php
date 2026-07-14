<?php
namespace WCMCS\Admin;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backend for the admin rate history chart and the rollback action.
 * Both endpoints are read/act on a single currency pair at a time,
 * driven by the "Exchange Rates" admin page.
 */
class RateHistoryAjaxController {

	private const ACTION_HISTORY  = 'wcmcs_get_rate_history';
	private const ACTION_ROLLBACK = 'wcmcs_rollback_rate';
	public const NONCE            = 'wcmcs_rate_history_nonce';

	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION_HISTORY, array( self::class, 'handleHistory' ) );
		add_action( 'wp_ajax_' . self::ACTION_ROLLBACK, array( self::class, 'handleRollback' ) );
	}

	public static function handleHistory(): void {
		self::guard();

		$base   = self::sanitizeCode( $_GET['base'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$target = self::sanitizeCode( $_GET['target'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$limit  = min( 200, max( 1, (int) ( $_GET['limit'] ?? 30 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $base || '' === $target ) {
			wp_send_json_error( array( 'message' => __( 'A base and target currency are required.', 'wc-multicurrency-switcher' ) ) );
		}

		/** @var \WCMCS\Services\ExchangeRate\RateService $rateService */
		$rateService = Plugin::instance()->container()->get( 'rate_service' );

		wp_send_json_success(
			array(
				'base'    => $base,
				'target'  => $target,
				'history' => $rateService->getHistory( $base, $target, $limit ),
			)
		);
	}

	public static function handleRollback(): void {
		self::guard();

		$historyId = (int) ( $_POST['history_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $historyId <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid history entry.', 'wc-multicurrency-switcher' ) ) );
		}

		/** @var \WCMCS\Services\ExchangeRate\RateService $rateService */
		$rateService = Plugin::instance()->container()->get( 'rate_service' );
		$restored    = $rateService->rollbackTo( $historyId );

		if ( null === $restored ) {
			wp_send_json_error( array( 'message' => __( 'That history entry no longer exists.', 'wc-multicurrency-switcher' ) ) );
		}

		wp_send_json_success( array( 'rate' => $restored ) );
	}

	private static function guard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wc-multicurrency-switcher' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}

	private static function sanitizeCode( $value ): string {
		return strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) wp_unslash( $value ) ) );
	}
}
