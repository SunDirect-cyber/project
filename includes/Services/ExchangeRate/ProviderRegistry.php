<?php
declare( strict_types=1 );

namespace WCMCS\Services\ExchangeRate;

use WCMCS\Services\ExchangeRate\Providers\EcbProvider;
use WCMCS\Services\ExchangeRate\Providers\ExchangeRateApiProvider;
use WCMCS\Services\ExchangeRate\Providers\FixerProvider;
use WCMCS\Services\ExchangeRate\Providers\OpenExchangeRatesProvider;
use WCMCS\Services\LoggerService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knows about every provider the plugin ships with, and builds them in
 * the order the store owner configured (drag-and-drop priority list in
 * admin, stored as an ordered array of slugs in 'wcmcs_provider_priority').
 * This is the one place that has to know all provider classes — RateService
 * and RateProviderChain only ever see them through RateProviderInterface.
 */
class ProviderRegistry {

	private const DEFAULT_PRIORITY = array( 'exchangerate-api', 'ecb', 'openexchangerates', 'fixer', 'manual' );

	private LoggerService $logger;

	public function __construct( LoggerService $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @return array<string, callable(): RateProviderInterface>
	 */
	private function factories(): array {
		return array(
			'exchangerate-api'  => fn () => new ExchangeRateApiProvider( $this->logger ),
			'ecb'               => fn () => new EcbProvider( $this->logger ),
			'openexchangerates' => fn () => new OpenExchangeRatesProvider( $this->logger ),
			'fixer'             => fn () => new FixerProvider( $this->logger ),
			'manual'            => fn () => new ManualRateProvider(),
		);
	}

	/**
	 * @return string[] Every provider slug the plugin knows about, in no particular order.
	 */
	public function availableSlugs(): array {
		return array_keys( $this->factories() );
	}

	/**
	 * Builds a single provider in isolation — used by the admin
	 * "Test Connection" button, which needs to try exactly one provider
	 * on demand rather than the whole priority chain.
	 */
	public function buildProvider( string $slug ): ?RateProviderInterface {
		$factories = $this->factories();

		return isset( $factories[ $slug ] ) ? $factories[ $slug ]() : null;
	}

	/**
	 * Builds every provider, ordered per the admin's saved priority.
	 * Unknown slugs (e.g. left over from a removed provider) are ignored;
	 * known slugs missing from a saved priority list are appended at the
	 * end, so a newly added provider doesn't just silently never run.
	 *
	 * @return RateProviderInterface[]
	 */
	public function buildOrderedProviders(): array {
		$factories = $this->factories();
		$priority  = get_option( 'wcmcs_provider_priority', self::DEFAULT_PRIORITY );

		if ( ! is_array( $priority ) || empty( $priority ) ) {
			$priority = self::DEFAULT_PRIORITY;
		}

		$order = array_values( array_unique( array_merge( $priority, array_keys( $factories ) ) ) );

		$providers = array();

		foreach ( $order as $slug ) {
			if ( isset( $factories[ $slug ] ) ) {
				$providers[] = $factories[ $slug ]();
			}
		}

		return $providers;
	}
}
