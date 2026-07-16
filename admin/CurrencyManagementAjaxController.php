<?php
declare( strict_types=1 );

namespace WCMCS\Admin;

use WCMCS\Core\Plugin;
use WCMCS\Services\Currency\CurrencyOverrideService;
use WCMCS\Services\CurrencyRule\RoundingRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backend for the Currency Management screen: saving the enabled
 * currency list (and its order), saving one currency's settings panel,
 * and the "preview" mode calculation.
 */
class CurrencyManagementAjaxController {

	public const NONCE = 'wcmcs_currency_management_nonce';

	public static function register(): void {
		add_action( 'wp_ajax_wcmcs_save_enabled_currencies', array( self::class, 'handle_save_enabled' ) );
		add_action( 'wp_ajax_wcmcs_save_currency_settings', array( self::class, 'handle_save_settings' ) );
		add_action( 'wp_ajax_wcmcs_preview_prices', array( self::class, 'handle_preview' ) );
	}

	private static function guard(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wc-multicurrency-switcher' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * Saves the enabled currency list in the exact order submitted — the
	 * drag-and-drop reorder result — since every other part of the
	 * plugin (switcher, gateway matrix, dashboard) just iterates this
	 * option in storage order.
	 */
	public static function handle_save_enabled(): void {
		self::guard();

		/** @var \WCMCS\Services\Currency\CurrencyRepository $repo */
		$repo = Plugin::instance()->container()->get( 'currency_repository' );

		$submitted = isset( $_POST['currencies'] ) && is_array( $_POST['currencies'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? array_map( static fn ( $c ) => strtoupper( sanitize_text_field( $c ) ), $_POST['currencies'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: array();

		$valid    = array_values( array_filter( array_unique( $submitted ), static fn ( $code ) => $repo->exists( $code ) ) );
		$previous = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );
		$added    = array_diff( $valid, $previous );

		update_option( 'wcmcs_enabled_currencies', $valid );

		/** @var \WCMCS\Services\ActivityLogger $activity */
		$activity = Plugin::instance()->container()->get( 'activity_logger' );
		$activity->record( 'Updated enabled currencies', array( 'currencies' => $valid ) );

		/** @var \WCMCS\Services\WebhookService $webhooks */
		$webhooks = Plugin::instance()->container()->get( 'webhook_service' );

		foreach ( $added as $newCurrency ) {
			$webhooks->trigger( \WCMCS\Services\WebhookService::EVENT_CURRENCY_ADDED, array( 'currency' => $newCurrency ) );
		}

		wp_send_json_success( array( 'currencies' => $valid ) );
	}

	public static function handle_save_settings(): void {
		self::guard();

		$code = isset( $_POST['code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		/** @var \WCMCS\Services\Currency\CurrencyRepository $repo */
		$repo = Plugin::instance()->container()->get( 'currency_repository' );

		if ( '' === $code || ! $repo->exists( $code ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown currency.', 'wc-multicurrency-switcher' ) ) );
		}

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Formatting overrides.
		$format = array();

		if ( '' !== trim( (string) ( $post['symbol'] ?? '' ) ) ) {
			$format['symbol'] = sanitize_text_field( $post['symbol'] );
		}
		if ( isset( $post['decimals'] ) && is_numeric( $post['decimals'] ) ) {
			$format['decimals'] = max( 0, (int) $post['decimals'] );
		}
		if ( in_array( $post['symbol_position'] ?? '', array( 'before', 'after' ), true ) ) {
			$format['symbol_position'] = $post['symbol_position'];
		}
		if ( isset( $post['thousand_separator'] ) ) {
			$format['thousand_separator'] = sanitize_text_field( $post['thousand_separator'] );
		}
		if ( isset( $post['decimal_separator'] ) && '' !== trim( (string) $post['decimal_separator'] ) ) {
			$format['decimal_separator'] = sanitize_text_field( $post['decimal_separator'] );
		}

		if ( ! empty( $format ) ) {
			CurrencyOverrideService::set( $code, $format );
		} else {
			CurrencyOverrideService::clear( $code );
		}

		/** @var \WCMCS\Services\CurrencyRule\PricingRuleService $pricingRules */
		$pricingRules = Plugin::instance()->container()->get( 'pricing_rule_service' );

		// Locked rate: blank clears it.
		$lockedRate = trim( (string) ( $post['locked_rate'] ?? '' ) );
		if ( '' === $lockedRate ) {
			$pricingRules->clearLockedRate( $code );
		} elseif ( is_numeric( $lockedRate ) && (float) $lockedRate > 0 ) {
			$pricingRules->setLockedRate( $code, (float) $lockedRate );
		}

		// Markup: always a real number, 0 is valid ("no markup") and clears it.
		$markup = isset( $post['markup_percent'] ) && is_numeric( $post['markup_percent'] ) ? (float) $post['markup_percent'] : 0.0;
		if ( 0.0 === $markup ) {
			$pricingRules->clearMarkupPercent( $code );
		} else {
			$pricingRules->setMarkupPercent( $code, $markup );
		}

		// Rounding.
		$roundingMode = $post['rounding_mode'] ?? RoundingRule::MODE_NONE;
		if ( RoundingRule::MODE_NONE === $roundingMode ) {
			$pricingRules->clearRounding( $code );
		} elseif ( in_array( $roundingMode, RoundingRule::modes(), true ) ) {
			$step   = isset( $post['rounding_step'] ) && is_numeric( $post['rounding_step'] ) && (float) $post['rounding_step'] > 0 ? (float) $post['rounding_step'] : 1.0;
			$offset = isset( $post['rounding_offset'] ) && is_numeric( $post['rounding_offset'] ) ? (float) $post['rounding_offset'] : 0.01;
			$pricingRules->setRoundingConfig( $code, $roundingMode, $step, $offset );
		}

		/** @var \WCMCS\Services\ActivityLogger $activity */
		$activity = Plugin::instance()->container()->get( 'activity_logger' );
		$activity->record( "Updated {$code} currency settings", array( 'code' => $code ) + $format );

		wp_send_json_success();
	}

	/**
	 * Computes a product's price in every enabled currency without ever
	 * touching session/user-meta state — pure server-side calculation
	 * via PriceConversionService directly, so previewing never changes
	 * what any real shopper (including the admin's own browsing session)
	 * currently sees.
	 */
	public static function handle_preview(): void {
		self::guard();

		$productId = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product   = $productId > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $productId ) : null;

		if ( ! $product instanceof \WC_Product ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'wc-multicurrency-switcher' ) ) );
		}

		$container = Plugin::instance()->container();

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );
		/** @var \WCMCS\Services\PriceConversionService $priceConversion */
		$priceConversion = $container->get( 'price_conversion_service' );

		$base       = $currencyService->baseCurrency()->code();
		$basePrice  = (float) $product->get_price( 'edit' );
		$enabled    = (array) get_option( 'wcmcs_enabled_currencies', array() );
		$results    = array();

		foreach ( $enabled as $code ) {
			$code = strtoupper( $code );

			$converted = $code === $base ? $basePrice : $priceConversion->convert( $basePrice, $base, $code );

			$results[] = array(
				'code'      => $code,
				'formatted' => null === $converted ? null : $currencyService->format( $converted, $code ),
			);
		}

		wp_send_json_success(
			array(
				'product_name' => $product->get_name(),
				'base_price'   => $currencyService->format( $basePrice, $base ),
				'results'      => $results,
			)
		);
	}
}
