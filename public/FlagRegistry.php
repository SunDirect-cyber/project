<?php
namespace WCMCS\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which currencies have a hand-built flag in assets/images/flags-sprite.svg.
 * Deliberately a short, honest list — every currency not in here falls
 * back to a plain text badge (its 3-letter code) in the switcher rather
 * than a guessed or missing flag.
 */
class FlagRegistry {

	private const CURRENCIES_WITH_FLAGS = array(
		'USD', 'EUR', 'GBP', 'JPY', 'CNY', 'INR', 'BRL', 'MXN', 'RUB', 'TRY',
		'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CAD', 'AUD', 'NZD', 'THB', 'VND',
		'AED', 'ILS', 'NGN', 'IDR', 'KRW', 'ZAR',
	);

	public static function hasFlag( string $currencyCode ): bool {
		return in_array( strtoupper( $currencyCode ), self::CURRENCIES_WITH_FLAGS, true );
	}

	public static function symbolId( string $currencyCode ): string {
		return 'wcmcs-flag-' . strtolower( $currencyCode );
	}
}
