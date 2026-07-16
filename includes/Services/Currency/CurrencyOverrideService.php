<?php
namespace WCMCS\Services\Currency;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store-owner overrides for a currency's own formatting — symbol,
 * decimal places, symbol position, thousand/decimal separators —
 * layered on top of CurrencyData's ISO-4217-derived defaults via the
 * 'wcmcs_currency' filter CurrencyRepository already calls for every
 * currency it builds (see Currency Management admin screen). No change
 * needed to CurrencyRepository itself — this is exactly what that
 * filter hook was built for.
 */
class CurrencyOverrideService {

	private const OPTION = 'wcmcs_currency_format_overrides';

	private const OVERRIDABLE_FIELDS = array(
		'symbol',
		'decimals',
		'symbol_position',
		'thousand_separator',
		'decimal_separator',
	);

	public static function register(): void {
		add_filter( 'wcmcs_currency', array( self::class, 'applyOverride' ), 10, 2 );
	}

	public static function applyOverride( Currency $currency, string $code ): Currency {
		$override = self::get( $code );

		if ( empty( $override ) ) {
			return $currency;
		}

		$merged = array_merge( $currency->toArray(), $override );

		return new Currency(
			$merged['code'],
			$merged['name'],
			$merged['symbol'],
			(int) $merged['decimals'],
			$merged['symbol_position'],
			$merged['thousand_separator'],
			$merged['decimal_separator']
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get( string $code ): array {
		$all = (array) get_option( self::OPTION, array() );

		return $all[ strtoupper( $code ) ] ?? array();
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	public static function set( string $code, array $fields ): void {
		$all = (array) get_option( self::OPTION, array() );

		$all[ strtoupper( $code ) ] = array_intersect_key( $fields, array_flip( self::OVERRIDABLE_FIELDS ) );

		update_option( self::OPTION, $all );
	}

	public static function clear( string $code ): void {
		$all = (array) get_option( self::OPTION, array() );

		unset( $all[ strtoupper( $code ) ] );

		update_option( self::OPTION, $all );
	}
}
