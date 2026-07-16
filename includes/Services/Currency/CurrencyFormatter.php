<?php
declare( strict_types=1 );

namespace WCMCS\Services\Currency;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats a numeric amount using a Currency's own native rules — decimal
 * places, thousand/decimal separators, and symbol position — instead of
 * a single hardcoded "$1,234.56" style applied to every currency.
 */
class CurrencyFormatter {

	/**
	 * @param float|int|string $amount
	 */
	public function format( $amount, Currency $currency, bool $withSymbol = true ): string {
		$amount     = (float) $amount;
		$isNegative = $amount < 0;

		$number = number_format(
			abs( $amount ),
			$currency->decimals(),
			$currency->decimalSeparator(),
			$currency->thousandSeparator()
		);

		if ( $withSymbol ) {
			$number = $currency->isSymbolBefore()
				? $currency->symbol() . $number
				: $number . ' ' . $currency->symbol();
		}

		if ( $isNegative ) {
			$number = '-' . $number;
		}

		/**
		 * Final hook so themes/extensions can adjust the rendered string
		 * (e.g. wrap it in a <span>) without re-implementing formatting.
		 */
		return apply_filters( 'wcmcs_format_currency', $number, $amount, $currency, $withSymbol );
	}

	/**
	 * Rounds an amount to the currency's own decimal precision, without
	 * formatting it into a display string. Useful before storing an
	 * amount or doing further math on it.
	 */
	public function round( $amount, Currency $currency ): float {
		return round( (float) $amount, $currency->decimals() );
	}
}
