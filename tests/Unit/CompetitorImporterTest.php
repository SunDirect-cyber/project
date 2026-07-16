<?php
namespace WCMCS\Tests\Unit;

use WCMCS\Compat\Migration\CompetitorImporter;
use WCMCS\Core\Plugin;
use WCMCS\Services\Currency\CurrencyRepository;

/**
 * @covers \WCMCS\Compat\Migration\CompetitorImporter
 */
class CompetitorImporterTest extends WcmcsUnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// CompetitorImporter validates every imported code against the
		// real currency dataset via this service — registering the real
		// (dependency-free) CurrencyRepository here, exactly as
		// production's DI container does, is what makes the "ZZZ isn't a
		// real currency, so drop it" behavior actually exercised rather
		// than silently skipped.
		if ( ! Plugin::instance()->container()->has( 'currency_repository' ) ) {
			Plugin::instance()->container()->set( 'currency_repository', static fn () => new CurrencyRepository() );
		}
	}

	public function test_detects_nothing_when_no_competitor_data_is_present(): void {
		$this->assertSame( array(), CompetitorImporter::detectAvailable() );
	}

	public function test_woocs_preview_extracts_valid_codes_and_drops_invalid_ones(): void {
		update_option(
			'WOOCS_CURRENCIES',
			array(
				'USD' => array( 'name' => 'US Dollar', 'is_default' => 1 ),
				'EUR' => array( 'name' => 'Euro' ),
				'ZZZ' => array( 'name' => 'Not a real currency' ),
			)
		);

		$preview = CompetitorImporter::preview( CompetitorImporter::PLUGIN_WOOCS );

		$this->assertSame( array( 'USD', 'EUR' ), $preview['currencies'] );
		$this->assertSame( 'USD', $preview['default_currency'] );
	}

	public function test_curcy_preview_parses_a_comma_separated_string(): void {
		update_option( 'alg_wc_currency_switcher_currencies', 'USD, GBP, notacode, jpy' );

		$preview = CompetitorImporter::preview( CompetitorImporter::PLUGIN_CURCY );

		$this->assertSame( array( 'USD', 'GBP', 'JPY' ), $preview['currencies'] );
	}

	public function test_aelia_preview_parses_a_nested_settings_array(): void {
		update_option( 'wc_aelia_currency_switcher', array( 'enabled_currencies' => array( 'AUD', 'INR' ) ) );

		$preview = CompetitorImporter::preview( CompetitorImporter::PLUGIN_AELIA );

		$this->assertSame( array( 'AUD', 'INR' ), $preview['currencies'] );
	}

	public function test_import_merges_into_existing_enabled_currencies_rather_than_replacing_them(): void {
		update_option( 'wcmcs_enabled_currencies', array( 'USD' ) );
		update_option( 'WOOCS_CURRENCIES', array( 'USD' => array(), 'EUR' => array() ) );

		$result = CompetitorImporter::import( CompetitorImporter::PLUGIN_WOOCS );

		$this->assertSame( 2, $result['imported_count'] );

		$stored = get_option( 'wcmcs_enabled_currencies' );
		sort( $stored );
		$this->assertSame( array( 'EUR', 'USD' ), $stored );
	}

	public function test_import_is_a_no_op_when_nothing_is_found(): void {
		$result = CompetitorImporter::import( CompetitorImporter::PLUGIN_CURCY );

		$this->assertSame( 0, $result['imported_count'] );
		$this->assertFalse( get_option( 'wcmcs_enabled_currencies' ) );
	}

	public function test_detect_available_finds_a_plugin_once_its_data_is_present(): void {
		update_option( 'alg_wc_currency_switcher_currencies', 'USD,EUR' );

		$this->assertSame( array( CompetitorImporter::PLUGIN_CURCY ), CompetitorImporter::detectAvailable() );
	}
}
