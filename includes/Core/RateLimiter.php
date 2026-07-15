<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple transient-backed rate limiter for admin AJAX actions that call
 * out to a metered third-party API (rate providers) — without this, a
 * user spam-clicking "Refresh Rates Now" (or a script doing the same
 * against admin-ajax.php with a stolen nonce) could exhaust the site's
 * daily API quota in seconds.
 *
 * Keyed per-user (not just per-action) so one heavy admin can't lock out
 * another, and per-site via the transient API so it works the same on
 * single-site and multisite without extra plumbing.
 */
class RateLimiter {

	/**
	 * Returns true and records the attempt if the action is currently
	 * allowed for this user; returns false (and records nothing) if the
	 * caller is still inside the cooldown window from a previous attempt.
	 */
	public static function attempt( string $action, int $userId, int $windowSeconds ): bool {
		$key = self::transientKey( $action, $userId );

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, time(), $windowSeconds );

		return true;
	}

	/**
	 * Seconds remaining until the caller may retry, or 0 if they may
	 * retry now. Used to give the admin a precise "try again in Ns"
	 * message instead of a bare rejection.
	 */
	public static function retryAfter( string $action, int $userId, int $windowSeconds ): int {
		$key       = self::transientKey( $action, $userId );
		$startedAt = get_transient( $key );

		if ( false === $startedAt ) {
			return 0;
		}

		$remaining = $windowSeconds - ( time() - (int) $startedAt );

		return max( 0, $remaining );
	}

	private static function transientKey( string $action, int $userId ): string {
		return 'wcmcs_rl_' . md5( $action . '|' . $userId );
	}
}
