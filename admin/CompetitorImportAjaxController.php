<?php
namespace WCMCS\Admin;

use WCMCS\Compat\Migration\CompetitorImporter;
use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX backend for the "Import from another plugin" section of the
 * Import/Export screen — a preview step (read-only) and a confirm step
 * (writes), kept as two separate actions so the admin always sees
 * exactly what will be imported before anything changes.
 */
class CompetitorImportAjaxController {

	public const NONCE = 'wcmcs_competitor_import_nonce';

	private const KNOWN_PLUGINS = array(
		CompetitorImporter::PLUGIN_WOOCS,
		CompetitorImporter::PLUGIN_CURCY,
		CompetitorImporter::PLUGIN_AELIA,
	);

	public static function register(): void {
		add_action( 'wp_ajax_wcmcs_preview_competitor_import', array( self::class, 'handle_preview' ) );
		add_action( 'wp_ajax_wcmcs_confirm_competitor_import', array( self::class, 'handle_import' ) );
	}

	private static function guard(): ?string {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wc-multicurrency-switcher' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		$plugin = isset( $_POST['plugin'] ) ? sanitize_key( wp_unslash( $_POST['plugin'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $plugin, self::KNOWN_PLUGINS, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown plugin.', 'wc-multicurrency-switcher' ) ) );
		}

		return $plugin;
	}

	public static function handle_preview(): void {
		$plugin = self::guard();

		$preview = CompetitorImporter::preview( $plugin );

		wp_send_json_success(
			array(
				'plugin'           => CompetitorImporter::label( $plugin ),
				'currencies'       => $preview['currencies'],
				'default_currency' => $preview['default_currency'],
			)
		);
	}

	public static function handle_import(): void {
		$plugin = self::guard();

		$result = CompetitorImporter::import( $plugin );

		if ( 0 === $result['imported_count'] ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to import — no recognizable currency data was found for that plugin.', 'wc-multicurrency-switcher' ) ) );
		}

		/** @var \WCMCS\Services\ActivityLogger $activity */
		$activity = Plugin::instance()->container()->get( 'activity_logger' );
		$activity->record(
			'Imported currencies from a competitor plugin',
			array( 'source' => CompetitorImporter::label( $plugin ), 'currencies' => $result['currencies'] )
		);

		wp_send_json_success(
			array(
				'message'    => sprintf(
					/* translators: %d: number of currencies imported */
					__( 'Imported %d currencies. Review Currencies & Display settings, then refresh exchange rates.', 'wc-multicurrency-switcher' ),
					$result['imported_count']
				),
				'currencies' => $result['currencies'],
			)
		);
	}
}
