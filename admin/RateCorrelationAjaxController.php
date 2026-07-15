<?php
namespace WCMCS\Admin;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backend for the Rate Correlation screen's currency/date-range picker.
 */
class RateCorrelationAjaxController {

	public const NONCE = 'wcmcs_rate_correlation_nonce';

	public static function register(): void {
		add_action( 'wp_ajax_wcmcs_get_rate_correlation', array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wc-multicurrency-switcher' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		$currency = isset( $_GET['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['currency'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from     = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to       = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'wc-multicurrency-switcher' ) ) );
		}

		/** @var \WCMCS\Services\Analytics\RateFluctuationCorrelationReport $report */
		$report = Plugin::instance()->container()->get( 'rate_correlation_report' );

		wp_send_json_success( array( 'series' => $report->generate( $currency, $from, $to ) ) );
	}
}
