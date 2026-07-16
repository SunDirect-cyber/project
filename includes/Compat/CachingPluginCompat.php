<?php
declare( strict_types=1 );

namespace WCMCS\Compat;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents a page cache plugin from serving one visitor's currency-
 * converted prices to a different visitor browsing in a different
 * currency — a real, frequently-reported bug against multi-currency
 * plugins: Visitor A in EUR gets a page cached, Visitor B in USD is
 * served the same cached EUR page.
 *
 * The safe strategy here is exclusion, not cache-key fragmentation:
 * generating and storing a separate cached copy per currency (true
 * "vary by cookie" full-page caching) needs support this plugin can't
 * reliably assume every host's cache plugin/config actually has enabled
 * — getting that wrong in either direction is a correctness bug. Instead:
 *  - A visitor on the store's base currency (the common case, and what
 *    an anonymous first-time visitor sees by default) is completely
 *    unaffected — their page still gets the full benefit of whatever
 *    page cache is configured.
 *  - A visitor who has an active currency selection at all (their own
 *    cookie/session is present, whether it's actually a different
 *    currency or coincidentally the base one) skips the page cache for
 *    that request, guaranteeing they always see their own correct
 *    prices at the cost of that one request not being served from cache.
 * This trades a small amount of cache-hit rate for guaranteed pricing
 * correctness, which is the right trade for anything involving money.
 *
 * Each plugin's own exclusion mechanism is used where documented, plus
 * the DONOTCACHEPAGE constant, which the large majority of WordPress
 * page-cache plugins (including ones not explicitly handled below)
 * check as a de-facto standard. None of these caching plugins are
 * installed in this environment, so — like the other best-effort
 * integrations in this plugin — this is built from each plugin's
 * publicly documented, stable hooks rather than a live test; worth
 * confirming on a staging site running the specific cache plugin in use
 * before relying on it in production.
 */
class CachingPluginCompat {

	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_exclude_from_cache' ), 1 );
	}

	public static function maybe_exclude_from_cache(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! self::visitorHasActiveCurrencySelection() ) {
			return;
		}

		self::excludeCurrentRequestFromCache();
	}

	private static function visitorHasActiveCurrencySelection(): bool {
		if ( ! Plugin::instance()->container()->has( 'currency_persistence_service' ) ) {
			return false;
		}

		/** @var \WCMCS\Services\CurrencyPersistenceService $persistence */
		$persistence = Plugin::instance()->container()->get( 'currency_persistence_service' );

		return null !== $persistence->getCurrency();
	}

	private static function excludeCurrentRequestFromCache(): void {
		// The de-facto standard most page-cache plugins check, including
		// several not given a dedicated integration below (WP Fastest
		// Cache, Cache Enabler, Comet Cache, and others).
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// WP Rocket: any request carrying a cookie matching one of these
		// patterns is never served from or written to the page cache.
		add_filter(
			'rocket_cache_reject_cookies',
			static function ( $cookies ) {
				$cookies   = is_array( $cookies ) ? $cookies : array();
				$cookies[] = 'wcmcs_currency';

				return $cookies;
			}
		);

		// W3 Total Cache: a non-empty return value from this filter is
		// treated as the reason this request must not be cached.
		add_filter(
			'w3tc_pagecache_reject_reason',
			static function ( $reason ) {
				return $reason ?: 'wcmcs: visitor has an active non-default currency selection';
			}
		);

		// LiteSpeed Cache: its own documented action for explicitly
		// marking the current response as not cacheable, with a reason
		// string for LiteSpeed's own debug log.
		if ( has_action( 'litespeed_control_set_nocache' ) || defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_control_set_nocache', 'wcmcs currency selection active' );
		}

		// WP Super Cache: consults this global directly (set before its
		// own late-init cache-serving logic runs on 'init'/'template_redirect').
		global $cache_no_cache; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$cache_no_cache = 1; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

		// A defensive baseline for any reverse proxy/CDN in front of
		// WordPress that isn't one of the specific plugins above (a
		// Cloudflare page rule, Varnish, etc.) — tells it this response
		// is specific to this visitor and must not be cached/shared.
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}
}
