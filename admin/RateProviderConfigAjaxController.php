<?php
namespace WCMCS\Admin;

use WCMCS\Core\Cron;
use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backend for the Rate Providers screen: saving API keys/priority/sync
 * interval, and the live "Test Connection" button.
 */
class RateProviderConfigAjaxController {

	public const NONCE = 'wcmcs_rate_provider_config_nonce';

	private const KEY_OPTIONS = array(
		'openexchangerates' => 'wcmcs_provider_openexchangerates_key',
		'fixer'              => 'wcmcs_provider_fixer_key',
		'exchangerate-api'   => 'wcmcs_provider_exchangerateapi_key',
	);

	public static function register(): void {
		add_action( 'wp_ajax_wcmcs_save_provider_settings', array( self::class, 'handle_save' ) );
		add_action( 'wp_ajax_wcmcs_test_provider', array( self::class, 'handle_test' ) );
	}

	private static function guard(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wc-multicurrency-switcher' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}

	public static function handle_save(): void {
		self::guard();

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		foreach ( self::KEY_OPTIONS as $slug => $optionName ) {
			if ( isset( $post[ 'key_' . $slug ] ) ) {
				update_option( $optionName, sanitize_text_field( $post[ 'key_' . $slug ] ) );
			}
		}

		/** @var \WCMCS\Services\ExchangeRate\ProviderRegistry $registry */
		$registry = Plugin::instance()->container()->get( 'provider_registry' );
		$known    = $registry->availableSlugs();

		$priority = isset( $post['priority'] ) && is_array( $post['priority'] )
			? array_values( array_intersect( array_map( 'sanitize_key', $post['priority'] ), $known ) )
			: array();

		if ( ! empty( $priority ) ) {
			update_option( 'wcmcs_provider_priority', $priority );
		}

		if ( isset( $post['sync_interval'] ) && array_key_exists( $post['sync_interval'], Cron::INTERVALS ) ) {
			update_option( 'wcmcs_rate_refresh_interval', sanitize_key( $post['sync_interval'] ) );
		}

		wp_send_json_success();
	}

	public static function handle_test(): void {
		self::guard();

		$slug = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		/** @var \WCMCS\Services\ExchangeRate\ProviderRegistry $registry */
		$registry = Plugin::instance()->container()->get( 'provider_registry' );
		$provider = $registry->buildProvider( $slug );

		if ( null === $provider ) {
			wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'wc-multicurrency-switcher' ) ) );
		}

		if ( ! $provider->isConfigured() ) {
			wp_send_json_error( array( 'message' => __( 'This provider has no API key configured.', 'wc-multicurrency-switcher' ) ) );
		}

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );
		$base             = $currencyService->baseCurrency()->code();
		$testTarget       = 'USD' === $base ? 'EUR' : 'USD';

		$rate = $provider->getRate( $base, $testTarget );

		if ( null === $rate ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: base currency, 2: test currency */
						__( 'Connection failed — could not fetch a %1$s → %2$s rate. Check the recent errors below for details.', 'wc-multicurrency-switcher' ),
						$base,
						$testTarget
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: base currency, 2: rate, 3: test currency */
					__( 'Success — 1 %1$s = %2$s %3$s', 'wc-multicurrency-switcher' ),
					$base,
					$rate,
					$testTarget
				),
			)
		);
	}
}
