<?php
declare( strict_types=1 );

namespace WCMCS\Services\ExchangeRate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default rate provider: reads rates the store owner entered by hand in
 * the plugin settings, stored as a flat "BASE_TARGET" => rate map. This
 * is what makes the plugin work out of the box with zero configuration
 * or API keys — a live-rate provider (pulling from a rates API) can be
 * added later as an alternative, but manual rates always remain a valid
 * fallback for currencies or stores that want fixed, predictable pricing.
 */
class ManualRateProvider implements RateProviderInterface {

	public function getRate( string $base, string $target ): ?float {
		$rates = (array) get_option( 'wcmcs_manual_rates', array() );
		$key   = strtoupper( $base ) . '_' . strtoupper( $target );

		if ( ! isset( $rates[ $key ] ) || ! is_numeric( $rates[ $key ] ) ) {
			return null;
		}

		return (float) $rates[ $key ];
	}

	public function getSourceName(): string {
		return 'manual';
	}

	public function isConfigured(): bool {
		return true;
	}
}
