<?php
namespace WCMCS\Tests\Unit;

use WCMCS\Services\CacheService;
use WCMCS\Services\CurrencyRule\PricingRuleService;
use WCMCS\Services\ExchangeRate\RateService;
use WCMCS\Services\PriceConversionService;

/**
 * @covers \WCMCS\Services\PriceConversionService
 *
 * The core currency conversion math: composing a raw market rate with
 * a store owner's pricing rules (lock / markup / rounding) into the
 * rate and price a shopper actually sees.
 */
class PriceConversionServiceTest extends WcmcsUnitTestCase {

	private function makeService(
		RateService $rateService,
		PricingRuleService $pricingRules
	): PriceConversionService {
		// CacheService itself is a thin transient wrapper already covered
		// by the WpStubs transient stubs — using the real class here
		// exercises the actual cache-or-compute path, not a mock of it.
		return new PriceConversionService( $rateService, $pricingRules, new CacheService() );
	}

	public function test_same_currency_pair_always_has_a_rate_of_one(): void {
		$rateService  = $this->createMock( RateService::class );
		$pricingRules = $this->createMock( PricingRuleService::class );
		$rateService->expects( $this->never() )->method( 'getRate' );

		$service = $this->makeService( $rateService, $pricingRules );

		$this->assertSame( 1.0, $service->getEffectiveRate( 'USD', 'USD' ) );
	}

	public function test_a_locked_rate_is_used_instead_of_the_live_market_rate(): void {
		$rateService = $this->createMock( RateService::class );
		$rateService->expects( $this->never() )->method( 'getRate' );

		$pricingRules = $this->createMock( PricingRuleService::class );
		$pricingRules->method( 'getLockedRate' )->with( 'EUR' )->willReturn( 0.90 );

		$service = $this->makeService( $rateService, $pricingRules );

		$this->assertSame( 0.90, $service->getEffectiveRate( 'USD', 'EUR' ) );
	}

	public function test_markup_is_applied_on_top_of_the_live_rate_when_not_locked(): void {
		$rateService = $this->createMock( RateService::class );
		$rateService->method( 'getRate' )->with( 'USD', 'EUR' )->willReturn( 0.90 );

		$pricingRules = $this->createMock( PricingRuleService::class );
		$pricingRules->method( 'getLockedRate' )->willReturn( null );
		// A 5% markup: 0.90 * 1.05 = 0.945.
		$pricingRules->method( 'applyMarkup' )->with( 'EUR', 0.90 )->willReturn( 0.945 );

		$service = $this->makeService( $rateService, $pricingRules );

		$this->assertSame( 0.945, $service->getEffectiveRate( 'USD', 'EUR' ) );
	}

	public function test_no_live_rate_available_means_no_effective_rate(): void {
		$rateService = $this->createMock( RateService::class );
		$rateService->method( 'getRate' )->willReturn( null );

		$pricingRules = $this->createMock( PricingRuleService::class );
		$pricingRules->method( 'getLockedRate' )->willReturn( null );

		$service = $this->makeService( $rateService, $pricingRules );

		$this->assertNull( $service->getEffectiveRate( 'USD', 'EUR' ) );
	}

	public function test_convert_applies_the_effective_rate_and_the_rounding_rule(): void {
		$rateService = $this->createMock( RateService::class );
		$rateService->method( 'getRate' )->willReturn( 0.90 );

		$pricingRules = $this->createMock( PricingRuleService::class );
		$pricingRules->method( 'getLockedRate' )->willReturn( null );
		$pricingRules->method( 'applyMarkup' )->willReturn( 0.90 );
		// 100 * 0.90 = 90 — the rounding rule then charm-rounds it to 89.99.
		$pricingRules->method( 'roundPrice' )->with( 'EUR', 90.0 )->willReturn( 89.99 );

		$service = $this->makeService( $rateService, $pricingRules );

		$this->assertSame( 89.99, $service->convert( 100, 'USD', 'EUR' ) );
	}

	public function test_convert_returns_null_when_no_rate_is_available_rather_than_a_wrong_number(): void {
		$rateService = $this->createMock( RateService::class );
		$rateService->method( 'getRate' )->willReturn( null );

		$pricingRules = $this->createMock( PricingRuleService::class );
		$pricingRules->method( 'getLockedRate' )->willReturn( null );
		$pricingRules->expects( $this->never() )->method( 'roundPrice' );

		$service = $this->makeService( $rateService, $pricingRules );

		$this->assertNull( $service->convert( 100, 'USD', 'EUR' ) );
	}

	public function test_effective_rate_is_cached_so_a_second_call_does_not_hit_the_rate_service_again(): void {
		$rateService = $this->createMock( RateService::class );
		// exactly once, not twice, despite two calls below.
		$rateService->expects( $this->once() )->method( 'getRate' )->willReturn( 0.90 );

		$pricingRules = $this->createMock( PricingRuleService::class );
		$pricingRules->method( 'getLockedRate' )->willReturn( null );
		$pricingRules->method( 'applyMarkup' )->willReturn( 0.90 );

		$service = $this->makeService( $rateService, $pricingRules );

		$first  = $service->getEffectiveRate( 'USD', 'EUR' );
		$second = $service->getEffectiveRate( 'USD', 'EUR' );

		$this->assertSame( 0.90, $first );
		$this->assertSame( $first, $second );
	}

	public function test_invalidate_clears_the_cached_effective_rate_so_the_next_call_re_fetches(): void {
		$rateService = $this->createMock( RateService::class );
		$rateService->expects( $this->exactly( 2 ) )->method( 'getRate' )->willReturn( 0.90 );

		$pricingRules = $this->createMock( PricingRuleService::class );
		$pricingRules->method( 'getLockedRate' )->willReturn( null );
		$pricingRules->method( 'applyMarkup' )->willReturn( 0.90 );

		$service = $this->makeService( $rateService, $pricingRules );

		$service->getEffectiveRate( 'USD', 'EUR' );
		$service->invalidate( 'USD', 'EUR' );
		$service->getEffectiveRate( 'USD', 'EUR' );
	}
}
