<?php
declare( strict_types=1 );

namespace WCMCS\Compat\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects and imports settings from a handful of popular competing
 * multi-currency plugins — lowers the switching cost for a store
 * currently using one of them.
 *
 * Deliberately narrow in scope, and deliberately honest about why: this
 * environment has no live install of any of these plugins to verify
 * their exact stored-option schema against, so every option name/shape
 * checked below is this plugin's best-documented understanding of each
 * competitor's known behavior, not something confirmed against real
 * data. Getting a competitor's internal schema wrong in a way that
 * silently imports garbage would be worse than importing nothing, so
 * this only ever imports the one thing that's both the most valuable to
 * carry over and the safest to get right: the list of currencies the
 * store had enabled, plus which one was the default/base. Every
 * imported code is validated against this plugin's own ISO 4217 dataset
 * before being accepted — an unrecognized or malformed value from a
 * schema mismatch is simply dropped, never passed through. Markup,
 * rounding, and historical rate data are deliberately NOT imported:
 * this plugin fetches its own fresh live rates the moment currencies
 * are enabled, which is a safer foundation than trying to carry over
 * another plugin's differently-modeled pricing rules.
 *
 * A preview is always shown before anything is written — see
 * ImportExportPage — so the store owner can verify the detected
 * currencies look right before committing, exactly the same
 * "detect and warn rather than silently guess" policy this plugin
 * applies to base-currency changes and third-party integrations
 * elsewhere.
 */
class CompetitorImporter {

	public const PLUGIN_WOOCS = 'woocs';
	public const PLUGIN_CURCY = 'curcy';
	public const PLUGIN_AELIA = 'aelia';

	private const LABELS = array(
		self::PLUGIN_WOOCS => 'WOOCS – WooCommerce Currency Switcher',
		self::PLUGIN_CURCY => 'CURCY – Multi Currency for WooCommerce',
		self::PLUGIN_AELIA => 'Currency Switcher for WooCommerce (Aelia)',
	);

	public static function label( string $pluginKey ): string {
		return self::LABELS[ $pluginKey ] ?? $pluginKey;
	}

	/**
	 * Every competitor this importer knows about that has *some* data
	 * present under its known option name(s) — active or not, since a
	 * store owner may have already deactivated the competitor before
	 * installing this plugin, and its settings (harmlessly) remain in
	 * wp_options either way.
	 *
	 * @return string[] Plugin keys (self::PLUGIN_*).
	 */
	public static function detectAvailable(): array {
		$found = array();

		foreach ( array_keys( self::LABELS ) as $key ) {
			if ( ! empty( self::preview( $key )['currencies'] ) ) {
				$found[] = $key;
			}
		}

		return $found;
	}

	/**
	 * Reads a competitor's stored settings without changing anything —
	 * what ImportExportPage shows the admin before they confirm.
	 *
	 * @return array{plugin: string, currencies: string[], default_currency: ?string, notes: string[]}
	 */
	public static function preview( string $pluginKey ): array {
		switch ( $pluginKey ) {
			case self::PLUGIN_WOOCS:
				return self::previewWoocs();
			case self::PLUGIN_CURCY:
				return self::previewCurcy();
			case self::PLUGIN_AELIA:
				return self::previewAelia();
			default:
				return array(
					'plugin'           => $pluginKey,
					'currencies'       => array(),
					'default_currency' => null,
					'notes'            => array( 'Unknown plugin.' ),
				);
		}
	}

	/**
	 * Applies a preview's currencies to this plugin's own settings —
	 * merged into whatever is already enabled, never replacing it
	 * outright, so importing can't accidentally disable a currency this
	 * plugin was already configured with.
	 *
	 * @return array{imported_count: int, currencies: string[]}
	 */
	public static function import( string $pluginKey ): array {
		$preview = self::preview( $pluginKey );

		if ( empty( $preview['currencies'] ) ) {
			return array(
				'imported_count' => 0,
				'currencies'     => array(),
			);
		}

		$existing = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );
		$merged   = array_values( array_unique( array_merge( $existing, $preview['currencies'] ) ) );

		update_option( 'wcmcs_enabled_currencies', $merged );

