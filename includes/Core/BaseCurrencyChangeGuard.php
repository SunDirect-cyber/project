<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the store's base currency (WooCommerce > Settings > General)
 * being changed while this plugin is active.
 *
 * What this deliberately does NOT do: silently re-convert every stored
 * product price to the new base currency. That would require picking an
 * exchange rate to apply retroactively to every product, which is a
 * real pricing decision only the store owner can make correctly (should
 * it use today's rate? the rate on each product's last edit date? Some
 * products already have deliberately custom pricing that a blanket
 * conversion would wreck). Guessing here risks corrupting every price
 * in the catalog in one silent step, which is a far worse outcome than
 * doing nothing. WooCommerce itself has this same limitation when the
 * base currency changes — it's a known store-wide gotcha, not something
 * introduced by this plugin.
 *
 * What this DOES handle, safely and automatically:
 *  - Every cached converted price is invalidated immediately, so nothing
 *    served after the change is a stale price computed against the old
 *    base currency.
 *  - A fresh rate fetch for (new base -> every enabled currency) is
 *    scheduled right away, rather than leaving conversions silently
 *    broken (or worse, using leftover history rows scoped to the old
 *    base) until the next regular cron run.
 *  - The change itself is recorded with a timestamp, in a form the
 *    analytics/reporting screens can use later to flag "the base
 *    currency changed partway through this date range" rather than
 *    silently blending revenue figures computed against two different
 *    base currencies.
 *  - A one-time admin notice spells out exactly what did and didn't
 *    happen automatically, so the store owner knows a manual product
 *    price review is on them, not something this plugin is expected to
 *    have already handled.
 */
class BaseCurrencyChangeGuard {

	private const HISTORY_OPTION = 'wcmcs_base_currency_history';
	private const MAX_HISTORY    = 20;

	// How long the "base currency just changed" admin notice keeps
	// showing before it auto-expires. Long enough that a store owner who
	// only logs into wp-admin occasionally still sees it; short enough
	// that it doesn't nag indefinitely. Deliberately not a dismiss
	// button wired to its own AJAX round trip for what's a one-time,
	// naturally-expiring notice — that's unneeded complexity for this.
	private const NOTICE_LIFETIME_DAYS = 14;

	public static function register(): void {
		add_action( 'update_option_woocommerce_currency', array( self::class, 'handle_change' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'maybe_show_notice' ) );
		add_action( 'wcmcs_refresh_rates_after_base_change', array( self::class, 'handle_refresh_after_base_change' ) );
	}

	public static function handle_change( $oldValue, $newValue ): void {
		$oldCode = strtoupper( (string) $oldValue );
		$newCode = strtoupper( (string) $newValue );

		if ( $oldCode === $newCode ) {
			return;
		}

		$history   = self::history();
		$history[] = array(
			'from'       => $oldCode,
			'to'         => $newCode,
			'changed_at' => current_time( 'mysql' ),
		);

		// Keep only the most recent entries — this is a diagnostic trail
		// for interpreting historical reports, not a full audit log (see
		// ActivityLogger, below, for that).
		if ( count( $history ) > self::MAX_HISTORY ) {
			$history = array_slice( $history, -self::MAX_HISTORY );
		}

		update_option( self::HISTORY_OPTION, $history );

		$container = Plugin::instance()->container();

		if ( $container->has( 'cache_service' ) ) {
			/** @var \WCMCS\Services\CacheService $cache */
			$cache = $container->get( 'cache_service' );
			$cache->flushAll();
		}

		if ( $container->has( 'activity_logger' ) ) {
			/** @var \WCMCS\Services\ActivityLogger $activity */
			$activity = $container->get( 'activity_logger' );
			$activity->record(
				'Store base currency changed',
				array( 'from' => $oldCode, 'to' => $newCode )
			);
		}

		// A real rate fetch is an outbound HTTP call — doing it inline
		// during this option's save request would make changing a
		// WooCommerce setting unexpectedly slow (or fail outright if a
		// provider is briefly unreachable). Scheduling it as its own
		// single event runs it moments later, off the current request.
		if ( ! wp_next_scheduled( 'wcmcs_refresh_rates_after_base_change' ) ) {
			wp_schedule_single_event( time() + 5, 'wcmcs_refresh_rates_after_base_change' );
		}
	}

	public static function handle_refresh_after_base_change(): void {
		$container = Plugin::instance()->container();

		if ( ! $container->has( 'rate_service' ) || ! $container->has( 'currency_service' ) ) {
			return;
		}

		/** @var \WCMCS\Services\ExchangeRate\RateService $rateService */
		$rateService = $container->get( 'rate_service' );
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );

		$base    = $currencyService->baseCurrency()->code();
		$targets = (array) get_option( 'wcmcs_enabled_currencies', array() );

		if ( empty( $targets ) ) {
			return;
		}

		$rateService->refreshAll( $base, $targets );
	}

	/**
	 * @return array{from: string, to: string, changed_at: string}[]
	 */
	public static function history(): array {
		$history = get_option( self::HISTORY_OPTION, array() );

		return is_array( $history ) ? $history : array();
	}

	/**
	 * Whether a given date range overlaps a recorded base-currency
	 * change — used by reporting screens to add a "figures before/after
	 * this date used a different base currency" caveat instead of
	 * silently presenting a discontinuous series as one continuous one.
	 */
	public static function changedWithinRange( string $fromDate, string $toDate ): array {
		$matches = array();

		foreach ( self::history() as $entry ) {
			$changedDate = substr( (string) ( $entry['changed_at'] ?? '' ), 0, 10 );

			if ( $changedDate >= $fromDate && $changedDate <= $toDate ) {
				$matches[] = $entry;
			}
		}

		return $matches;
	}

	public static function maybe_show_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$history = self::history();

		if ( empty( $history ) ) {
			return;
		}

		$latest = end( $history );

		// changed_at was written via current_time( 'mysql' ) — site-local
		// time, same as current_time( 'timestamp' ) below — so both sides
		// of this comparison need to be interpreted the same way (parsed
		// as if UTC, per WordPress's own convention for that timestamp
		// flavour) rather than mixing in a real UTC "now".
		$changedAtTs = strtotime( (string) ( $latest['changed_at'] ?? '' ) . ' UTC' );

		if ( false === $changedAtTs || ( current_time( 'timestamp' ) - $changedAtTs ) > self::NOTICE_LIFETIME_DAYS * DAY_IN_SECONDS ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Multi-Currency Switcher:', 'wc-multicurrency-switcher' ),
			esc_html(
				sprintf(
					/* translators: 1: old base currency code, 2: new base currency code */
					__( 'The store\'s base currency changed from %1$s to %2$s. Existing product prices were NOT automatically converted — review them manually. Cached conversions were cleared and fresh exchange rates are being fetched now. Reports covering dates before this change used %1$s as the base currency.', 'wc-multicurrency-switcher' ),
					esc_html( (string) ( $latest['from'] ?? '' ) ),
					esc_html( (string) ( $latest['to'] ?? '' ) )
				)
			)
		);
	}
}
