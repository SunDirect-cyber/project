<?php
namespace WCMCS\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the shopper's chosen currency for the duration of their visit.
 *
 * Backed by WooCommerce's own customer session (WC()->session) when it's
 * available, which is the correct place for this — it already handles
 * the session cookie, expiry, and persisting logged-in customers' data.
 * Falls back to a plain cookie only when WC()->session isn't usable yet
 * (e.g. very early on a request, or in a context WooCommerce hasn't
 * initialized for, such as REST/cron), so currency selection still works
 * rather than silently doing nothing.
 */
class SessionService {

	private const KEY        = 'wcmcs_currency';
	private const COOKIE_KEY = 'wcmcs_currency';
	private const COOKIE_TTL = DAY_IN_SECONDS * 30;

	public function getCurrency(): ?string {
		if ( $this->hasWcSession() ) {
			$value = WC()->session->get( self::KEY );

			return $value ? (string) $value : null;
		}

		return isset( $_COOKIE[ self::COOKIE_KEY ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_KEY ] ) ) : null;
	}

	public function setCurrency( string $code ): void {
		$code = strtoupper( trim( $code ) );

		if ( $this->hasWcSession() ) {
			WC()->session->set( self::KEY, $code );
		}

		// Set the cookie either way: it's what lets us recover the choice
		// on a request early enough that WC()->session isn't ready yet.
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE_KEY, $code, time() + self::COOKIE_TTL, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
		}
	}

	public function clearCurrency(): void {
		if ( $this->hasWcSession() ) {
			WC()->session->set( self::KEY, null );
		}

		if ( ! headers_sent() ) {
			setcookie( self::COOKIE_KEY, '', time() - HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
		}
	}

	private function hasWcSession(): bool {
		return function_exists( 'WC' ) && WC()->session instanceof \WC_Session;
	}
}
