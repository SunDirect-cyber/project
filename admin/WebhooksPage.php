<?php
namespace WCMCS\Admin;

use WCMCS\Services\WebhookService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Webhooks" admin screen: register/remove webhook URLs per event type,
 * and see each one's signing secret (needed by the receiving system to
 * verify the X-Wcmcs-Signature header WebhookService sends).
 */
class WebhooksPage {

	private const ADD_ACTION    = 'wcmcs_add_webhook';
	private const REMOVE_ACTION = 'wcmcs_remove_webhook';

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style( 'wcmcs-admin', WCMCS_URL . 'assets/css/admin.css', array(), WCMCS_VERSION );
		wp_enqueue_script( 'wcmcs-webhooks', WCMCS_URL . 'assets/js/webhooks.js', array(), WCMCS_VERSION, true );
		wp_localize_script(
			'wcmcs-webhooks',
			'wcmcsWebhooks',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wcmcs_test_webhook' ),
				'i18n'    => array( 'testing' => __( 'Sending test…', 'wc-multicurrency-switcher' ) ),
			)
		);

		if ( isset( $_POST['wcmcs_webhook_add_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcmcs_webhook_add_nonce'] ) ), self::ADD_ACTION ) ) {
			self::handle_add( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( isset( $_POST['wcmcs_webhook_remove_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcmcs_webhook_remove_nonce'] ) ), self::REMOVE_ACTION ) && ! empty( $_POST['webhook_id'] ) ) {
			WebhookService::remove( sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Webhook removed.', 'wc-multicurrency-switcher' ) . '</p></div>';
		}

		?>
		<div class="wrap wcmcs-webhooks">
			<h1><?php esc_html_e( 'Webhooks', 'wc-multicurrency-switcher' ); ?></h1>
			<p><?php esc_html_e( 'Every delivery includes an X-Wcmcs-Signature header: an HMAC-SHA256 of the raw JSON body using the webhook\'s own secret, so your receiving system can verify a payload genuinely came from this store.', 'wc-multicurrency-switcher' ); ?></p>

			<h2><?php esc_html_e( 'Registered Webhooks', 'wc-multicurrency-switcher' ); ?></h2>
			<?php $webhooks = WebhookService::all(); ?>
			<?php if ( empty( $webhooks ) ) : ?>
				<p><em><?php esc_html_e( 'None yet.', 'wc-multicurrency-switcher' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Events', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Secret', 'wc-multicurrency-switcher' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $webhooks as $webhook ) : ?>
							<tr>
								<td><?php echo esc_html( $webhook['url'] ); ?></td>
								<td><?php echo esc_html( implode( ', ', $webhook['events'] ) ); ?></td>
								<td><code><?php echo esc_html( $webhook['secret'] ); ?></code></td>
								<td>
									<button type="button" class="button" data-wcmcs-test-webhook="<?php echo esc_attr( $webhook['id'] ); ?>"><?php esc_html_e( 'Send Test', 'wc-multicurrency-switcher' ); ?></button>
									<span class="wcmcs-status" data-wcmcs-test-result="<?php echo esc_attr( $webhook['id'] ); ?>"></span>
									<form method="post" style="display:inline">
										<?php wp_nonce_field( self::REMOVE_ACTION, 'wcmcs_webhook_remove_nonce' ); ?>
										<input type="hidden" name="webhook_id" value="<?php echo esc_attr( $webhook['id'] ); ?>">
										<button type="submit" class="button-link-delete"><?php esc_html_e( 'Remove', 'wc-multicurrency-switcher' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Add a Webhook', 'wc-multicurrency-switcher' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::ADD_ACTION, 'wcmcs_webhook_add_nonce' ); ?>
				<p>
					<label><?php esc_html_e( 'URL', 'wc-multicurrency-switcher' ); ?></label><br>
					<input type="url" name="url" class="regular-text" required placeholder="https://example.com/webhook">
				</p>
				<p>
					<?php foreach ( WebhookService::validEvents() as $event ) : ?>
						<label><input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>"> <?php echo esc_html( $event ); ?></label><br>
					<?php endforeach; ?>
				</p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Add Webhook', 'wc-multicurrency-switcher' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function handle_add( array $post ): void {
		$url = esc_url_raw( $post['url'] ?? '' );

		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Please enter a valid URL.', 'wc-multicurrency-switcher' ) . '</p></div>';
			return;
		}

		$events = isset( $post['events'] ) && is_array( $post['events'] ) ? array_map( 'sanitize_text_field', $post['events'] ) : array();

		if ( empty( $events ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Select at least one event.', 'wc-multicurrency-switcher' ) . '</p></div>';
			return;
		}

		WebhookService::register( $url, $events );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Webhook added.', 'wc-multicurrency-switcher' ) . '</p></div>';
	}
}
