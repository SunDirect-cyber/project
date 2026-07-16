<?php
declare( strict_types=1 );

namespace WCMCS\Services;

use WCMCS\Services\Analytics\EventTracker;
use WCMCS\Services\Geo\GeoCurrencyResolver;
use WCMCS\Services\Geo\GeoSuggestionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which of the plugin's several currency signals actually wins
 * for this visitor, and persists that decision — the "Conflict
 * Resolution Engine": geolocation, a stored auto-detected value, a
 * manual pick, and a ?currency= link can all disagree, and there has to
 * be one unambiguous rule for who wins.
 *
 * The rule, in order:
 *  1. A currency already persisted with source "manual" or "url" always
 *     wins and is never touched here again — a shopper who explicitly
 *     chose EUR should never have that silently reverted because
 *     geolocation (or a VPN) now thinks they're somewhere else.
 *  2. A currency already persisted with source "auto" is left alone
 *     too, UNLESS the store is configured for "always auto-detect"
 *     (as opposed to the default "remember my currency") — only in that
 *     mode does an existing auto-detected value get re-evaluated on a
 *     later visit.
 *  3. Nothing persisted yet: run detection and persist the result as
 *     "auto" — unless confirmation mode is on, in which case detection
 *     still runs (via GeoSuggestionService) but isn't applied until the
 *     shopper actively confirms it in the modal.
 */
class CurrencyResolutionEngine {

	public const MODE_REMEMBER      = 'remember';
	public const MODE_ALWAYS_DETECT = 'always_detect';

	private CurrencyPersistenceService $persistence;
	private GeoCurrencyResolver $geoResolver;
	private EventTracker $eventTracker;

	public function __construct( CurrencyPersistenceService $persistence, GeoCurrencyResolver $geoResolver, EventTracker $eventTracker ) {
		$this->persistence  = $persistence;
		$this->geoResolver  = $geoResolver;
		$this->eventTracker = $eventTracker;
	}

	public function resolve(): void {
		$persistedCurrency = $this->persistence->getCurrency();

		if ( null !== $persistedCurrency ) {
			$source = $this->persistence->getSource();

			if ( SessionService::SOURCE_AUTO === $source && self::MODE_ALWAYS_DETECT === self::mode() ) {
				$this->applyAutoDetection();
			}

			// Manual/url sources, or an auto source under "remember" mode,
			// are left exactly as they are.
			return;
		}

		if ( GeoSuggestionService::isConfirmationModeEnabled() ) {
			// Don't auto-apply — GeoSuggestionService surfaces this as a
			// dismissible suggestion instead, and the request falls back
			// to the base currency until the shopper confirms.
			return;
		}

		$this->applyAutoDetection();
	}

	private function applyAutoDetection(): void {
		$result = $this->geoResolver->resolve();

		$this->persistence->setCurrency( $result['currency'], SessionService::SOURCE_AUTO );

		// Only a genuine geolocation/language detection, not the "nothing
		// detected, fell back to base currency" case, counts as a country
		// visit worth reporting on.
		if ( $result['detected'] && null !== $result['country'] ) {
			$this->eventTracker->recordCountryVisit( $result['country'], $result['currency'] );
		}
	}

	public static function mode(): string {
		$mode = (string) get_option( 'wcmcs_currency_remember_mode', self::MODE_REMEMBER );

		return self::MODE_ALWAYS_DETECT === $mode ? self::MODE_ALWAYS_DETECT : self::MODE_REMEMBER;
	}
}
