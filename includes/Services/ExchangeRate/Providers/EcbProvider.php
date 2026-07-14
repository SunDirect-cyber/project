<?php
namespace WCMCS\Services\ExchangeRate\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * European Central Bank's free daily reference-rate XML feed. No API
 * key, no rate limit in practice, but two real limitations to work
 * around:
 *  - It only publishes rates against EUR, so any pair not involving EUR
 *    has to be triangulated: rate(base, target) = rate(EUR, target) /
 *    rate(EUR, base).
 *  - It covers roughly 30 major currencies, not all 155 this plugin
 *    supports — for anything else it correctly returns null so the
 *    chain moves on to a provider that does cover it.
 */
class EcbProvider extends AbstractApiRateProvider {

	private const FEED_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

	/** @var array<string, float>|null Cached for the lifetime of this instance. */
	private ?array $eurRates = null;

	public function getSourceName(): string {
		return 'ecb';
	}

	public function isConfigured(): bool {
		return true;
	}

	public function getRate( string $base, string $target ): ?float {
		$base   = strtoupper( $base );
		$target = strtoupper( $target );

		if ( $base === $target ) {
			return 1.0;
		}

		$rates = $this->getEurRates();

		if ( null === $rates ) {
			return null;
		}

		if ( 'EUR' === $base ) {
			return $rates[ $target ] ?? null;
		}

		if ( 'EUR' === $target ) {
			return isset( $rates[ $base ] ) && $rates[ $base ] > 0 ? 1 / $rates[ $base ] : null;
		}

		if ( ! isset( $rates[ $base ], $rates[ $target ] ) || $rates[ $base ] <= 0 ) {
			return null;
		}

		return $rates[ $target ] / $rates[ $base ];
	}

	/**
	 * @return array<string, float>|null EUR-based rates, e.g. ['USD' => 1.08, ...].
	 */
	private function getEurRates(): ?array {
		if ( null !== $this->eurRates ) {
			return $this->eurRates;
		}

		$response = wp_remote_get( self::FEED_URL, array( 'timeout' => 8 ) );

		if ( is_wp_error( $response ) ) {
			$this->logger->warning( '[ecb] request failed: ' . $response->get_error_message() );
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			$this->logger->warning( sprintf( '[ecb] unexpected HTTP status %d', $status ) );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		// Suppress libxml warnings on malformed XML — we just want a
		// clean null result if the feed is temporarily broken.
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );
		libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			$this->logger->warning( '[ecb] response was not valid XML' );
			return null;
		}

		$rates = array( 'EUR' => 1.0 );

		// Structure: Envelope > Cube > Cube[time] > Cube[currency, rate]
		foreach ( $xml->Cube->Cube->Cube as $cube ) {
			$attributes = $cube->attributes();
			$currency   = (string) $attributes->currency;
			$rate       = (float) $attributes->rate;

			if ( '' !== $currency && $rate > 0 ) {
				$rates[ $currency ] = $rate;
			}
		}

		if ( count( $rates ) <= 1 ) {
			$this->logger->warning( '[ecb] feed parsed but contained no currency rates' );
			return null;
		}

		$this->eurRates = $rates;

		return $this->eurRates;
	}
}
