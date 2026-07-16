<?php
declare( strict_types=1 );

namespace WCMCS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Notifications" admin screen: recipients, summary email frequency,
 * and the anomaly-alerts toggle — both consumed by AnalyticsCron, which
 * runs once daily and decides there whether it's actually time to send
 * either.
 */
class NotificationSettingsPage {

	private const NONCE_ACTION = 'wcmcs_save_notification_settings';

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		if ( isset( $_POST['wcmcs_notifications_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcmcs_notifications_nonce'] ) ), self::NONCE_ACTION ) ) {
			self::save( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'wc-multicurrency-switcher' ) . '</p></div>';
		}

		$recipients = (string) get_option( 'wcmcs_notification_recipients', '' );
		$frequency  = (string) get_option( 'wcmcs_notification_summary_frequency', 'off' );
		$anomalies  = ! empty( get_option( 'wcmcs_notification_anomaly_alerts', false ) );

		?>
		<div class="wrap wcmcs-notifications">
			<h1><?php esc_html_e( 'Notifications', 'wc-multicurrency-switcher' ); ?></h1>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, 'wcmcs_notifications_nonce' ); ?>

				<h2><?php esc_html_e( 'Recipients', 'wc-multicurrency-switcher' ); ?></h2>
				<p>
					<label for="wcmcs-notification-recipients"><?php esc_html_e( 'Comma-separated email addresses. Leave blank to use the site admin email.', 'wc-multicurrency-switcher' ); ?></label><br>
					<input type="text" id="wcmcs-notification-recipients" name="recipients" class="regular-text" value="<?php echo esc_attr( $recipients ); ?>">
				</p>

				<h2><?php esc_html_e( 'Performance Summary Email', 'wc-multicurrency-switcher' ); ?></h2>
				<p>
					<label><input type="radio" name="summary_frequency" value="off" <?php checked( $frequency, 'off' ); ?>> <?php esc_html_e( 'Off', 'wc-multicurrency-switcher' ); ?></label><br>
					<label><input type="radio" name="summary_frequency" value="daily" <?php checked( $frequency, 'daily' ); ?>> <?php esc_html_e( 'Daily', 'wc-multicurrency-switcher' ); ?></label><br>
					<label><input type="radio" name="summary_frequency" value="weekly" <?php checked( $frequency, 'weekly' ); ?>> <?php esc_html_e( 'Weekly', 'wc-multicurrency-switcher' ); ?></label>
				</p>

				<h2><?php esc_html_e( 'Anomaly Alerts', 'wc-multicurrency-switcher' ); ?></h2>
				<p>
					<label>
						<input type="checkbox" name="anomaly_alerts" value="1" <?php checked( $anomalies ); ?>>
						<?php esc_html_e( 'Email me when a currency\'s conversions drop sharply, or when a currency has visitor interest but zero sales for a week', 'wc-multicurrency-switcher' ); ?>
					</label>
				</p>
				<p class="description"><?php esc_html_e( 'Unusual rate fetch failures are always alerted separately (see Rate Providers), regardless of this setting.', 'wc-multicurrency-switcher' ); ?></p>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'wc-multicurrency-switcher' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function save( array $post ): void {
		update_option( 'wcmcs_notification_recipients', sanitize_text_field( $post['recipients'] ?? '' ) );

		$frequency = $post['summary_frequency'] ?? 'off';
		if ( in_array( $frequency, array( 'off', 'daily', 'weekly' ), true ) ) {
			update_option( 'wcmcs_notification_summary_frequency', $frequency );
		}

		update_option( 'wcmcs_notification_anomaly_alerts', ! empty( $post['anomaly_alerts'] ) );

		/** @var \WCMCS\Services\ActivityLogger $activity */
		$activity = \WCMCS\Core\Plugin::instance()->container()->get( 'activity_logger' );
		$activity->record( 'Updated notification settings', array( 'summary_frequency' => $frequency, 'anomaly_alerts' => ! empty( $post['anomaly_alerts'] ) ) );
	}
}
