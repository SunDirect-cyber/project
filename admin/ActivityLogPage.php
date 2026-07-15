<?php
namespace WCMCS\Admin;

use WCMCS\Core\Plugin;
use WCMCS\Services\ActivityLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Activity Log" admin screen — who changed what setting and when,
 * across every admin screen that calls ActivityLogger::record().
 */
class ActivityLogPage {

	private const PER_PAGE = 20;

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style( 'wcmcs-admin', WCMCS_URL . 'assets/css/admin.css', array(), WCMCS_VERSION );

		/** @var \WCMCS\Services\LoggerService $logger */
		$logger = Plugin::instance()->container()->get( 'logger_service' );

		$page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total   = $logger->countByLevel( ActivityLogger::LEVEL );
		$entries = $logger->paginate( ActivityLogger::LEVEL, $page, self::PER_PAGE );
		$pages   = (int) ceil( $total / self::PER_PAGE );

		?>
		<div class="wrap wcmcs-activity-log">
			<h1><?php esc_html_e( 'Activity Log', 'wc-multicurrency-switcher' ); ?></h1>

			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'No settings have been changed yet.', 'wc-multicurrency-switcher' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'User', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Action', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Details', 'wc-multicurrency-switcher' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php
							$context = $entry['context'];
							$user    = $context['user_login'] ?? __( 'system', 'wc-multicurrency-switcher' );
							unset( $context['user_id'], $context['user_login'] );
							?>
							<tr>
								<td><?php echo esc_html( $entry['created_at'] ); ?></td>
								<td><?php echo esc_html( $user ); ?></td>
								<td><?php echo esc_html( $entry['message'] ); ?></td>
								<td><code><?php echo esc_html( empty( $context ) ? '' : wp_json_encode( $context ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<p class="wcmcs-pagination">
						<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
							<?php if ( $i === $page ) : ?>
								<strong><?php echo esc_html( (string) $i ); ?></strong>
							<?php else : ?>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>"><?php echo esc_html( (string) $i ); ?></a>
							<?php endif; ?>
						<?php endfor; ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
