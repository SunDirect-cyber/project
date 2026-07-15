<?php
namespace WCMCS\Core;

use WCMCS\Services\CurrencyRule\CurrencyRuleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports/imports every setting this plugin stores — for staging-to
 * -production migration or replicating configuration across a
 * multisite network — as a single validated JSON structure.
 *
 * Import is deliberately all-or-nothing: every field in the incoming
 * data is validated *before* anything is written, so a corrupted or
 * incompatible file is rejected outright rather than partially applied
 * (which would leave the store in a worse, inconsistent state than
 * either the old or the new configuration).
 */
class SettingsPortability {

	public const FORMAT_VERSION = 1;

	private const SIMPLE_OPTIONS = array(
		'wcmcs_enabled_currencies',
		'wcmcs_currency_format_overrides',
		'wcmcs_provider_priority',
		'wcmcs_rate_refresh_interval',
		'wcmcs_rate_alert_threshold_hours',
		'wcmcs_rate_deviation_threshold_percent',
		'wcmcs_manual_rates',
		'wcmcs_gateway_currency_overrides',
		'wcmcs_currency_remember_mode',
		'wcmcs_currency_switch_confirmation',
		'wcmcs_auto_detection_mode',
		'wcmcs_default_switcher_style',
		'wcmcs_floating_widget_enabled',
		'wcmcs_floating_widget_style',
		'wcmcs_menu_location',
	);

	private const KEY_OPTIONS = array(
		'wcmcs_provider_openexchangerates_key',
		'wcmcs_provider_fixer_key',
		'wcmcs_provider_exchangerateapi_key',
	);

	/**
	 * @return array<string, mixed>
	 */
	public static function export( bool $includeApiKeys = false ): array {
		$options = array();

		foreach ( self::SIMPLE_OPTIONS as $name ) {
			$value = get_option( $name, null );

			if ( null !== $value ) {
				$options[ $name ] = $value;
			}
		}

		if ( $includeApiKeys ) {
			foreach ( self::KEY_OPTIONS as $name ) {
				$value = get_option( $name, '' );

				if ( '' !== $value ) {
					$options[ $name ] = $value;
				}
			}
		}

		$ruleRepository = new CurrencyRuleRepository();

		return array(
			'wcmcs_export_version' => self::FORMAT_VERSION,
			'plugin_version'       => defined( 'WCMCS_VERSION' ) ? WCMCS_VERSION : null,
			'exported_at'          => gmdate( 'c' ),
			'site_url'             => home_url(),
			'options'              => $options,
			'currency_rules'       => $ruleRepository->getAll(),
		);
	}

	/**
	 * @param mixed $data Decoded JSON — untrusted input, validated field by field.
	 * @return array{success: bool, errors: string[], applied: array<string, mixed>}
	 */
	public static function import( $data ): array {
		$errors = self::validate( $data );

		if ( ! empty( $errors ) ) {
			return array( 'success' => false, 'errors' => $errors, 'applied' => array() );
		}

		$applied = array();

		foreach ( $data['options'] as $name => $value ) {
			update_option( $name, $value );
			$applied[ $name ] = $value;
		}

		if ( ! empty( $data['currency_rules'] ) && is_array( $data['currency_rules'] ) ) {
			$ruleRepository = new CurrencyRuleRepository();

			foreach ( $data['currency_rules'] as $rule ) {
				$ruleRepository->upsert(
					(string) $rule['currency'],
					(string) $rule['rule_type'],
					(string) $rule['rule_value'],
					(int) ( $rule['priority'] ?? 10 )
				);
			}
		}

		return array( 'success' => true, 'errors' => array(), 'applied' => $applied );
	}

	/**
	 * @param mixed $data
	 * @return string[]
	 */
	private static function validate( $data ): array {
		$errors = array();

		if ( ! is_array( $data ) ) {
			return array( __( 'File does not contain a valid settings export (not a JSON object).', 'wc-multicurrency-switcher' ) );
		}

		if ( ! isset( $data['wcmcs_export_version'] ) || (int) $data['wcmcs_export_version'] > self::FORMAT_VERSION ) {
			$errors[] = __( 'This file was exported by a newer, incompatible version of this plugin.', 'wc-multicurrency-switcher' );
		}

		if ( ! isset( $data['options'] ) || ! is_array( $data['options'] ) ) {
			$errors[] = __( 'File is missing its "options" section.', 'wc-multicurrency-switcher' );

			return $errors; // Nothing further can be validated meaningfully.
		}

		$knownOptions = array_merge( self::SIMPLE_OPTIONS, self::KEY_OPTIONS );

		foreach ( $data['options'] as $name => $value ) {
			if ( ! in_array( $name, $knownOptions, true ) ) {
				$errors[] = sprintf(
					/* translators: %s: option name */
					__( 'Unrecognized setting in file: %s', 'wc-multicurrency-switcher' ),
					(string) $name
				);
			}
		}

		if ( isset( $data['options']['wcmcs_enabled_currencies'] ) ) {
			if ( ! is_array( $data['options']['wcmcs_enabled_currencies'] ) ) {
				$errors[] = __( 'Enabled currencies must be a list.', 'wc-multicurrency-switcher' );
			} else {
				foreach ( $data['options']['wcmcs_enabled_currencies'] as $code ) {
					if ( ! is_string( $code ) || ! preg_match( '/^[A-Za-z]{3}$/', $code ) ) {
						$errors[] = sprintf(
							/* translators: %s: the invalid value found */
							__( 'Invalid currency code in enabled currencies list: %s', 'wc-multicurrency-switcher' ),
							wp_json_encode( $code )
						);
						break;
					}
				}
			}
		}

		if ( isset( $data['currency_rules'] ) ) {
			if ( ! is_array( $data['currency_rules'] ) ) {
				$errors[] = __( 'Currency rules must be a list.', 'wc-multicurrency-switcher' );
			} else {
				foreach ( $data['currency_rules'] as $rule ) {
					if ( ! is_array( $rule ) || empty( $rule['currency'] ) || empty( $rule['rule_type'] ) || ! isset( $rule['rule_value'] ) ) {
						$errors[] = __( 'A currency rule entry is malformed (missing currency, rule_type, or rule_value).', 'wc-multicurrency-switcher' );
						break;
					}
				}
			}
		}

		return $errors;
	}
}
