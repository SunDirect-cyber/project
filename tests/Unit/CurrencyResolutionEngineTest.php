<?php
namespace WCMCS\Tests\Unit;

use WCMCS\Services\Analytics\EventTracker;
use WCMCS\Services\CurrencyPersistenceService;
use WCMCS\Services\CurrencyResolutionEngine;
use WCMCS\Services\Geo\GeoCurrencyResolver;
use WCMCS\Services\SessionService;

/**
 * @covers \WCMCS\Services\CurrencyResolutionEngine
 *
 * This is the plugin's "conflict resolution" logic: geolocation, a
 * previously auto-detected value, a manual pick, and a ?currency= link
 * can all disagree about what a visitor's currency should be, and this
 * class is the one place that decides who wins. See its own docblock
 * for the exact precedence rule these tests verify.
 */
class CurrencyResolutionEngineTest extends WcmcsUnitTestCase {

	private function makeEngine(
		CurrencyPersistenceService $persistence,
		GeoCurrencyResolver $geoResolver
	): CurrencyResolutionEngine {
		$eventTracker = $this->createMock( EventTracker::class );

		return new CurrencyResolutionEngine( $persistence, $geoResolver, $eventTracker );
	}

	public function test_a_manually_chosen_currency_is_never_touched(): void {
		$persistence = $this->createMock( CurrencyPersistenceService::class );
		$persistence->method( 'getCurrency' )->willReturn( 'EUR' );
		$persistence->method( 'getSource' )->willReturn( SessionService::SOURCE_MANUAL );
		$persistence->expects( $this->never() )->method( 'setCurrency' );

		$geoResolver = $this->createMock( GeoCurrencyResolver::class );
		$geoResolver->expects( $this->never() )->method( 'resolve' );

		$this->makeEngine( $persistence, $geoResolver )->resolve();
	}

	public function test_a_currency_chosen_via_url_link_is_never_touched(): void {
		$persistence = $this->createMock( CurrencyPersistenceService::class );
		$persistence->method( 'getCurrency' )->willReturn( 'GBP' );
		$persistence->method( 'getSource' )->willReturn( SessionService::SOURCE_URL );
		$persistence->expects( $this->never() )->method( 'setCurrency' );

		$geoResolver = $this->createMock( GeoCurrencyResolver::class );
		$geoResolver->expects( $this->never() )->method( 'resolve' );

		$this->makeEngine( $persistence, $geoResolver )->resolve();
	}

	public function test_an_auto_detected_currency_is_left_alone_under_remember_mode(): void {
		update_option( 'wcmcs_currency_remember_mode', CurrencyResolutionEngine::MODE_REMEMBER );

		$persistence = $this->createMock( CurrencyPersistenceService::class );
		$persistence->method( 'getCurrency' )->willReturn( 'JPY' );
		$persistence->method( 'getSource' )->willReturn( SessionService::SOURCE_AUTO );
		$persistence->expects( $this->never() )->method( 'setCurrency' );

		$geoResolver = $this->createMock( GeoCurrencyResolver::class );
		$geoResolver->expects( $this->never() )->method( 'resolve' );

		$this->makeEngine( $persistence, $geoResolver )->resolve();
	}

	public function test_an_auto_detected_currency_is_re_resolved_under_always_detect_mode(): void {
		update_option( 'wcmcs_currency_remember_mode', CurrencyResolutionEngine::MODE_ALWAYS_DETECT );

		$persistence = $this->createMock( CurrencyPersistenceService::class );
		$persistence->method( 'getCurrency' )->willReturn( 'JPY' );
		$persistence->method( 'getSource' )->willReturn( SessionService::SOURCE_AUTO );
		$persistence->expects( $this->once() )
			->method( 'setCurrency' )
			->with( 'GBP', SessionService::SOURCE_AUTO );

		$geoResolver = $this->createMock( GeoCurrencyResolver::class );
		$geoResolver->method( 'resolve' )->willReturn( array( 'country' => 'GB', 'currency' => 'GBP', 'detected' => true ) );

		$this->makeEngine( $persistence, $geoResolver )->resolve();
	}

	public function test_a_first_time_visitor_with_nothing_persisted_gets_auto_detection_applied(): void {
		update_option( 'wcmcs_currency_switch_confirmation', false );

		$persistence = $this->createMock( CurrencyPersistenceService::class );
		$persistence->method( 'getCurrency' )->willReturn( null );
		$persistence->expects( $this->once() )
			->method( 'setCurrency' )
			->with( 'EUR', SessionService::SOURCE_AUTO );

		$geoResolver = $this->createMock( GeoCurrencyResolver::class );
		$geoResolver->method( 'resolve' )->willReturn( array( 'country' => 'DE', 'currency' => 'EUR', 'detected' => true ) );

		$this->makeEngine( $persistence, $geoResolver )->resolve();
	}

	public function test_a_first_time_visitor_under_confirmation_mode_is_not_auto_applied(): void {
		update_option( 'wcmcs_currency_switch_confirmation', true );

		$persistence = $this->createMock( CurrencyPersistenceService::class );
		$persistence->method( 'getCurrency' )->willReturn( null );
		$persistence->expects( $this->never() )->method( 'setCurrency' );

		$geoResolver = $this->createMock( GeoCurrencyResolver::class );
		$geoResolver->expects( $this->never() )->method( 'resolve' );

		$this->makeEngine( $persistence, $geoResolver )->resolve();
	}

	public function test_mode_defaults_to_remember_for_an_unrecognized_stored_value(): void {
		update_option( 'wcmcs_currency_remember_mode', 'some_future_mode_this_version_does_not_know' );

		$this->assertSame( CurrencyResolutionEngine::MODE_REMEMBER, CurrencyResolutionEngine::mode() );
	}
}
