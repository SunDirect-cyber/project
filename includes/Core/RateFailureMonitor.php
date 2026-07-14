<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks when exchange rates were last successfully refreshed, and
 * emails the site admin if they've gone stale for longer than the
 * configured threshold. Sends at most one alert per "outage" — once
 * sent, it won't email again until rates recover and then go stale
 * again, so a persistent provider outage doesn't turn into an inbox
 * full of identical warnings.
 */
class RateFailureMonitor {

	private const OPTION_LAST_SUCCESS  = 'wcmcs_last_rate_success_at';
	private const OPTION_ALERT_SENT    = 'wcmcs_rate_alert_sent';
	private const DEFAULT_THRESHOLD_HOURS = 24;

	/**
	 * @param array<string, array{rate: float|null, source: ?string}> $results
	 */
	public function recordRefreshResult( array $results ): void {
		if ( empty( $results ) ) {
			return;
		}

		$anySuccess = false;

		foreach ( $results as $result ) {
			if ( null !== $result['rate'] ) {
				$anySuccess = true;
				break;
			}
		}

		if ( $anySuccess ) {
			update_option( self::OPTION_LAST_SUCCESS, time() );

			// Rates are flowing again — clear the flag so a future
			// outage can trigger a fresh alert instead of staying silent.
			if ( get_option( self::OPTION_ALERT_SENT ) ) {
				delete_option( self::OPTION_ALERT_SENT );
			}

			return;
		}

		$this->maybeAlert();
	}

	private function maybeAlert(): void {
		$lastSuccess = (int) get_option( self::OPTION_LAST_SUCCESS, 0 );

		// No successful refresh has ever been recorded yet (e.g. brand
		// new install) — nothing to compare against, so don't alert.
		if ( 0 === $lastSuccess ) {
			return;
		}

		if ( get_option( self::OPTION_ALERT_SENT ) ) {
			return; // Already alerted for this outage.
		}

		$thresholdHours = (int) get_option( 'wcmcs_rate_alert_threshold_hours', self::DEFAULT_THRESHOLD_HOURS );
		$hoursStale      = ( time() - $lastSuccess ) / HOUR_IN_SECONDS;

		if ( $hoursStale < $thresholdHours ) {
			return;
		}

		$this->sendAlertEmail( $hoursStale );

		update_option( self::OPTION_ALERT_SENT, true );
	}

	private function sendAlertEmail( float $hoursStale ): void {
		$to = get_option( 'admin_email' );

		if ( empty( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Currency exchange rates have not updated', 'wc-multicurrency-switcher' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			/* translators: %d: number of hours */
			__( "WooCommerce Multi-Currency Switcher has not been able to fetch fresh exchange rates from any configured provider for over %d hours.\n\nCustomers are currently seeing prices based on the last rate that was successfully fetched. Please check the plugin's exchange rate settings and provider status.", 'wc-multicurrency-switcher' ),
			(int) floor( $hoursStale )
		);

		wp_mail( $to, $subject, $message );
	}
}
