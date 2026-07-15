<?php
namespace WCMCS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saves the allow-list of origins the public, unauthenticated headless
 * storefront endpoints (StoreApiController) will send CORS headers for.
 */
class ApiAccessAjaxController {

	public const NONCE = 'wcmcs_headless_origins_nonce';

	public static function register(): void {
		add_action( 'wp_ajax_wcmcs_save_headless_origins', array( self::class, 'handle_save' ) );
	}

	public static function handle_save(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wc-multicurrency-switcher' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		$raw = isset( $_POST['origins'] ) ? wp_unslash( $_POST['origins'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$lines = array_filter( array_map( 'trim', explode( "\n", (string) $raw ) ) );

		$valid   = array();
		$invalid = array();

		foreach ( $lines as $line ) {
			// An "origin" is scheme + host (+ optional port), never a
			// path/query — validated with esc_url_raw plus an explicit
			// scheme check, so a malformed value can't end up sent back
			// verbatim in an Access-Control-Allow-Origin header later.
			$sanitized = esc_url_raw( $line );

			if ( '' !== $sanitized && preg_match( '#^https?://[^/]+$#', rtrim( $sanitized, '/' ) ) ) {
				$valid[] = rtrim( $sanitized, '/' );
			} else {
				$invalid[] = $line;
			}
		}

		if ( ! empty( $invalid ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: comma-separated list of invalid origins */
						__( 'These lines aren\'t valid origins (scheme + host only, e.g. https://shop.example.com): %s', 'wc-multicurrency-switcher' ),
						implode( ', ', $invalid )
					),
				)
			);
		}

		$valid = array_values( array_unique( $valid ) );

		update_option( 'wcmcs_headless_allowed_origins', implode( "\n", $valid ) );

		wp_send_json_success( array( 'origins' => $valid ) );
	}
}
