<?php
declare( strict_types=1 );

namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple transient-backed rate limiter for actions that call out to a
 * metered third-party API (rate providers) or are otherwise cheap to
 * abuse — without this, a user spam-clicking "Refresh Rates Now" (or a
 * script doing the same against admin-ajax.php with a stolen nonce)
 * could exhaust the site's daily API quota in seconds, and an
 * unauthenticated REST endpoint with no rate limit at all is an open
 * invitation to be hammered.
 *
 * Keyed per-identifier (not just per-action) so one heavy caller can't
 * lock out another, and per-site via the transient API so it works the
 * same on single-site and multisite without extra plumbing. The
 * identifier is a plain string so the same limiter works for both a
 * logged-in user ID (admin AJAX actions) and an anonymous visitor's IP
 * address (public REST endpoints) — see StoreApiController.
 */
class RateLimiter {

	/**
	 * Returns true and records the attempt if the action is currently
	 * allowed for this identifier; returns false (and records nothing) if
	 * the caller is still inside the cooldown window from a previous
	 * attempt.
	 *
	 * @param int|string $identifier A user ID, IP address, or any other
	 *                               string that identifies the caller.
	 */
	public static function attempt( string $action, $identifier, int $windowSeconds ): bool {
		$key = self::transientKey( $action, $identifier );

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, time(), $windowSeconds );

		return true;
	}

	/**
	 * Seconds remaining until the caller may retry, or 0 if they may
	 * retry now. Used to give the caller a precise "try again in Ns"
	 * message instead of a bare rejection.
	 *
	 * @param int|string $identifier Same identifier passed to attempt().
	 */
	public static function retryAfter( string $action, $identifier, int $windowSeconds ): int {
		$key       = self::transientKey( $action, $identifier );
		$startedAt = get_transient( $key );

		if ( false === $startedAt ) {
			return 0;
		}

		$remaining = $windowSeconds - ( time() - (int) $startedAt );

		return max( 0, $remaining );
	}

	/**
	 * @param int|string $identifier
	 */
	private static function transientKey( string $action, $identifier ): string {
		return 'wcmcs_rl_' . md5( $action . '|' . (string) $identifier );
	}
}