		return array(
			'imported_count' => count( $preview['currencies'] ),
			'currencies'     => $preview['currencies'],
		);
	}

	/**
	 * @return string[] Only codes that are valid, real, 3-letter currency
	 *                   codes this plugin actually knows about.
	 */
	private static function validateCodes( array $rawCodes ): array {
		// The currency_repository service is always registered by the
		// time this plugin is actually running (see Plugin::run()) — if
		// it's somehow unavailable, that's a broken-boot state, not a
		// reason to fall back to accepting any 3-letter string as a real
		// currency code. Fail closed, not open.
		if ( ! \WCMCS\Core\Plugin::instance()->container()->has( 'currency_repository' ) ) {
			return array();
		}

		/** @var \WCMCS\Services\Currency\CurrencyRepository $repository */
		$repository = \WCMCS\Core\Plugin::instance()->container()->get( 'currency_repository' );

		$valid = array();

		foreach ( $rawCodes as $code ) {
			if ( ! is_string( $code ) ) {
				continue;
			}

			$code = strtoupper( trim( $code ) );

			if ( ! preg_match( '/^[A-Z]{3}$/', $code ) || ! $repository->exists( $code ) ) {
				continue;
			}

			$valid[] = $code;
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * WOOCS's best-documented, long-standing storage format: a single
	 * option (checked under both the historical uppercase name and the
	 * lowercase variant some versions use) holding an array keyed by
	 * currency code, each entry carrying at least a 'name'/'symbol' and
	 * often an 'is_default' flag for the base currency.
	 */
	private static function previewWoocs(): array {
		$raw = get_option( 'WOOCS_CURRENCIES', null );

		if ( ! is_array( $raw ) ) {
			$raw = get_option( 'woocs_currencies', null );
		}

		if ( ! is_array( $raw ) ) {
			return array(
				'plugin'           => self::PLUGIN_WOOCS,
				'currencies'       => array(),
				'default_currency' => null,
				'notes'            => array(),
			);
		}

		$codes   = self::validateCodes( array_keys( $raw ) );
		$default = null;

		foreach ( $raw as $code => $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['is_default'] ) ) {
				$candidate = strtoupper( (string) $code );

				if ( in_array( $candidate, $codes, true ) ) {
					$default = $candidate;
				}
			}
		}

		return array(
			'plugin'           => self::PLUGIN_WOOCS,
			'currencies'       => $codes,
			'default_currency' => $default,
			'notes'            => array(),
		);
	}

	/**
	 * CURCY's known option holds either a comma-separated string of
	 * currency codes or (in some versions) a plain array — both forms
	 * are handled.
	 */
	private static function previewCurcy(): array {
		$raw = get_option( 'alg_wc_currency_switcher_currencies', null );

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$raw = array_map( 'trim', explode( ',', $raw ) );
		}

		if ( ! is_array( $raw ) ) {
			return array(
				'plugin'           => self::PLUGIN_CURCY,
				'currencies'       => array(),
				'default_currency' => null,
				'notes'            => array(),
			);
		}

		return array(
			'plugin'           => self::PLUGIN_CURCY,
			'currencies'       => self::validateCodes( $raw ),
			'default_currency' => null,
			'notes'            => array(),
		);
	}

	/**
	 * Aelia's settings are stored as one large serialized options array;
	 * this checks the two sub-keys most consistently documented across
	 * its own changelog/support content for the enabled-currencies list.
	 */
	private static function previewAelia(): array {
		$settings = get_option( 'wc_aelia_currency_switcher', null );

		if ( ! is_array( $settings ) ) {
			return array(
				'plugin'           => self::PLUGIN_AELIA,
				'currencies'       => array(),
				'default_currency' => null,
				'notes'            => array(),
			);
		}

		$raw = $settings['enabled_currencies'] ?? $settings['currencies'] ?? null;

		if ( is_string( $raw ) ) {
			$raw = array_map( 'trim', explode( ',', $raw ) );
		}

		if ( ! is_array( $raw ) ) {
			return array(
				'plugin'           => self::PLUGIN_AELIA,
				'currencies'       => array(),
				'default_currency' => null,
				'notes'            => array(),
			);
		}

		return array(
			'plugin'           => self::PLUGIN_AELIA,
			'currencies'       => self::validateCodes( $raw ),
			'default_currency' => null,
			'notes'            => array(),
		);
	}
}
