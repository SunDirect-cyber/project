<?php
namespace WCMCS\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight daily traffic counters — currently just "how many requests
 * served a price actually converted to a non-base currency" (the
 * dashboard's "total conversions served" stat). Deliberately not a
 * per-conversion database write (that would run on every single price
 * filter call, exactly the N+1-style cost the performance work just
 * eliminated) — PriceConverter marks "a conversion happened" at most
 * once per request, and this only persists that once, on shutdown.
 *
 * Stored as day-bucketed WordPress options rather than a dedicated
 * table: the write volume is at most one per request (not per
 * conversion), so the simplicity of get_option/update_option outweighs
 * needing a table for this.
 */
class StatsService {

	private const OPTION_PREFIX = 'wcmcs_stats_conversions_';
	private const RETENTION_DAYS = 90;

	public function recordConversionServed(): void {
		$key   = self::OPTION_PREFIX . current_time( 'Y-m-d' );
		$count = (int) get_option( $key, 0 );

		update_option( $key, $count + 1, false );
	}

	/**
	 * @return int Total conversions served across the last $days days.
	 */
	public function totalConversions( int $days = 30 ): int {
		$total = 0;

		foreach ( $this->dailyCounts( $days ) as $count ) {
			$total += $count;
		}

		return $total;
	}

	/**
	 * @return array<string, int> Date (Y-m-d) => count, oldest first.
	 */
	public function dailyCounts( int $days = 30 ): array {
		$counts = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date            = gmdate( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$counts[ $date ] = (int) get_option( self::OPTION_PREFIX . $date, 0 );
		}

		return $counts;
	}

	/**
	 * Deletes counters older than the retention window — hooked to the
	 * daily side of the existing rate-refresh cron so it doesn't need a
	 * schedule of its own.
	 */
	public function pruneOldCounters(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d', strtotime( '-' . self::RETENTION_DAYS . ' days', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				self::OPTION_PREFIX . $cutoff
			)
		);
	}
}
