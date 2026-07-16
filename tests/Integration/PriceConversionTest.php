<?php
namespace WCMCS\Tests\Integration;

/**
 * Verifies prices actually render correctly across product types
 * against a real WooCommerce install — not achievable with the
 * tests/Unit suite, which stubs WordPress/WooCommerce entirely and so
 * can't observe get_price()/get_price_html() actually going through
 * WooCommerce's real filter pipeline.
 *
 * Not runnable in this sandbox (no WordPress/WooCommerce/MySQL
 * available here) — see tests/Integration/bootstrap.php and
 * docs/testing.md. Written to the same conventions WooCommerce core's
 * own test suite uses (WC_Helper_Product, WC_Unit_Test_Case) so it
 * runs unmodified once WP_TESTS_DIR is set up.
 */
class PriceConversionTest extends \WC_Unit_Test_Case {

	private array $createdProductIds = array();

	public function tearDown(): void {
		foreach ( $this->createdProductIds as $productId ) {
			wp_delete_post( $productId, true );
		}
		$this->createdProductIds = array();

		delete_option( 'wcmcs_enabled_currencies' );
		update_option( 'woocommerce_currency', 'USD' );

		parent::tearDown();
	}

	private function enableEurAndSwitchToIt(): void {
		update_option( 'wcmcs_enabled_currencies', array( 'USD', 'EUR' ) );

		/** @var \WCMCS\Services\CurrencyPersistenceService $persistence */
		$persistence = \WCMCS\Core\Plugin::instance()->container()->get( 'currency_persistence_service' );
		$persistence->setCurrency( 'EUR', \WCMCS\Services\SessionService::SOURCE_MANUAL );
	}

	public function test_a_simple_products_price_is_converted_for_the_active_currency(): void {
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '100.00' );
		$product->save();
		$this->createdProductIds[] = $product->get_id();

		$this->enableEurAndSwitchToIt();

		$reloaded = wc_get_product( $product->get_id() );

		$this->assertNotSame( '100.00', $reloaded->get_price() );
		$this->assertGreaterThan( 0, (float) $reloaded->get_price() );
	}

	public function test_a_variable_products_price_range_is_converted(): void {
		$product = \WC_Helper_Product::create_variation_product();
		$this->createdProductIds[] = $product->get_id();

		foreach ( $product->get_children() as $variationId ) {
			$this->createdProductIds[] = $variationId;
		}

		$this->enableEurAndSwitchToIt();

		$reloaded = wc_get_product( $product->get_id() );
		$prices   = $reloaded->get_variation_prices( true );

		$this->assertNotEmpty( $prices['price'] );

		foreach ( $prices['price'] as $convertedPrice ) {
			$this->assertGreaterThan( 0, (float) $convertedPrice );
		}
	}

	public function test_switching_back_to_the_base_currency_restores_the_original_price(): void {
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '49.99' );
		$product->save();
		$this->createdProductIds[] = $product->get_id();

		$this->enableEurAndSwitchToIt();

		/** @var \WCMCS\Services\CurrencyPersistenceService $persistence */
		$persistence = \WCMCS\Core\Plugin::instance()->container()->get( 'currency_persistence_service' );
		$persistence->setCurrency( 'USD', \WCMCS\Services\SessionService::SOURCE_MANUAL );

		$reloaded = wc_get_product( $product->get_id() );

		$this->assertSame( '49.99', $reloaded->get_price() );
	}

	public function test_a_zero_price_free_product_is_never_converted_or_charm_rounded(): void {
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '0' );
		$product->save();
		$this->createdProductIds[] = $product->get_id();

		$this->enableEurAndSwitchToIt();

		$reloaded = wc_get_product( $product->get_id() );

		// Must stay exactly free — converting/rounding a 0 price is the
		// classic bug this plugin explicitly guards against elsewhere
		// (see PriceConverter::filter_price()).
		$this->assertSame( '0', $reloaded->get_price() );
	}
}
