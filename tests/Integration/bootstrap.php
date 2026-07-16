<?php
/**
 * Bootstrap for the WordPress *integration* test suite — a genuinely
 * different tier from tests/Unit (see tests/bootstrap.php and
 * docs/testing.md): these tests run against the real WordPress test
 * framework (WP_UnitTestCase), a real WooCommerce install, and a real
 * (throwaway) MySQL database, verifying things a stubbed unit test
 * fundamentally can't — that PriceConverter's filters actually change
 * what get_price() returns on a live WC_Product, that a variable
 * product's price range is genuinely correct after conversion, that
 * checkout really creates an order in the right currency.
 *
 * This requires the standard WordPress PHPUnit test scaffold to be
 * present (WP_TESTS_DIR pointing at wordpress-develop's tests/phpunit,
 * plus a WP_TESTS_DOMAIN-reachable database) — normally set up via
 * `wp scaffold plugin-tests` and the bin/install-wp-tests.sh script it
 * generates. This sandbox has no WordPress install, no MySQL, and no
 * network access to download WordPress core, so this suite is not
 * runnable here — it's included so CI (or a developer's local
 * environment, which does have all of the above) can run it as-is. See
 * docs/testing.md for exact setup steps.
 */

$testsDir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $testsDir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite at \"{$testsDir}/includes/functions.php\".\n";
	echo "Run bin/install-wp-tests.sh first (see docs/testing.md) or set WP_TESTS_DIR.\n";
	exit( 1 );
}

require_once $testsDir . '/includes/functions.php';

/**
 * Loads this plugin (and WooCommerce, which must be present in the test
 * install's wp-content/plugins) before WordPress finishes bootstrapping,
 * exactly like a real site loading it via the normal plugin lifecycle.
 */
function _wcmcs_manually_load_plugin(): void {
	require dirname( __DIR__, 2 ) . '/wc-multicurrency-switcher.php';
}
tests_add_filter( 'muplugins_loaded', '_wcmcs_manually_load_plugin' );

require $testsDir . '/includes/bootstrap.php';
