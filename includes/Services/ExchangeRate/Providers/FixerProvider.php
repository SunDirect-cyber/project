<?php
declare( strict_types=1 );

namespace WCMCS\Services\ExchangeRate\Providers;

use WCMCS\Core\EncryptionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * fixer.io (apilayer). Requires an access_key (option
 * 'wcmcs_provider_fixer_key'). Note: their free plan forces EUR as the
 * base and ignores a requested non-EUR $base — same as with
 * OpenExchangeRatesProvider, an API-side restriction here just results
 * in this provider returning null so the chain tries the next one.
 */
class FixerProvider extends AbstractApiRateProvider {

	public function getSourceName(): string {
		return 'fixer';
	}

	public function isConfigured(): bool {
		return '' !== EncryptionService::getDecryptedOption( 'wcmcs_provider_fixer_key' );
	}

	public function getRate( string $base, string $target ): ?float {
		if ( ! $this->isConfigured() ) {
			return null;
		}

		$key  = EncryptionService::getDecryptedOption( 'wcmcs_provider_fixer_key' );
		$url  = sprintf(
			'https://data.fixer.io/api/latest?access_key=%s&base=%s&symbols=%s',
			rawurlencode( $key ),
			strtoupper( $base ),
			strtoupper( $target )
		);
		$data = $this->fetchJson( $url );

		if ( null === $data || true !== ( $data['success'] ?? false ) ) {
			if ( is_array( $data ) && isset( $data['error']['info'] ) ) {
				$this->logger->warning( '[fixer] API error: ' . $data['error']['info'] );
			}
			return null;
		}

		return isset( $data['rates'][ strtoupper( $target ) ] ) ? (float) $data['rates'][ strtoupper( $target ) ] : null;
	}
}
