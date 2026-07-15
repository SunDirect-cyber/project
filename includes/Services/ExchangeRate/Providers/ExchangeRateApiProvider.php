<?php
namespace WCMCS\Services\ExchangeRate\Providers;

use WCMCS\Core\EncryptionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * exchangerate-api.com. Works two ways:
 *  - No API key configured: uses their genuinely free, keyless
 *    open.er-api.com endpoint (rate-limited but requires zero setup —
 *    this is what makes the plugin usable the moment it's activated).
 *  - API key configured (option 'wcmcs_provider_exchangerateapi_key'):
 *    uses their v6 paid-tier endpoint for higher limits and the
 *    dedicated single-pair route.
 */
class ExchangeRateApiProvider extends AbstractApiRateProvider {

	public function getSourceName(): string {
		return 'exchangerate-api';
	}

	public function isConfigured(): bool {
		// Always usable — the keyless endpoint is the fallback.
		return true;
	}

	public function getRate( string $base, string $target ): ?float {
		$base   = strtoupper( $base );
		$target = strtoupper( $target );
		$key    = EncryptionService::getDecryptedOption( 'wcmcs_provider_exchangerateapi_key' );

		if ( '' !== $key ) {
			$data = $this->fetchJson( sprintf( 'https://v6.exchangerate-api.com/v6/%s/pair/%s/%s', rawurlencode( $key ), $base, $target ) );

			if ( null === $data || 'success' !== ( $data['result'] ?? null ) ) {
				return null;
			}

			return isset( $data['conversion_rate'] ) ? (float) $data['conversion_rate'] : null;
		}

		$data = $this->fetchJson( sprintf( 'https://open.er-api.com/v6/latest/%s', $base ) );

		if ( null === $data || 'success' !== ( $data['result'] ?? null ) ) {
			return null;
		}

		return isset( $data['rates'][ $target ] ) ? (float) $data['rates'][ $target ] : null;
	}
}
