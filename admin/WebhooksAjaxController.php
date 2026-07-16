<?php
declare( strict_types=1 );

namespace WCMCS\Admin;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebhooksAjaxController {

	public static function register(): void {
		add_action( 'wp_ajax_wcmcs_test_webhook', array( self::class, 'handle_test' ) );
	}

	public static function handle_test(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wc-multicurrency-switcher' ) ), 403 );
		}

		check_ajax_referer( 'wcmcs_test_webhook', 'nonce' );

		$webhookId = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		/** @var \WCMCS\Services\WebhookService $webhooks */
		$webhooks = Plugin::instance()->container()->get( 'webhook_service' );
		$result   = $webhooks->sendTest( $webhookId );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}

		wp_send_json_error( array( 'message' => $result['message'] ) );
	}
}
