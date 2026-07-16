<?php
namespace WCMCS\Services\Analytics;

use WCMCS\Core\Hpos;
use WCMCS\Services\Geo\CountryCurrencyMap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads wcmcs_currency_events (written by EventTracker) for the three
 * things the spec asks for: country-vs-currency mismatches, switch
 * abandonment, and the most common switch pairs.
 */
class GeographicInsightsReport {

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wcmcs_currency_events';
	}

	/**
	 * Per country: how many visits, and which currency they most often
	 * ended up with — compared against that country's official currency
	 * (CountryCurrencyMap), so a mismatch (e.g. lots of GB visits but few
	 * ending up on GBP) is flagged rather than left for the store owner
	 * to notice by eye.
	 *
	 * @return array<string, array{visits: int, top_currency: ?string, top_currency_share: float, expected_currency: ?string, mismatch: bool}>
	 */
	public function countryVsCurrency( string $fromDate, string $toDate ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$this->table()} is this repository's own table name, never user data.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country, to_currency, COUNT(*) AS visits FROM {$this->table()}
				WHERE event_type = 'country_visit' AND country IS NOT NULL AND created_at BETWEEN %s AND %s
				GROUP BY country, to_currency",
				$fromDate . ' 00:00:00',
				$toDate . ' 23:59:59'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$byCountry = array();

		foreach ( $rows ?: array() as $row ) {
			$country  = strtoupper( (string) $row['country'] );
			$currency = $row['to_currency'] ? strtoupper( (string) $row['to_currency'] ) : null;
			$visits   = (int) $row['visits'];

			if ( ! isset( $byCountry[ $country ] ) ) {
				$byCountry[ $country ] = array(
					'total'       => 0,
					'by_currency' => array(),
				);
			}

			$byCountry[ $country ]['total']                          += $visits;
			$byCountry[ $country ]['by_currency'][ $currency ?? '—' ] = ( $byCountry[ $country ]['by_currency'][ $currency ?? '—' ] ?? 0 ) + $visits;
		}

		$result = array();

		foreach ( $byCountry as $country => $data ) {
			arsort( $data['by_currency'] );
			$topCurrency = array_key_first( $data['by_currency'] );
			$topCount    = $data['by_currency'][ $topCurrency ] ?? 0;
			$expected    = CountryCurrencyMap::currencyForCountry( $country );

			$result[ $country ] = array(
				'visits'             => $data['total'],
				'top_currency'       => '—' === $topCurrency ? null : $topCurrency,
				'top_currency_share' => $data['total'] > 0 ? round( ( $topCount / $data['total'] ) * 100, 1 ) : 0.0,
				'expected_currency'  => $expected,
				'mismatch'           => null !== $expected && $expected !== $topCurrency,
			);
		}

		uasort( $result, static fn ( $a, $b ) => $b['visits'] <=> $a['visits'] );

		return $result;
	}

	/**
	 * @return array{from: string, to: string, count: int}[]
	 */
	public function mostSwitchedPairs( string $fromDate, string $toDate, int $limit = 10 ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$this->table()} is this repository's own table name, never user data.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT from_currency, to_currency, COUNT(*) AS switch_count FROM {$this->table()}
				WHERE event_type = 'switch' AND created_at BETWEEN %s AND %s
				GROUP BY from_currency, to_currency ORDER BY switch_count DESC LIMIT %d",
				$fromDate . ' 00:00:00',
				$toDate . ' 23:59:59',
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static fn ( array $row ) => array(
				'from'  => strtoupper( (string) $row['from_currency'] ),
				'to'    => strtoupper( (string) $row['to_currency'] ),
				'count' => (int) $row['switch_count'],
			),
			$rows ?: array()
		);
	}

	/**
	 * Switch events in the range where the session that switched never
	 * placed an order — a signal something about that market's pricing
	 * or experience isn't converting. Only counts switches old enough
	 * that the session has had a fair chance to check out
	 * ($minAgeHours), so a switch from an hour ago isn't wrongly counted
	 * as "abandoned" when the shopper might simply still be browsing.
	 *
	 * @return array{total_switches: int, abandoned: int, abandonment_rate: float}
	 */
	public function abandonment( string $fromDate, string $toDate, int $minAgeHours = 48 ): array {
		global $wpdb;

		// EventTracker writes created_at via current_time( 'mysql' ) —
		// site-local time, not UTC — so the cutoff has to be computed the
		// same way. Using gmdate()/strtotime() (UTC) here would be off by
		// exactly the site's UTC offset for any store not physically in
		// UTC, silently widening or narrowing the "abandoned" window.
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $minAgeHours * HOUR_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$this->table()} is this repository's own table name, never user data.
		$switches = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT session_id FROM {$this->table()}
				WHERE event_type = 'switch' AND session_id IS NOT NULL AND created_at BETWEEN %s AND %s AND created_at <= %s",
				$fromDate . ' 00:00:00',
				$toDate . ' 23:59:59',
				$cutoff
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$sessionIds = array_map( static fn ( $row ) => (string) $row['session_id'], $switches ?: array() );

		if ( empty( $sessionIds ) ) {
			return array(
				'total_switches'   => 0,
				'abandoned'        => 0,
				'abandonment_rate' => 0.0,
			);
		}

		$convertedSessions = Hpos::is_enabled() ? $this->sessionsWithOrdersHpos( $sessionIds ) : $this->sessionsWithOrdersLegacy( $sessionIds );

		$abandoned = count( array_diff( $sessionIds, $convertedSessions ) );
		$total     = count( $sessionIds );

		return array(
			'total_switches'   => $total,
			'abandoned'        => $abandoned,
			'abandonment_rate' => $total > 0 ? round( ( $abandoned / $total ) * 100, 1 ) : 0.0,
		);
	}

	/**
	 * @param string[] $sessionIds
	 * @return string[]
	 */
	private function sessionsWithOrdersHpos( array $sessionIds ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $sessionIds ), '%s' ) );

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_wcmcs_session_id' AND meta_value IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$placeholders} is a dynamically-built %s-per-session-id list, a genuine placeholder set the sniff can't statically verify
				$sessionIds
			)
		);

		return $rows ?: array();
	}

	/**
	 * @param string[] $sessionIds
	 * @return string[]
	 */
	private function sessionsWithOrdersLegacy( array $sessionIds ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $sessionIds ), '%s' ) );

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wcmcs_session_id' AND meta_value IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- see sessionsWithOrdersHpos() above, same reasoning
				$sessionIds
			)
		);

		return $rows ?: array();
	}
}
