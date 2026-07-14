<?php
namespace WCMCS\Admin;

use WCMCS\Core\Plugin;
use WCMCS\Core\RateFailureMonitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backend for the admin "Refresh Rates Now" button: runs the same
 * refresh the cron job runs, on demand, and returns the outcome as JSON
 * so the admin screen can show live feedback per currency instead of a
 * blind "done" message.
 */
class RateAjaxController {

	private const ACTION = 'wcmcs_refresh_rates';
	private const NONCE  = 'wcmcs_refresh_rates_nonce';

	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wc-multicurrency-switcher' ) ), 403 );
		}

		check_ajax_referer( self::ACTION, self::NONCE );

		$container = Plugin::instance()->container();

		/** @var \WCMCS\Services\ExchangeRate\RateService $rateService */
		$rateService = $container->get( 'rate_service' );
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );
		/** @var RateFailureMonitor $monitor */
		$monitor = $container->get( 'rate_failure_monitor' );

		$base    = $currencyService->baseCurrency()->code();
		$targets = (array) get_option( 'wcmcs_enabled_currencies', array() );

		if ( empty( $targets ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No currencies are enabled yet — add currencies in the settings before refreshing rates.', 'wc-multicurrency-switcher' ) )
			);
		}

		$results = $rateService->refreshAll( $base, $targets );
		$monitor->recordRefreshResult( $results );

		$failed = array_filter( $results, static fn ( $r ) => null === $r['rate'] );

		wp_send_json_success(
			array(
				'base'         => $base,
				'results'      => $results,
				'failed_count' => count( $failed ),
				'refreshed_at' => current_time( 'mysql' ),
			)
		);
	}

	public static function nonceAction(): string {
		return self::ACTION;
	}

	public static function nonceName(): string {
		return self::NONCE;
	}
}
