<?php
/**
 * Bootstrap for the *unit* test suite only (tests/Unit) — deliberately
 * not a WordPress test environment. These tests exercise this plugin's
 * core logic classes in isolation, with just enough of the handful of
 * WordPress functions those classes actually call stubbed out to make
 * them loadable — no database, no HTTP, no wp-load.php, so the whole
 * suite runs in well under a second and can run in any CI job with
 * nothing but PHP and Composer installed.
 *
 * WordPress *integration* tests (tests/Integration, using the real
 * WP_UnitTestCase framework against a live WordPress + WooCommerce +
 * MySQL environment) are a deliberately separate test suite with their
 * own bootstrap — see tests/Integration/bootstrap.php and
 * docs/testing.md for why the two can't share one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}

if ( ! defined( 'WCMCS_PATH' ) ) {
	define( 'WCMCS_PATH', dirname( __DIR__ ) . '/' );
}

// A real (not the wp-config.php sample placeholder), 32+ char secret —
// EncryptionServiceTest exercises both the "real salt present" and
// "still the placeholder" code paths explicitly, so this is only the
// default for tests that don't care about that distinction.
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'unit-test-auth-key-not-a-real-secret-1234567890' );
}
if ( ! defined( 'AUTH_SALT' ) ) {
	define( 'AUTH_SALT', 'unit-test-auth-salt-not-a-real-secret-0987654321' );
}

require_once __DIR__ . '/Unit/WpStubs.php';

$composerAutoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( file_exists( $composerAutoload ) ) {
	require_once $composerAutoload;
}

require_once dirname( __DIR__ ) . '/includes/Core/Autoloader.php';
( new WCMCS\Core\Autoloader() )->register();
