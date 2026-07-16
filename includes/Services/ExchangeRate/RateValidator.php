<?php
namespace WCMCS\Services\ExchangeRate;

use WCMCS\Services\CurrencyRule\PricingRuleService;
use WCMCS\Services\LoggerService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanity-checks a freshly fetched rate before it's allowed anywhere near
 * history or a customer's price: rejects it if it's outside this
 * currency's admin-defined min/max bounds, or if it swings too far from
 * the previous known rate. Either check failing means "treat this
 * exactly like a fetch failure" — a single bad API response should
 * never be able to silently wreck every price on the site overnight.
 */
class RateValidator {

	private const DEFAULT_DEVIATION_THRESHOLD_PERCENT = 20.0;

	private PricingRuleService $pricingRules;
	private LoggerService $logger;

	public function __construct( PricingRuleService $pricingRules, LoggerService $logger ) {
		$this->pricingRules = $pricingRules;
		$this->logger       = $logger;
	}

	/**
	 * @return array{valid: bool, reason: ?string}
	 */
	public function validate( string $base, string $target, float $rate, ?float $previousRate ): array {
		if ( $rate <= 0 ) {
			return $this->reject( $base, $target, $rate, 'non_positive', 'Fetched rate was zero or negative.' );
		}

		$bounds = $this->pricingRules->getRateBounds( $target );

		if ( null !== $bounds && ( $rate < $bounds['min'] || $rate > $bounds['max'] ) ) {
			return $this->reject(
				$base,
				$target,
				$rate,
				'out_of_bounds',
				sprintf( 'Rate %.6f is outside the configured bounds [%.6f, %.6f].', $rate, $bounds['min'], $bounds['max'] )
			);
		}

		if ( null !== $previousRate && $previousRate > 0 ) {
			$deviationPercent = abs( $rate - $previousRate ) / $previousRate * 100;
			$threshold        = $this->deviationThreshold( $target );

			if ( $deviationPercent > $threshold ) {
				return $this->reject(
					$base,
					$target,
					$rate,
					'deviation_exceeded',
					sprintf(
						'Rate %.6f deviates %.2f%% from the previous rate %.6f, exceeding the %.2f%% threshold.',
						$rate,
						$deviationPercent,
						$previousRate,
						$threshold
					)
				);
			}
		}

		return array(
			'valid'  => true,
			'reason' => null,
		);
	}

	/**
	 * Max allowed deviation, filterable per currency so a store can, for
	 * example, allow more swing for a historically volatile currency.
	 */
	private function deviationThreshold( string $currency ): float {
		$default = (float) get_option( 'wcmcs_rate_deviation_threshold_percent', self::DEFAULT_DEVIATION_THRESHOLD_PERCENT );

		return (float) apply_filters( 'wcmcs_rate_deviation_threshold', $default, $currency );
	}

	/**
	 * @return array{valid: bool, reason: string}
	 */
	private function reject( string $base, string $target, float $rate, string $reasonCode, string $message ): array {
		$this->logger->warning(
			sprintf( '[rate-validator] Rejected %s->%s rate: %s', $base, $target, $message ),
			array(
				'rate'   => $rate,
				'reason' => $reasonCode,
			)
		);

		return array(
			'valid'  => false,
			'reason' => $reasonCode,
		);
	}
}
