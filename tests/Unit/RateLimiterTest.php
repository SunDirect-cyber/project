<?php
namespace WCMCS\Tests\Unit;

use WCMCS\Core\RateLimiter;

/**
 * @covers \WCMCS\Core\RateLimiter
 */
class RateLimiterTest extends WcmcsUnitTestCase {

	public function test_first_attempt_is_allowed(): void {
		$this->assertTrue( RateLimiter::attempt( 'test_action', 'user-1', 30 ) );
	}

	public function test_a_second_attempt_within_the_window_is_blocked(): void {
		RateLimiter::attempt( 'test_action', 'user-1', 30 );

		$this->assertFalse( RateLimiter::attempt( 'test_action', 'user-1', 30 ) );
	}

	public function test_different_identifiers_do_not_interfere_with_each_other(): void {
		RateLimiter::attempt( 'test_action', 'user-1', 30 );

		$this->assertTrue( RateLimiter::attempt( 'test_action', 'user-2', 30 ) );
	}

	public function test_different_actions_for_the_same_identifier_do_not_interfere(): void {
		RateLimiter::attempt( 'action_a', 'user-1', 30 );

		$this->assertTrue( RateLimiter::attempt( 'action_b', 'user-1', 30 ) );
	}

	public function test_works_with_an_integer_identifier_as_well_as_a_string_one(): void {
		RateLimiter::attempt( 'test_action', 42, 30 );

		$this->assertFalse( RateLimiter::attempt( 'test_action', 42, 30 ) );
	}

	public function test_retry_after_is_zero_when_no_attempt_has_been_recorded(): void {
		$this->assertSame( 0, RateLimiter::retryAfter( 'never_attempted', 'user-1', 30 ) );
	}

	public function test_retry_after_reports_a_positive_value_within_the_window(): void {
		RateLimiter::attempt( 'test_action', 'user-1', 30 );

		$retryAfter = RateLimiter::retryAfter( 'test_action', 'user-1', 30 );

		$this->assertGreaterThan( 0, $retryAfter );
		$this->assertLessThanOrEqual( 30, $retryAfter );
	}

	public function test_an_ip_address_style_identifier_works_for_a_public_endpoint(): void {
		RateLimiter::attempt( 'store_api_convert', '203.0.113.7', 2 );

		$this->assertFalse( RateLimiter::attempt( 'store_api_convert', '203.0.113.7', 2 ) );
		$this->assertTrue( RateLimiter::attempt( 'store_api_convert', '203.0.113.8', 2 ) );
	}
}
