<?php
namespace WCMCS\Services\CurrencyRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the wcmcs_currency_rules table (created by
 * WCMCS\Core\Installer). A "rule" is a per-currency, per-type setting —
 * a locked rate, a markup percentage, or a rounding config — stored as
 * plain text/JSON in rule_value so new rule types don't need a schema
 * change.
 *
 * At most one *active* rule exists per (currency, rule_type) pair:
 * upsert() enforces that by updating an existing active row instead of
 * insert()-ing a duplicate, so "what's the markup for EUR right now" is
 * always a single unambiguous answer.
 */
class CurrencyRuleRepository {

	public const TYPE_LOCKED_RATE    = 'locked_rate';
	public const TYPE_MARKUP_PERCENT = 'markup_percent';
	public const TYPE_ROUNDING       = 'rounding';
	public const TYPE_RATE_BOUNDS    = 'rate_bounds';

	/**
	 * get() is the hot path — PriceConverter calls it (indirectly, via
	 * PricingRuleService) for every single product price on a page, so a
	 * shop page of 20 products would otherwise run dozens of identical
	 * queries for the same currency's rounding/markup/lock rule. This
	 * instance persists for the lifetime of the request (the DI
	 * container only ever builds one), so a plain in-memory cache here
	 * is enough to turn "N queries" into "one query per (currency,
	 * rule_type) actually asked about, ever, per request".
	 *
	 * @var array<string, array|null>
	 */
	private array $cache = array();

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wcmcs_currency_rules';
	}

	/**
	 * @return array{id: int, currency: string, rule_type: string, rule_value: string, priority: int, is_active: bool}|null
	 */
	public function get( string $currency, string $ruleType ): ?array {
		global $wpdb;

		$cacheKey = strtoupper( $currency ) . '_' . $ruleType;

		if ( array_key_exists( $cacheKey, $this->cache ) ) {
			return $this->cache[ $cacheKey ];
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, currency, rule_type, rule_value, priority, is_active FROM {$this->table()} WHERE currency = %s AND rule_type = %s AND is_active = 1 ORDER BY priority DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				strtoupper( $currency ),
				$ruleType
			),
			ARRAY_A
		);

		if ( ! $row ) {
			$this->cache[ $cacheKey ] = null;
			return null;
		}

		$this->cache[ $cacheKey ] = array(
			'id'         => (int) $row['id'],
			'currency'   => (string) $row['currency'],
			'rule_type'  => (string) $row['rule_type'],
			'rule_value' => (string) $row['rule_value'],
			'priority'   => (int) $row['priority'],
			'is_active'  => (bool) $row['is_active'],
		);

		return $this->cache[ $cacheKey ];
	}

	public function upsert( string $currency, string $ruleType, string $value, int $priority = 10 ): void {
		global $wpdb;

		$existing = $this->get( $currency, $ruleType );
		$now      = current_time( 'mysql' );

		if ( null !== $existing ) {
			$wpdb->update(
				$this->table(),
				array(
					'rule_value' => $value,
					'priority'   => $priority,
					'updated_at' => $now,
				),
				array( 'id' => $existing['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			$this->forget( $currency, $ruleType );
			return;
		}

		$wpdb->insert(
			$this->table(),
			array(
				'currency'   => strtoupper( $currency ),
				'rule_type'  => $ruleType,
				'rule_value' => $value,
				'priority'   => $priority,
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		$this->forget( $currency, $ruleType );
	}

	public function remove( string $currency, string $ruleType ): void {
		global $wpdb;

		$wpdb->delete(
			$this->table(),
			array(
				'currency'  => strtoupper( $currency ),
				'rule_type' => $ruleType,
			),
			array( '%s', '%s' )
		);
		$this->forget( $currency, $ruleType );
	}

	private function forget( string $currency, string $ruleType ): void {
		unset( $this->cache[ strtoupper( $currency ) . '_' . $ruleType ] );
	}

	/**
	 * @return array<string, array{id: int, currency: string, rule_type: string, rule_value: string, priority: int, is_active: bool}>
	 *         Every active rule for a currency, keyed by rule_type.
	 */
	public function getAllForCurrency( string $currency ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, currency, rule_type, rule_value, priority, is_active FROM {$this->table()} WHERE currency = %s AND is_active = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				strtoupper( $currency )
			),
			ARRAY_A
		);

		$rules = array();

		foreach ( $rows ?: array() as $row ) {
			$rules[ $row['rule_type'] ] = array(
				'id'         => (int) $row['id'],
				'currency'   => (string) $row['currency'],
				'rule_type'  => (string) $row['rule_type'],
				'rule_value' => (string) $row['rule_value'],
				'priority'   => (int) $row['priority'],
				'is_active'  => (bool) $row['is_active'],
			);
		}

		return $rules;
	}
}
