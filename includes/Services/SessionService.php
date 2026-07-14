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

	private const KEY          = 'wcmcs_currency';
	private const SOURCE_KEY   = 'wcmcs_currency_source';
	private const COOKIE_KEY   = 'wcmcs_currency';
	private const SOURCE_COOKIE_KEY = 'wcmcs_currency_source';
	private const COOKIE_TTL   = DAY_IN_SECONDS * 30;

	public const SOURCE_MANUAL = 'manual';
	public const SOURCE_AUTO   = 'auto';
	public const SOURCE_URL    = 'url';

	public function getCurrency(): ?string {
		if ( $this->hasWcSession() ) {
			$value = WC()->session->get( self::KEY );

			return $value ? (string) $value : null;
		}

		return isset( $_COOKIE[ self::COOKIE_KEY ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_KEY ] ) ) : null;
	}

	/**
	 * $source records *how* this currency was chosen (a manual pick from
	 * the switcher widget vs. auto-detected vs. a ?currency= URL param) —
	 * used by the conflict-resolution logic to decide which signal wins
	 * when more than one is present.
	 */
	public function setCurrency( string $code, string $source = self::SOURCE_MANUAL ): void {
		$code = strtoupper( trim( $code ) );

		if ( $this->hasWcSession() ) {
			WC()->session->set( self::KEY, $code );
			WC()->session->set( self::SOURCE_KEY, $source );
		}

		// Set the cookie either way: it's what lets us recover the choice
		// on a request early enough that WC()->session isn't ready yet.
		if ( ! headers_sent() ) {
			$expires = time() + self::COOKIE_TTL;
			setcookie( self::COOKIE_KEY, $code, $expires, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
			setcookie( self::SOURCE_COOKIE_KEY, $source, $expires, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
		}
	}

	public function getSource(): ?string {
		if ( $this->hasWcSession() ) {
			$value = WC()->session->get( self::SOURCE_KEY );

			return $value ? (string) $value : null;
		}

		return isset( $_COOKIE[ self::SOURCE_COOKIE_KEY ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::SOURCE_COOKIE_KEY ] ) ) : null;
	}

	public function clearCurrency(): void {
		if ( $this->hasWcSession() ) {
			WC()->session->set( self::KEY, null );
			WC()->session->set( self::SOURCE_KEY, null );
		}

		if ( ! headers_sent() ) {
			$expired = time() - HOUR_IN_SECONDS;
			setcookie( self::COOKIE_KEY, '', $expired, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
			setcookie( self::SOURCE_COOKIE_KEY, '', $expired, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
		}
	}

	private function hasWcSession(): bool {
		return function_exists( 'WC' ) && WC()->session instanceof \WC_Session;
	}
}
