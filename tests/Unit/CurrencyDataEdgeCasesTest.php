<?php
namespace WCMCS\Tests\Unit;

use WCMCS\Services\Currency\CurrencyRepository;

/**
 * @covers \WCMCS\Services\Currency\CurrencyRepository
 * @covers \WCMCS\Services\Currency\CurrencyData
 *
 * Regression coverage for the zero-decimal and three-decimal currency
 * edge cases from the plugin's Edge Case & Failure Handling work — the
 * "must not show ¥100.00" and "must not lose BHD's third decimal" bugs.
 */
class CurrencyDataEdgeCasesTest extends WcmcsUnitTestCase {

	/**
	 * @dataProvider zeroDecimalCurrencies
	 */
	public function test_zero_decimal_currencies_are_configured_with_zero_decimals( string $code ): void {
		$currency = ( new CurrencyRepository() )->get( $code );

		$this->assertNotNull( $currency, "{$code} should be a known currency." );
		$this->assertSame( 0, $currency->decimals(), "{$code} should be configured with 0 decimal places." );
	}

	public function zeroDecimalCurrencies(): array {
		return array(
			'Japanese Yen'     => array( 'JPY' ),
			'South Korean Won' => array( 'KRW' ),
		);
	}

	/**
	 * @dataProvider threeDecimalCurrencies
	 */
	public function test_three_decimal_currencies_are_configured_with_three_decimals( string $code ): void {
		$currency = ( new CurrencyRepository() )->get( $code );

		$this->assertNotNull( $currency, "{$code} should be a known currency." );
		$this->assertSame( 3, $currency->decimals(), "{$code} should be configured with 3 decimal places." );
	}

	public function threeDecimalCurrencies(): array {
		return array(
			'Bahraini Dinar' => array( 'BHD' ),
			'Kuwaiti Dinar'  => array( 'KWD' ),
		);
	}

	public function test_the_common_two_decimal_case_is_still_the_default(): void {
		$usd = ( new CurrencyRepository() )->get( 'USD' );

		$this->assertSame( 2, $usd->decimals() );
	}

	public function test_over_150_currencies_are_available(): void {
		$this->assertGreaterThan( 150, count( ( new CurrencyRepository() )->all() ) );
	}

	/**
	 * The exact "convert then round to native precision" pipeline
	 * PriceConverter::convertAndRound() performs as its final step —
	 * exercised here directly with real Currency decimal counts, without
	 * needing the full PriceConverter/WooCommerce stack.
	 *
	 * @dataProvider conversionPrecisionCases
	 */
	public function test_a_converted_price_rounds_to_the_target_currencys_real_precision(
		string $targetCode,
		float $rawConvertedAmount,
		float $expectedRounded
	): void {
		$currency = ( new CurrencyRepository() )->get( $targetCode );

		$this->assertSame( $expectedRounded, round( $rawConvertedAmount, $currency->decimals() ) );
	}

	public function conversionPrecisionCases(): array {
		return array(
			'JPY: fractional yen must round to a whole number' => array( 'JPY', 2994.9917, 2995.0 ),
			'BHD: keeps its third decimal, not truncated to 2' => array( 'BHD', 37.5928, 37.593 ),
			'USD: ordinary two-decimal rounding' => array( 'USD', 19.996, 20.0 ),
			'USD micro-amount rounds cleanly, no float drift' => array( 'USD', 0.0092, 0.01 ),
		);
	}
}
