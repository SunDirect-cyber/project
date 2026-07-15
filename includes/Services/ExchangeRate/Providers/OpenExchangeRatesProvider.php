<?php
namespace WCMCS\Services\ExchangeRate\Providers;

use WCMCS\Core\EncryptionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * openexchangerates.org. Requires an app_id (option
 * 'wcmcs_provider_openexchangerates_key'). Note: their free plan only
 * supports USD as the base currency — a non-USD $base on the free plan
 * will come back as an API error, which fetchJson/this method both treat
 * as "no rate", letting the fallback chain move on to the next provider.
 */
class OpenExchangeRatesProvider extends AbstractApiRateProvider {

	public function getSourceName(): string {
		return 'openexchangerates';
	}

	public function isConfigured(): bool {
		return '' !== EncryptionService::getDecryptedOption( 'wcmcs_provider_openexchangerates_key' );
	}

	public function getRate( string $base, string $target ): ?float {
		if ( ! $this->isConfigured() ) {
			return null;
		}

		$key  = EncryptionService::getDecryptedOption( 'wcmcs_provider_openexchangerates_key' );
		$url  = sprintf(
			'https://openexchangerates.org/api/latest.json?app_id=%s&base=%s&symbols=%s',
			rawurlencode( $key ),
			strtoupper( $base ),
			strtoupper( $target )
		);
		$data = $this->fetchJson( $url );

		if ( null === $data || isset( $data['error'] ) ) {
			return null;
		}

		return isset( $data['rates'][ strtoupper( $target ) ] ) ? (float) $data['rates'][ strtoupper( $target ) ] : null;
	}
}
