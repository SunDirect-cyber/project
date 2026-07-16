<?php
namespace WCMCS\Tests\Unit;

use WCMCS\Compat\CachingPluginCompat;
use WCMCS\Core\Plugin;

/**
 * @covers \WCMCS\Compat\CachingPluginCompat
 *
 * Regression coverage for the cache-poisoning scenario named in the
 * plugin's spec: a page-cache plugin must never serve one visitor's
 * currency-converted page to a different visitor browsing in a
 * different currency.
 */
class CachingPluginCompatTest extends WcmcsUnitTestCase {

	private function registerFakePersistence( ?string $currency ): void {
		$persistence = $this->createMock( \WCMCS\Services\CurrencyPersistenceService::class );
		$persistence->method( 'getCurrency' )->willReturn( $currency );

		Plugin::instance()->container()->set( 'currency_persistence_service', static fn () => $persistence );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_a_visitor_with_no_active_currency_selection_is_not_excluded_from_cache(): void {
		$this->registerFakePersistence( null );

		CachingPluginCompat::maybe_exclude_from_cache();

		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_a_visitor_with_an_active_currency_selection_is_excluded_from_every_known_cache_plugin(): void {
		$this->registerFakePersistence( 'EUR' );

		CachingPluginCompat::maybe_exclude_from_cache();

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && true === \DONOTCACHEPAGE );

		$rocketCookies = apply_filters( 'rocket_cache_reject_cookies', array() );
		$this->assertContains( 'wcmcs_currency', $rocketCookies );

		$w3tcReason = apply_filters( 'w3tc_pagecache_reject_reason', '' );
		$this->assertNotSame( '', $w3tcReason );

		global $cache_no_cache;
		$this->assertSame( 1, $cache_no_cache );
	}
}
