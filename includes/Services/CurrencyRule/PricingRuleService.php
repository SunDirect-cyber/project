<?php
namespace WCMCS\Services\CurrencyRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The business-rules layer on top of raw exchange rates: per-currency
 * locked rates, a markup percentage, and rounding. Kept deliberately
 * separate from RateService/RateRepository — those stay a truthful
 * record of real market rates (which matters for history, auditing,
 * and rollback), while this layer is where a store owner's pricing
 * decisions get applied on top of that raw data.
 */
class PricingRuleService {

	private CurrencyRuleRepository $rules;

	public function __construct( CurrencyRuleRepository $rules ) {
		$this->rules = $rules;
	}

	/**
	 * A currency locked to a fixed rate is meant to bypass live
	 * providers entirely, so callers should check this *before* ever
	 * asking RateService for a live rate.
	 */
	public function getLockedRate( string $currency ): ?float {
		$rule = $this->rules->get( strtoupper( $currency ), CurrencyRuleRepository::TYPE_LOCKED_RATE );

		return ( null !== $rule && is_numeric( $rule['rule_value'] ) ) ? (float) $rule['rule_value'] : null;
	}

	public function setLockedRate( string $currency, float $rate ): void {
		$this->rules->upsert( strtoupper( $currency ), CurrencyRuleRepository::TYPE_LOCKED_RATE, (string) $rate );
	}

	public function clearLockedRate( string $currency ): void {
		$this->rules->remove( strtoupper( $currency ), CurrencyRuleRepository::TYPE_LOCKED_RATE );
	}

	public function isLocked( string $currency ): bool {
		return null !== $this->getLockedRate( $currency );
	}

	public function getMarkupPercent( string $currency ): float {
		$rule = $this->rules->get( strtoupper( $currency ), CurrencyRuleRepository::TYPE_MARKUP_PERCENT );

		return ( null !== $rule && is_numeric( $rule['rule_value'] ) ) ? (float) $rule['rule_value'] : 0.0;
	}

	public function setMarkupPercent( string $currency, float $percent ): void {
		$this->rules->upsert( strtoupper( $currency ), CurrencyRuleRepository::TYPE_MARKUP_PERCENT, (string) $percent );
	}

	public function clearMarkupPercent( string $currency ): void {
		$this->rules->remove( strtoupper( $currency ), CurrencyRuleRepository::TYPE_MARKUP_PERCENT );
	}

	/**
	 * Applies this currency's configured markup to a raw (live) rate.
	 * A positive percent raises the rate, e.g. 2.0 on a rate of 1.10
	 * gives 1.122 — showing the customer a slightly worse rate than the
	 * real one, the common way stores absorb currency risk.
	 */
	public function applyMarkup( string $currency, float $rawRate ): float {
		$percent = $this->getMarkupPercent( $currency );

		return 0.0 === $percent ? $rawRate : $rawRate * ( 1 + ( $percent / 100 ) );
	}

	/**
	 * @return array{mode: string, step: float, offset: float}
	 */
	public function getRoundingConfig( string $currency ): array {
		$rule = $this->rules->get( strtoupper( $currency ), CurrencyRuleRepository::TYPE_ROUNDING );

		if ( null === $rule ) {
			return array( 'mode' => RoundingRule::MODE_NONE, 'step' => 1.0, 'offset' => 0.0 );
		}

		$config = json_decode( $rule['rule_value'], true );

		return array(
			'mode'   => is_array( $config ) && in_array( $config['mode'] ?? '', RoundingRule::modes(), true ) ? $config['mode'] : RoundingRule::MODE_NONE,
			'step'   => is_array( $config ) && isset( $config['step'] ) ? (float) $config['step'] : 1.0,
			'offset' => is_array( $config ) && isset( $config['offset'] ) ? (float) $config['offset'] : 0.0,
		);
	}

	public function setRoundingConfig( string $currency, string $mode, float $step = 1.0, float $offset = 0.01 ): void {
		if ( ! in_array( $mode, RoundingRule::modes(), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid rounding mode "%s".', $mode ) );
		}

		$this->rules->upsert(
			strtoupper( $currency ),
			CurrencyRuleRepository::TYPE_ROUNDING,
			wp_json_encode( array( 'mode' => $mode, 'step' => $step, 'offset' => $offset ) )
		);
	}

	public function clearRounding( string $currency ): void {
		$this->rules->remove( strtoupper( $currency ), CurrencyRuleRepository::TYPE_ROUNDING );
	}

	public function roundPrice( string $currency, float $amount ): float {
		return RoundingRule::apply( $amount, $this->getRoundingConfig( $currency ) );
	}

	/**
	 * Admin-defined safety limits: a fetched rate outside [min, max] for
	 * this currency gets rejected by RateValidator before it ever
	 * reaches history or a customer's price, regardless of what any
	 * provider returned.
	 *
	 * @return array{min: float, max: float}|null
	 */
	public function getRateBounds( string $currency ): ?array {
		$rule = $this->rules->get( strtoupper( $currency ), CurrencyRuleRepository::TYPE_RATE_BOUNDS );

		if ( null === $rule ) {
			return null;
		}

		$config = json_decode( $rule['rule_value'], true );

		if ( ! is_array( $config ) || ! isset( $config['min'], $config['max'] ) ) {
			return null;
		}

		return array( 'min' => (float) $config['min'], 'max' => (float) $config['max'] );
	}

	public function setRateBounds( string $currency, float $min, float $max ): void {
		if ( $min > $max ) {
			throw new \InvalidArgumentException( 'Minimum bound cannot be greater than the maximum bound.' );
		}

		$this->rules->upsert(
			strtoupper( $currency ),
			CurrencyRuleRepository::TYPE_RATE_BOUNDS,
			wp_json_encode( array( 'min' => $min, 'max' => $max ) )
		);
	}

	public function clearRateBounds( string $currency ): void {
		$this->rules->remove( strtoupper( $currency ), CurrencyRuleRepository::TYPE_RATE_BOUNDS );
	}
}
