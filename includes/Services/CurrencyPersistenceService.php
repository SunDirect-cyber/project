<?php
declare( strict_types=1 );

namespace WCMCS\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The multi-layer persistence the spec calls for: a logged-in shopper's
 * currency choice is saved to user meta, so it follows them to any
 * device they log into, while a guest's choice only ever lives in
 * SessionService (WC session + cookie), since there's no account to
 * attach it to.
 *
 * This is the one place other code should call to read or write "the
 * visitor's chosen currency" — nothing else should touch user meta or
 * SessionService directly for this purpose.
 */
class CurrencyPersistenceService {

	private const USER_META_CURRENCY = 'wcmcs_currency';
	private const USER_META_SOURCE   = 'wcmcs_currency_source';

	private SessionService $session;

	public function __construct( SessionService $session ) {
		$this->session = $session;
	}

	public function getCurrency(): ?string {
		if ( is_user_logged_in() ) {
			$saved = get_user_meta( get_current_user_id(), self::USER_META_CURRENCY, true );

			if ( ! empty( $saved ) ) {
				return strtoupper( (string) $saved );
			}
		}

		return $this->session->getCurrency();
	}

	public function getSource(): ?string {
		if ( is_user_logged_in() ) {
			$saved = get_user_meta( get_current_user_id(), self::USER_META_CURRENCY, true );

			if ( ! empty( $saved ) ) {
				$source = get_user_meta( get_current_user_id(), self::USER_META_SOURCE, true );
				return $source ? (string) $source : SessionService::SOURCE_MANUAL;
			}
		}

		return $this->session->getSource();
	}

	/**
	 * The single place a currency switch actually happens, regardless of
	 * which of this plugin's own entry points triggered it (the switcher
	 * widget's AJAX call, a ?currency= link, geolocation auto-detection).
	 * Centralizing it here — rather than each caller firing its own
	 * do_action() — is what guarantees the wcmcs_currency_switched hook
	 * fires for every switch path, including geolocation auto-detection,
	 * which used to call setCurrency() directly with no hook at all.
	 */
	public function setCurrency( string $code, string $source = SessionService::SOURCE_MANUAL ): void {
		$code     = strtoupper( trim( $code ) );
		$previous = $this->getCurrency();

		if ( $previous === $code ) {
			return; // Not actually a switch — nothing to persist or announce.
		}

		/**
		 * Fires immediately before a currency switch is persisted.
		 * Returning here doesn't cancel the switch (this plugin doesn't
		 * support cancelling one) — this is for side effects (logging,
		 * syncing to an external system) that need to see the *previous*
		 * currency before it's overwritten.
		 *
		 * @param string      $code     The currency about to become active.
		 * @param string      $source   One of SessionService::SOURCE_*.
		 * @param string|null $previous The currency being switched away from.
		 */
		do_action( 'wcmcs_before_currency_switch', $code, $source, $previous );

		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::USER_META_CURRENCY, $code );
			update_user_meta( get_current_user_id(), self::USER_META_SOURCE, $source );
		}

		// Always also keep the session/cookie in sync — it's what a
		// logged-in user's *next* request reads before user meta is
		// consulted, and it's the only storage a guest has at all.
		$this->session->setCurrency( $code, $source );

		/**
		 * Fires immediately after a currency switch has been persisted.
		 * This is the plugin's original currency-switch hook, kept under
		 * its existing name for backwards compatibility with any
		 * integration already using it — wcmcs_before_currency_switch
		 * above is the new addition, not a replacement.
		 *
		 * @param string      $code     The now-active currency.
		 * @param string      $source   One of SessionService::SOURCE_*.
		 * @param string|null $previous The currency that was switched away from.
		 */
		do_action( 'wcmcs_currency_switched', $code, $source, $previous );
	}

	/**
	 * Runs on login: a guest who picked a currency before creating an
	 * account/logging in shouldn't lose that choice, but an existing
	 * saved preference on the account always wins over whatever an
	 * anonymous session happened to have — logging into an account you
	 * already use in USD shouldn't suddenly flip it to EUR just because
	 * this particular browser session had EUR selected.
	 */
	public function migrateGuestSessionToUser( int $userId ): void {
		$existing = get_user_meta( $userId, self::USER_META_CURRENCY, true );

		if ( ! empty( $existing ) ) {
			return;
		}

		$guestCurrency = $this->session->getCurrency();

		if ( null === $guestCurrency ) {
			return;
		}

		update_user_meta( $userId, self::USER_META_CURRENCY, $guestCurrency );
		update_user_meta( $userId, self::USER_META_SOURCE, $this->session->getSource() ?? SessionService::SOURCE_MANUAL );
	}
}
