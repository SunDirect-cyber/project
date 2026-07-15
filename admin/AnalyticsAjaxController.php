<?php
namespace WCMCS\Admin;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backend for the Analytics screen's date-range filtering — a single
 * endpoint that returns totals, per-currency breakdown, and the daily
 * trend series for a range, optionally alongside the same range one
 * year earlier for year-over-year comparison. Everything here reads
 * CurrencyStatsRepository (the pre-aggregated table), never live orders.
 */
class AnalyticsAjaxController {

	public const NONCE = 'wcmcs_analytics_nonce';

	public static function register(): void {
		add_action( 'wp_ajax_wcmcs_get_analytics', array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wc-multicurrency-switcher' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		$from = self::sanitizeDate( $_GET['from'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to   = self::sanitizeDate( $_GET['to'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( null === $from || null === $to || $from > $to ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date range.', 'wc-multicurrency-switcher' ) ) );
		}

		$compareYoy = ! empty( $_GET['compare_yoy'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		/** @var \WCMCS\Services\Analytics\CurrencyStatsRepository $repository */
		$repository = Plugin::instance()->container()->get( 'stats_repository' );

		$response = array(
			'from'    => $from,
			'to'      => $to,
			'totals'  => $repository->totalsByCurrency( $from, $to ),
			'trend'   => $repository->dailyTrend( $from, $to ),
		);

		if ( $compareYoy ) {
			$yoyFrom = gmdate( 'Y-m-d', strtotime( '-1 year', strtotime( $from ) ) );
			$yoyTo   = gmdate( 'Y-m-d', strtotime( '-1 year', strtotime( $to ) ) );

			$response['previous_year'] = array(
				'from'   => $yoyFrom,
				'to'     => $yoyTo,
				'totals' => $repository->totalsByCurrency( $yoyFrom, $yoyTo ),
			);
		}

		wp_send_json_success( $response );
	}

	private static function sanitizeDate( $value ): ?string {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}
}
