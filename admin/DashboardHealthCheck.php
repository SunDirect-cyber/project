<?php
declare( strict_types=1 );

namespace WCMCS\Admin;

use WCMCS\Core\Plugin;
use WCMCS\Services\Gateway\GatewayCurrencyMatrix;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The dashboard's health check panel: real, actionable warnings rather
 * than a decorative status widget. Every check here corresponds to a
 * genuine failure mode this plugin can actually detect from its own
 * state — nothing fabricated just to fill the panel.
 */
class DashboardHealthCheck {

	/**
	 * @return array{level: string, message: string}[] level is 'ok', 'warning', or 'error'.
	 */
	public static function run(): array {
		$results = array();

		$results[] = self::checkRateFreshness();
		$results[] = self::checkPrioritizedProvidersConfigured();
		$results[] = self::checkGatewayCurrencyMismatch();
		$results[] = self::checkHposApiAvailable();

		return array_values( array_filter( $results ) );
	}

	private static function checkRateFreshness(): array {
		$lastSuccess = (int) get_option( 'wcmcs_last_rate_success_at', 0 );

		if ( 0 === $lastSuccess ) {
			return array(
				'level'   => 'warning',
				'message' => __( 'Exchange rates have never been successfully refreshed yet.', 'wc-multicurrency-switcher' ),
			);
		}

		$hoursStale      = ( time() - $lastSuccess ) / HOUR_IN_SECONDS;
		$thresholdHours = (int) get_option( 'wcmcs_rate_alert_threshold_hours', 24 );

		if ( $hoursStale > $thresholdHours ) {
			return array(
				'level'   => 'error',
				'message' => sprintf(
					/* translators: %d: number of hours */
					__( 'Exchange rates have not refreshed successfully in over %d hours.', 'wc-multicurrency-switcher' ),
					(int) floor( $hoursStale )
				),
			);
		}

		return array(
			'level'   => 'ok',
			'message' => __( 'Exchange rates are up to date.', 'wc-multicurrency-switcher' ),
		);
	}

	private static function checkPrioritizedProvidersConfigured(): ?array {
		$priority = (array) get_option( 'wcmcs_provider_priority', array() );

		/** @var \WCMCS\Services\ExchangeRate\ProviderRegistry $registry */
		$registry  = Plugin::instance()->container()->get( 'provider_registry' );
		$providers = $registry->buildOrderedProviders();

		$missing = array();

		foreach ( $providers as $provider ) {
			$requiresKey = in_array( $provider->getSourceName(), array( 'openexchangerates', 'fixer' ), true );

			if ( $requiresKey && ! $provider->isConfigured() && in_array( $provider->getSourceName(), $priority, true ) ) {
				$missing[] = $provider->getSourceName();
			}
		}

		if ( empty( $missing ) ) {
			return null;
		}

		return array(
			'level'   => 'warning',
			'message' => sprintf(
				/* translators: %s: comma-separated provider names */
				__( 'These rate providers are in your priority order but have no API key configured, so they\'ll always be skipped: %s.', 'wc-multicurrency-switcher' ),
				implode( ', ', $missing )
			),
		);
	}

	private static function checkGatewayCurrencyMismatch(): ?array {
		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return null;
		}

		$enabled  = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );
		$base     = self::baseCurrency();
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();

		if ( empty( $gateways ) ) {
			return null;
		}

		$unsupported = array();

		foreach ( $enabled as $currency ) {
			if ( $currency === $base ) {
				continue;
			}

			$hasSupport = false;

			foreach ( $gateways as $gateway ) {
				if ( GatewayCurrencyMatrix::supports( $gateway->id, $currency ) ) {
					$hasSupport = true;
					break;
				}
			}

			if ( ! $hasSupport ) {
				$unsupported[] = $currency;
			}
		}

		if ( empty( $unsupported ) ) {
			return null;
		}

		return array(
			'level'   => 'warning',
			'message' => sprintf(
				/* translators: %s: comma-separated currency codes */
				__( 'No active payment gateway is marked as supporting: %s. Shoppers using these will be charged in your base currency instead, with a notice.', 'wc-multicurrency-switcher' ),
				implode( ', ', $unsupported )
			),
		);
	}

	private static function checkHposApiAvailable(): ?array {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return array(
				'level'   => 'warning',
				'message' => __( 'Your WooCommerce version doesn\'t support the HPOS compatibility API — consider updating WooCommerce.', 'wc-multicurrency-switcher' ),
			);
		}

		return null;
	}

	private static function baseCurrency(): string {
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );

		return $currencyService->baseCurrency()->code();
	}
}
