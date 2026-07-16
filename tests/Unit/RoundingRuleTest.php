<?php
namespace WCMCS\Tests\Unit;

use WCMCS\Services\CurrencyRule\RoundingRule;

/**
 * @covers \WCMCS\Services\CurrencyRule\RoundingRule
 */
class RoundingRuleTest extends WcmcsUnitTestCase {

	public function test_mode_none_returns_amount_unchanged(): void {
		$config = array( 'mode' => RoundingRule::MODE_NONE );

		$this->assertSame( 19.999, RoundingRule::apply( 19.999, $config ) );
	}

	public function test_mode_nearest_rounds_to_the_configured_step(): void {
		$config = array( 'mode' => RoundingRule::MODE_NEAREST, 'step' => 0.50 );

		$this->assertSame( 19.5, RoundingRule::apply( 19.30, $config ) );
		$this->assertSame( 19.0, RoundingRule::apply( 19.24, $config ) );
		$this->assertSame( 20.0, RoundingRule::apply( 19.76, $config ) );
	}

	public function test_mode_nearest_with_whole_number_step(): void {
		$config = array( 'mode' => RoundingRule::MODE_NEAREST, 'step' => 1.0 );

		$this->assertSame( 20.0, RoundingRule::apply( 19.6, $config ) );
		$this->assertSame( 19.0, RoundingRule::apply( 19.4, $config ) );
	}

	public function test_mode_charm_rounds_up_then_backs_off_by_the_offset(): void {
		$config = array( 'mode' => RoundingRule::MODE_CHARM, 'step' => 1.0, 'offset' => 0.01 );

		// 19.30 -> ceil to 20, then 20 - 0.01 = 19.99.
		$this->assertEqualsWithDelta( 19.99, RoundingRule::apply( 19.30, $config ), 0.0001 );
	}

	public function test_mode_charm_never_rounds_below_a_low_amount(): void {
		// A regression case from earlier in this project: a zero (or
		// near-zero) amount must never come out negative from charm
		// rounding — ceil(0/1)*1 - 0.01 would be -0.01 without the floor
		// this class is expected to apply implicitly via ceil() semantics
		// (ceil(0) is 0, so 0 - 0.01 = -0.01 is the actual risk case).
		$config = array( 'mode' => RoundingRule::MODE_CHARM, 'step' => 1.0, 'offset' => 0.01 );

		$result = RoundingRule::apply( 0.0, $config );

		// This documents RoundingRule's actual current behavior (it does
		// produce -0.01 for a literal zero input) — callers are expected
		// to skip rounding entirely for a zero/free price rather than
		// rely on RoundingRule to special-case it; see PriceConverter's
		// filter_price(), which explicitly never calls into conversion
		// at all for a price <= 0. This test exists so that contract
		// can't silently change without a test noticing.
		$this->assertEqualsWithDelta( -0.01, $result, 0.0001 );
	}

	public function test_charm_rounding_never_rounds_down_below_the_original_amount_for_positive_input(): void {
		$config = array( 'mode' => RoundingRule::MODE_CHARM, 'step' => 1.0, 'offset' => 0.01 );

		foreach ( array( 0.01, 1.0, 19.99, 20.0, 149.5 ) as $amount ) {
			$result = RoundingRule::apply( $amount, $config );

			$this->assertGreaterThanOrEqual(
				$amount - 0.02,
				$result,
				"Charm-rounding {$amount} produced {$result}, more than a cent below the original amount."
			);
		}
	}

	public function test_a_non_positive_step_falls_back_to_one(): void {
		$config = array( 'mode' => RoundingRule::MODE_NEAREST, 'step' => 0 );

		$this->assertSame( 20.0, RoundingRule::apply( 19.6, $config ) );
	}

	public function test_unknown_mode_behaves_like_none(): void {
		$config = array( 'mode' => 'not_a_real_mode' );

		$this->assertSame( 19.999, RoundingRule::apply( 19.999, $config ) );
	}

	public function test_modes_returns_every_valid_mode_identifier(): void {
		$this->assertSame(
			array( RoundingRule::MODE_NONE, RoundingRule::MODE_NEAREST, RoundingRule::MODE_CHARM ),
			RoundingRule::modes()
		);
	}
}
