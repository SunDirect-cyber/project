<?php
namespace WCMCS\Services\Geo;

use WCMCS\Services\CurrencyPersistenceService;
use WCMCS\Services\CurrencyService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backs the optional "Switch to EUR?" confirmation modal. Only relevant
 * when the store owner has confirmation mode on (default is silent
 * auto-switching) — in that mode, a first-time visitor's geo-detected
 * currency isn't applied automatically; it's offered as a dismissible
 * suggestion instead, and only takes effect if they actively confirm it.
 */
class GeoSuggestionService {

	public const DISMISS_COOKIE = 'wcmcs_geo_dismissed';
	private const DISMISS_TTL   = DAY_IN_SECONDS * 30;

	private GeoCurrencyResolver $geoResolver;
	private CurrencyPersistenceService $persistence;
	private CurrencyService $currencyService;

	public function __construct( GeoCurrencyResolver $geoResolver, CurrencyPersistenceService $persistence, CurrencyService $currencyService ) {
		$this->geoResolver     = $geoResolver;
		$this->persistence     = $persistence;
		$this->currencyService = $currencyService;
	}

	public static function isConfirmationModeEnabled(): bool {
		return ! empty( get_option( 'wcmcs_currency_switch_confirmation', false ) );
	}

	/**
	 * @return array{country: string, currency: string}|null Null when there's nothing worth suggesting.
	 */
	public function getSuggestion(): ?array {
		// A currency is already set (manual, url, or a previous auto
		// choice) — nothing to suggest, there's no conflict to surface.
		if ( null !== $this->persistence->getCurrency() ) {
			return null;
		}

		if ( $this->hasDismissed() ) {
			return null;
		}

		$result = $this->geoResolver->resolve();

		if ( ! $result['detected'] || null === $result['country'] ) {
			return null;
		}

		if ( $result['currency'] === $this->currencyService->baseCurrency()->code() ) {
			return null;
		}

		return array(
			'country'  => $result['country'],
			'currency' => $result['currency'],
		);
	}

	public function hasDismissed(): bool {
		return ! empty( $_COOKIE[ self::DISMISS_COOKIE ] );
	}

	public function dismiss(): void {
		if ( ! headers_sent() ) {
			setcookie( self::DISMISS_COOKIE, '1', time() + self::DISMISS_TTL, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
		}
	}
}
