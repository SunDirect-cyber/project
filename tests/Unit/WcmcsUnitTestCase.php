<?php
namespace WCMCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Base class every unit test in this suite extends — guarantees the
 * WordPress function stubs' state (options, filters, transients) is
 * reset before each test, so tests can never leak state into each
 * other regardless of run order.
 */
abstract class WcmcsUnitTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		wcmcs_test_reset_stubs();
	}
}
