<?php
namespace WCMCS\Tests\Unit;

use WCMCS\Services\CurrencyRule\PricingRuleService;
use WCMCS\Services\ExchangeRate\RateValidator;
use WCMCS\Services\LoggerService;

/**
 * @covers \WCMCS\Services\ExchangeRate\RateValidator
 */
class RateValidatorTest extends WcmcsUnitTestCase {

	private function makeValidator( ?array $bounds = null ): RateValidator {
		$pricingRules = $this->createMock( PricingRuleService::class );
		$pricingRules->method( 'getRateBounds' )->willReturn( $bounds );

		// A real LoggerService would hit $wpdb — not stubbed here, so a
		// mock stands in; RateValidator only ever calls warning() on it.
		$logger = $this->createMock( LoggerService::class );

		return new RateValidator( $pricingRules, $logger );
	}

	public function test_a_positive_rate_with_no_prior_history_or_bounds_is_valid(): void {
		$validator = $this->makeValidator();

		$result = $validator->validate( 'USD', 'EUR', 0.92, null );

		$this->assertTrue( $result['valid'] );
		$this->assertNull( $result['reason'] );
	}

	public function test_a_zero_rate_is_rejected(): void {
		$validator = $this->makeValidator();

		$result = $validator->validate( 'USD', 'EUR', 0.0, null );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'non_positive', $result['reason'] );
	}

	public function test_a_negative_rate_is_rejected(): void {
		$validator = $this->makeValidator();

		$result = $validator->validate( 'USD', 'EUR', -1.5, null );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'non_positive', $result['reason'] );
	}

	public function test_a_rate_outside_configured_bounds_is_rejected(): void {
		$validator = $this->makeValidator(
			array(
				'min' => 0.80,
				'max' => 1.00,
			)
		);

		$tooLow  = $validator->validate( 'USD', 'EUR', 0.50, null );
		$tooHigh = $validator->validate( 'USD', 'EUR', 1.50, null );
		$inRange = $validator->validate( 'USD', 'EUR', 0.90, null );

		$this->assertFalse( $tooLow['valid'] );
		$this->assertSame( 'out_of_bounds', $tooLow['reason'] );
		$this->assertFalse( $tooHigh['valid'] );
		$this->assertSame( 'out_of_bounds', $tooHigh['reason'] );
		$this->assertTrue( $inRange['valid'] );
	}

	public function test_a_rate_within_the_default_20_percent_deviation_threshold_is_valid(): void {
		$validator = $this->makeValidator();

		// 1.00 -> 1.15 is a 15% swing, under the 20% default threshold.
		$result = $validator->validate( 'USD', 'EUR', 1.15, 1.00 );

		$this->assertTrue( $result['valid'] );
	}

	public function test_a_rate_exceeding_the_default_deviation_threshold_is_rejected(): void {
		$validator = $this->makeValidator();

		// 1.00 -> 1.50 is a 50% swing, well over the 20% default threshold.
		$result = $validator->validate( 'USD', 'EUR', 1.50, 1.00 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'deviation_exceeded', $result['reason'] );
	}

	public function test_deviation_is_symmetric_a_large_drop_is_also_rejected(): void {
		$validator = $this->makeValidator();

		// 1.00 -> 0.50 is also a 50% swing (downward).
		$result = $validator->validate( 'USD', 'EUR', 0.50, 1.00 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'deviation_exceeded', $result['reason'] );
	}

	public function test_deviation_threshold_is_filterable_per_currency(): void {
		add_filter(
			'wcmcs_rate_deviation_threshold',
			static function ( $default, $currency ) {
				return 'EUR' === $currency ? 80.0 : $default;
			},
			10
		);

		$validator = $this->makeValidator();

		// A 50% swing would normally be rejected, but the filter above
		// raises EUR's own threshold to 80%.
		$result = $validator->validate( 'USD', 'EUR', 1.50, 1.00 );

		$this->assertTrue( $result['valid'] );
	}

	public function test_deviation_check_is_skipped_when_there_is_no_prior_rate(): void {
		$validator = $this->makeValidator();

		// A huge "swing" from nothing (first-ever fetch) must not be
		// rejected — there's nothing to compare against yet.
		$result = $validator->validate( 'USD', 'JPY', 1000.0, null );

		$this->assertTrue( $result['valid'] );
	}

	public function test_admin_configured_deviation_threshold_option_is_respected(): void {
		update_option( 'wcmcs_rate_deviation_threshold_percent', 5.0 );

		$validator = $this->makeValidator();

		// A 10% swing is within the plugin's hardcoded default (20%) but
		// outside the admin's own configured 5% threshold.
		$result = $validator->validate( 'USD', 'EUR', 1.10, 1.00 );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'deviation_exceeded', $result['reason'] );
	}
}
