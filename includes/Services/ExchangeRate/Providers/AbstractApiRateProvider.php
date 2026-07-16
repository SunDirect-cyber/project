<?php
namespace WCMCS\Services\ExchangeRate\Providers;

use WCMCS\Services\ExchangeRate\RateProviderInterface;
use WCMCS\Services\LoggerService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared HTTP/JSON plumbing for the API-based providers. Each concrete
 * provider only needs to know its own URL shape and how to read the
 * rate out of the response — request handling, error logging, and
 * timeouts live here once instead of being copy-pasted per provider.
 */
abstract class AbstractApiRateProvider implements RateProviderInterface {

	protected LoggerService $logger;

	public function __construct( LoggerService $logger ) {
		$this->logger = $logger;
	}

	/**
	 * GETs a URL and decodes a JSON body. Returns null (and logs why) on
	 * any network error, non-2xx response, or unparseable body, so
	 * callers can treat "null" as the single uniform failure signal.
	 */
	protected function fetchJson( string $url, int $timeoutSeconds = 8 ): ?array {
		$response = wp_remote_get( $url, array( 'timeout' => $timeoutSeconds ) );

		if ( is_wp_error( $response ) ) {
			$this->logger->warning(
				sprintf( '[%s] request failed: %s', $this->getSourceName(), $response->get_error_message() ),
				array( 'url' => $this->redact( $url ) )
			);
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			$this->logger->warning(
				sprintf( '[%s] unexpected HTTP status %d', $this->getSourceName(), $status ),
				array(
					'url'  => $this->redact( $url ),
					'body' => substr( wp_remote_retrieve_body( $response ), 0, 500 ),
				)
			);
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			$this->logger->warning(
				sprintf( '[%s] response was not valid JSON', $this->getSourceName() ),
				array( 'url' => $this->redact( $url ) )
			);
			return null;
		}

		return $data;
	}

	/**
	 * Strips API keys out of a URL before it's written to the log table,
	 * so credentials never end up sitting in plaintext in the database.
	 */
	protected function redact( string $url ): string {
		return (string) preg_replace( '/(key|access_key|app_id)=[^&]+/i', '$1=***', $url );
	}
}
