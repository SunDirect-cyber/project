<?php
namespace WCMCS\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around WordPress transients, namespaced to this plugin so
 * nothing else can collide with our cache keys. Using transients (rather
 * than a hand-rolled options-table cache) means we automatically get a
 * persistent object cache (Redis/Memcached) for free on any host that has
 * one configured.
 */
class CacheService {

	private const PREFIX = 'wcmcs_';

	// Transient keys are capped at 172 chars by WordPress; keep our own
	// prefixed keys well under that even for long, filter-built cache keys.
	private const MAX_KEY_LENGTH = 150;

	public function get( string $key ) {
		return get_transient( $this->key( $key ) );
	}

	public function set( string $key, $value, int $ttlSeconds ): bool {
		return set_transient( $this->key( $key ), $value, $ttlSeconds );
	}

	public function delete( string $key ): bool {
		return delete_transient( $this->key( $key ) );
	}

	/**
	 * Clears every transient this plugin has ever set, regardless of key
	 * — used when something invalidates a whole category of cached data
	 * at once rather than one known key (e.g. the store's base currency
	 * changing, which makes every cached converted price stale
	 * simultaneously). Mirrors the same LIKE-based cleanup uninstall.php
	 * already does, just without dropping the plugin's own settings too.
	 */
	public function flushAll(): void {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_" . self::PREFIX . "%' OR option_name LIKE '\\_transient\\_timeout\\_" . self::PREFIX . "%'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name and a hardcoded class constant, not user data
		);
	}

	/**
	 * Returns the cached value for $key, or computes it via $callback,
	 * caches it, and returns it if nothing was cached yet (or the cached
	 * value was false — a transient that expired or was never set).
	 */
	public function remember( string $key, int $ttlSeconds, callable $callback ) {
		$cached = $this->get( $key );

		if ( false !== $cached ) {
			return $cached;
		}

		$value = $callback();
		$this->set( $key, $value, $ttlSeconds );

		return $value;
	}

	private function key( string $key ): string {
		$key = self::PREFIX . $key;

		if ( strlen( $key ) <= self::MAX_KEY_LENGTH ) {
			return $key;
		}

		// Long dynamic keys (e.g. built from several currency codes) get
		// hashed down instead of silently truncated, which would risk two
		// different keys colliding on the same cache entry.
		return self::PREFIX . md5( $key );
	}
}
