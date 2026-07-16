<?php
namespace WCMCS\Tests\Unit;

use WCMCS\Services\Currency\Currency;

/**
 * @covers \WCMCS\Services\Currency\Currency
 */
class CurrencyTest extends WcmcsUnitTestCase {

	private function make( array $overrides = array() ): Currency {
		$defaults = array(
			'code'              => 'usd',
			'name'              => 'US Dollar',
			'symbol'            => '$',
			'decimals'          => 2,
			'symbolPosition'    => Currency::SYMBOL_BEFORE,
			'thousandSeparator' => ',',
			'decimalSeparator'  => '.',
		);
		$args     = array_merge( $defaults, $overrides );

		return new Currency(
			$args['code'],
			$args['name'],
			$args['symbol'],
			$args['decimals'],
			$args['symbolPosition'],
			$args['thousandSeparator'],
			$args['decimalSeparator']
		);
	}

	public function test_code_is_normalized_to_uppercase(): void {
		$this->assertSame( 'USD', $this->make( array( 'code' => 'usd' ) )->code() );
	}

	public function test_rejects_a_code_that_is_not_three_letters(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->make( array( 'code' => 'US' ) );
	}

	public function test_rejects_a_four_letter_code(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->make( array( 'code' => 'USDX' ) );
	}

	public function test_rejects_an_invalid_symbol_position(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->make( array( 'symbolPosition' => 'middle' ) );
	}

	public function test_rejects_negative_decimals(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->make( array( 'decimals' => -1 ) );
	}

	public function test_accepts_zero_decimals_for_currencies_like_jpy(): void {
		$jpy = $this->make(
			array(
				'code'     => 'JPY',
				'decimals' => 0,
			)
		);

		$this->assertSame( 0, $jpy->decimals() );
	}

	public function test_accepts_three_decimals_for_currencies_like_bhd(): void {
		$bhd = $this->make(
			array(
				'code'     => 'BHD',
				'decimals' => 3,
			)
		);

		$this->assertSame( 3, $bhd->decimals() );
	}

	public function test_is_symbol_before_reflects_the_configured_position(): void {
		$before = $this->make( array( 'symbolPosition' => Currency::SYMBOL_BEFORE ) );
		$after  = $this->make( array( 'symbolPosition' => Currency::SYMBOL_AFTER ) );

		$this->assertTrue( $before->isSymbolBefore() );
		$this->assertFalse( $after->isSymbolBefore() );
	}

	public function test_equals_compares_by_currency_code_not_identity(): void {
		$a = $this->make(
			array(
				'code' => 'EUR',
				'name' => 'Euro',
			)
		);
		$b = $this->make(
			array(
				'code' => 'EUR',
				'name' => 'A different name entirely',
			)
		);
		$c = $this->make( array( 'code' => 'GBP' ) );

		$this->assertTrue( $a->equals( $b ) );
		$this->assertFalse( $a->equals( $c ) );
	}

	public function test_to_array_contains_every_field(): void {
		$currency = $this->make(
			array(
				'code'   => 'EUR',
				'name'   => 'Euro',
				'symbol' => '€',
			)
		);

		$this->assertSame(
			array(
				'code'               => 'EUR',
				'name'               => 'Euro',
				'symbol'             => '€',
				'decimals'           => 2,
				'symbol_position'    => Currency::SYMBOL_BEFORE,
				'thousand_separator' => ',',
				'decimal_separator'  => '.',
			),
			$currency->toArray()
		);
	}
}
