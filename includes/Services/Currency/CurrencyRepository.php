<?php
namespace WCMCS\Services\Currency;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns CurrencyData's raw rows into Currency value objects, and caches
 * them so the same code always returns the exact same immutable instance
 * for the lifetime of the request.
 */
class CurrencyRepository {

	/** @var array<string, Currency> */
	private array $cache = array();

	/** @var array<string, array>|null */
	private ?array $rawData = null;

	public function get( string $code ): ?Currency {
		$code = strtoupper( trim( $code ) );

		if ( isset( $this->cache[ $code ] ) ) {
			return $this->cache[ $code ];
		}

		$row = $this->raw()[ $code ] ?? null;

		if ( null === $row ) {
			return null;
		}

		$currency = new Currency(
			$code,
			$row['name'],
			$row['symbol'],
			$row['decimals'],
			$row['position'],
			$row['thousand_separator'],
			$row['decimal_separator']
		);

		/**
		 * Lets a store owner override formatting for a specific currency
		 * (e.g. a different symbol placement) without touching plugin code.
		 */
		$currency = apply_filters( 'wcmcs_currency', $currency, $code );

		$this->cache[ $code ] = $currency;

		return $currency;
	}

	public function exists( string $code ): bool {
		return isset( $this->raw()[ strtoupper( trim( $code ) ) ] );
	}

	/**
	 * @return Currency[] All supported currencies, keyed by ISO code.
	 */
	public function all(): array {
		$currencies = array();

		foreach ( array_keys( $this->raw() ) as $code ) {
			$currencies[ $code ] = $this->get( $code );
		}

		return $currencies;
	}

	/**
	 * @return array<string, array>
	 */
	private function raw(): array {
		if ( null === $this->rawData ) {
			$this->rawData = apply_filters( 'wcmcs_currency_data', CurrencyData::raw() );
		}

		return $this->rawData;
	}
}
