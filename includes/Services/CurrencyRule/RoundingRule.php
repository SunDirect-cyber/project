<?php
namespace WCMCS\Services\CurrencyRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure rounding logic for "psychological pricing" — converted prices
 * rounded to a nearest step (e.g. nearest 0.50) or "charm priced" just
 * under a step (e.g. nearest whole number minus 0.01, giving X.99).
 * Stateless on purpose: takes a config array (decoded from a rule's
 * JSON rule_value), never touches the database itself.
 */
class RoundingRule {

	public const MODE_NONE    = 'none';
	public const MODE_NEAREST = 'nearest';
	public const MODE_CHARM   = 'charm';

	/**
	 * @param array{mode?: string, step?: float, offset?: float} $config
	 */
	public static function apply( float $amount, array $config ): float {
		$mode = $config['mode'] ?? self::MODE_NONE;
		$step = isset( $config['step'] ) ? (float) $config['step'] : 1.0;

		if ( $step <= 0 ) {
			$step = 1.0;
		}

		switch ( $mode ) {
			case self::MODE_NEAREST:
				// e.g. step=0.50 rounds 19.30 -> 19.50, step=1 rounds to a whole number.
				return round( $amount / $step ) * $step;

			case self::MODE_CHARM:
				// e.g. step=1, offset=0.01 turns 19.30 into 19.99 — the price
				// rounds *up* to the next step, then backs off by the offset,
				// so it never rounds down below the original amount.
				$offset = isset( $config['offset'] ) ? (float) $config['offset'] : 0.01;
				return ( ceil( $amount / $step ) * $step ) - $offset;

			case self::MODE_NONE:
			default:
				return $amount;
		}
	}

	/**
	 * @return string[] Valid mode identifiers, for validating admin input.
	 */
	public static function modes(): array {
		return array( self::MODE_NONE, self::MODE_NEAREST, self::MODE_CHARM );
	}
}
