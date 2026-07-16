<?php
declare( strict_types=1 );

namespace WCMCS\Cli;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage currencies from the command line.
 *
 * Registered as `wp wcmcs currency <subcommand>` — see
 * WCMCS\Core\Plugin::run() for the WP_CLI::add_command() call, which
 * only happens when WP-CLI is actually the current runtime.
 */
class CurrencyCommand {

	/**
	 * Lists every currency this plugin knows about, or just the enabled ones.
	 *
	 * ## OPTIONS
	 *
	 * [--enabled-only]
	 * : Only list currencies currently enabled on the store.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcmcs currency list
	 *     wp wcmcs currency list --enabled-only
	 */
	public function list_( array $args, array $assoc_args ): void {
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );
		$enabled         = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );

		$rows = array();

		foreach ( $currencyService->all() as $code => $currency ) {
			$isEnabled = in_array( $code, $enabled, true );

			if ( ! empty( $assoc_args['enabled-only'] ) && ! $isEnabled ) {
				continue;
			}

			$rows[] = array(
				'code'    => $code,
				'name'    => $currency->name(),
				'symbol'  => $currency->symbol(),
				'enabled' => $isEnabled ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'code', 'name', 'symbol', 'enabled' ) );
	}

	/**
	 * Enables a currency.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : ISO 4217 currency code, e.g. EUR.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcmcs currency enable EUR
	 */
	public function enable( array $args ): void {
		$code = strtoupper( $args[0] ?? '' );

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );

		if ( ! $currencyService->exists( $code ) ) {
			\WP_CLI::error( "Unknown currency code: {$code}" );
			return;
		}

		$enabled = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );

		if ( in_array( $code, $enabled, true ) ) {
			\WP_CLI::success( "{$code} is already enabled." );
			return;
		}

		$enabled[] = $code;
		update_option( 'wcmcs_enabled_currencies', $enabled );

		\WP_CLI::success( "Enabled {$code}." );
	}

	/**
	 * Disables a currency.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : ISO 4217 currency code, e.g. EUR.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcmcs currency disable EUR
	 */
	public function disable( array $args ): void {
		$code    = strtoupper( $args[0] ?? '' );
		$enabled = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );
		$updated = array_values( array_diff( $enabled, array( $code ) ) );

		update_option( 'wcmcs_enabled_currencies', $updated );

		\WP_CLI::success( count( $updated ) < count( $enabled ) ? "Disabled {$code}." : "{$code} was not enabled." );
	}
}
