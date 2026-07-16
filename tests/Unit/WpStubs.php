<?php
/**
 * Minimal WordPress function stubs for the unit test suite. Backed by
 * plain global arrays rather than any mocking framework, so there's no
 * extra dependency beyond PHPUnit itself. Call wcmcs_test_reset_stubs()
 * from a test's setUp() to guarantee no state leaks between tests —
 * see tests/Unit/WcmcsUnitTestCase.php, which every test in this suite
 * extends specifically so nobody has to remember to call it by hand.
 *
 * This file intentionally implements only what the classes actually
 * under test call — it is not, and isn't meant to become, a general
 * WordPress stub library. If a new unit test needs a function that
 * isn't here yet, add the smallest faithful stub that makes the real
 * class's behavior observable, not a generic mock.
 */

if ( ! function_exists( 'wcmcs_test_reset_stubs' ) ) {
	function wcmcs_test_reset_stubs(): void {
		$GLOBALS['wcmcs_test_options']    = array();
		$GLOBALS['wcmcs_test_filters']    = array();
		$GLOBALS['wcmcs_test_actions']    = array();
		$GLOBALS['wcmcs_test_transients'] = array();
	}
}

wcmcs_test_reset_stubs();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['wcmcs_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value ): bool {
		$GLOBALS['wcmcs_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['wcmcs_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10 ): bool {
		$GLOBALS['wcmcs_test_filters'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['wcmcs_test_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10 ): bool {
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['wcmcs_test_actions'][] = array( $hook, $args );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		$entry = $GLOBALS['wcmcs_test_transients'][ $key ] ?? null;

		if ( null === $entry ) {
			return false;
		}

		if ( $entry['expires_at'] < time() ) {
			unset( $GLOBALS['wcmcs_test_transients'][ $key ] );
			return false;
		}

		return $entry['value'];
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttlSeconds ): bool {
		$GLOBALS['wcmcs_test_transients'][ $key ] = array(
			'value'      => $value,
			'expires_at' => time() + $ttlSeconds,
		);
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['wcmcs_test_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, int $gmt = 0 ) {
		if ( 'timestamp' === $type ) {
			return time();
		}

		if ( 'mysql' === $type ) {
			return gmdate( 'Y-m-d H:i:s' );
		}

		return gmdate( $type );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( string $hook ) {
		return false;
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {
		$GLOBALS['wcmcs_test_nocache_headers_called'] = true;
	}
}
