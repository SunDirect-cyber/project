<?php
namespace WCMCS\Tests\Unit;

use WCMCS\Compat\VendorDashboardDetector;

/**
 * @covers \WCMCS\Compat\VendorDashboardDetector
 *
 * Regression coverage for the vendor-dashboard price-corruption bug
 * found during third-party compatibility work: a multi-vendor
 * marketplace's product-edit dashboard runs on the front end, so
 * without this detector PriceConverter would treat it as an ordinary
 * shopping page and show a vendor a converted price inside their own
 * price input field.
 */
class VendorDashboardDetectorTest extends WcmcsUnitTestCase {

	public function test_returns_false_when_no_vendor_plugin_is_active(): void {
		$this->assertFalse( VendorDashboardDetector::isVendorDashboard() );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_detects_a_dokan_seller_dashboard_request(): void {
		eval( 'function dokan_is_seller_dashboard() { return true; }' );

		$this->assertTrue( VendorDashboardDetector::isVendorDashboard() );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_dokan_present_but_not_on_the_dashboard_is_not_flagged(): void {
		eval( 'function dokan_is_seller_dashboard() { return false; }' );

		$this->assertFalse( VendorDashboardDetector::isVendorDashboard() );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_detects_a_wcfm_dashboard_request(): void {
		define( 'WCFM_VERSION', '6.9.0' );
		$GLOBALS['WCFM'] = new \stdClass();

		eval( 'function get_query_var( $name, $default = "" ) { return "wcfm-view" === $name ? "products" : $default; }' );

		$this->assertTrue( VendorDashboardDetector::isVendorDashboard() );
	}
}
