<?php
namespace WCMCS\Services\ExchangeRate;

use WCMCS\Services\LoggerService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tries each configured provider in priority order and returns the first
 * usable rate, so a single provider being down, unconfigured, or
 * rate-limited never breaks currency conversion on the site — it just
 * silently falls through to the next one.
 *
 * Implements RateProviderInterface itself (the Composite pattern): as
 * far as RateService is concerned, the whole chain just *is* "the"
 * provider, so it never needs to know how many real providers are behind it.
 */
class RateProviderChain implements RateProviderInterface {

	/** @var RateProviderInterface[] */
	private array $providers;

	private LoggerService $logger;

	private ?string $lastSourceUsed = null;

	/**
	 * @param RateProviderInterface[] $providers Already in priority order.
	 */
	public function __construct( array $providers, LoggerService $logger ) {
		$this->providers = $providers;
		$this->logger    = $logger;
	}

	public function getSourceName(): string {
		return $this->lastSourceUsed ?? 'chain';
	}

	public function isConfigured(): bool {
		foreach ( $this->providers as $provider ) {
			if ( $provider->isConfigured() ) {
				return true;
			}
		}

		return false;
	}

	public function getRate( string $base, string $target ): ?float {
		$this->lastSourceUsed = null;

		if ( strtoupper( $base ) === strtoupper( $target ) ) {
			$this->lastSourceUsed = 'identity';
			return 1.0;
		}

		$attempted = array();

		foreach ( $this->providers as $provider ) {
			if ( ! $provider->isConfigured() ) {
				continue;
			}

			$attempted[] = $provider->getSourceName();

			try {
				$rate = $provider->getRate( $base, $target );
			} catch ( \Throwable $e ) {
				$this->logger->error(
					sprintf( '[%s] threw while fetching %s->%s: %s', $provider->getSourceName(), $base, $target, $e->getMessage() )
				);
				continue;
			}

			if ( null !== $rate && $rate > 0 ) {
				$this->lastSourceUsed = $provider->getSourceName();
				return $rate;
			}
		}

		$this->logger->error(
			sprintf( 'All rate providers failed for %s->%s', $base, $target ),
			array( 'attempted' => $attempted )
		);

		return null;
	}
}
