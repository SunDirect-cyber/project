<?php
declare( strict_types=1 );

namespace WCMCS\Admin;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Currency Impact" admin screen: the theoretical gain/loss report
 * (CurrencyImpactReport) with a date range and a CSV export for the
 * store's accountant. CSV export is handled on admin_init (like
 * ImportExportPage's export) since a file download's headers have to go
 * out before any of wp-admin's own page chrome starts rendering.
 */
class CurrencyImpactPage {

	private const EXPORT_ACTION = 'wcmcs_export_currency_impact';

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_handle_export' ) );
	}

	public static function maybe_handle_export(): void {
		if ( ! isset( $_POST['wcmcs_impact_export_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcmcs_impact_export_nonce'] ) ), self::EXPORT_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wc-multicurrency-switcher' ) );
		}

		[ $from, $to ] = self::resolveRange( $_POST );
		$report = self::buildReport()->generate( $from, $to );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wcmcs-currency-impact-' . $from . '-to-' . $to . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		fputcsv( $out, array( 'Order ID', 'Date', 'Currency', 'Order Total', 'Historical Rate', 'Historical Base Value', 'Current Rate', 'Current Base Value', 'Gain/Loss' ) );

		foreach ( $report['rows'] as $row ) {
			fputcsv(
				$out,
				array(
					$row['order_id'],
					$row['date'],
					$row['currency'],
					$row['total'],
					$row['historical_rate'] ?? 'N/A',
					$row['historical_base_value'] ?? 'N/A',
					$row['current_rate'] ?? 'N/A',
					$row['current_base_value'] ?? 'N/A',
					$row['gain_loss'] ?? 'N/A',
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style( 'wcmcs-admin', WCMCS_URL . 'assets/css/admin.css', array(), WCMCS_VERSION );

		[ $from, $to ] = self::resolveRange( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report = self::buildReport()->generate( $from, $to );
		$summary = $report['summary'];

		?>
		<div class="wrap wcmcs-currency-impact">
			<h1><?php esc_html_e( 'Currency Impact Report', 'wc-multicurrency-switcher' ); ?></h1>
			<p><?php esc_html_e( 'Compares what each foreign-currency order was worth in your base currency at the exchange rate active when it was placed, against what that same amount would be worth today. This is a theoretical accounting figure, not a real cash gain or loss — the orders already settled at their historical rate.', 'wc-multicurrency-switcher' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::SLUG_CURRENCY_IMPACT ); ?>">
				<label><?php esc_html_e( 'From', 'wc-multicurrency-switcher' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label>
				<label><?php esc_html_e( 'To', 'wc-multicurrency-switcher' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Generate', 'wc-multicurrency-switcher' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Summary', 'wc-multicurrency-switcher' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'Orders in range:', 'wc-multicurrency-switcher' ); ?> <?php echo esc_html( (string) $summary['order_count'] ); ?></li>
				<li><?php esc_html_e( 'Historical value:', 'wc-multicurrency-switcher' ); ?> <?php echo esc_html( number_format( $summary['total_historical_value'], 2 ) . ' ' . $summary['base_currency'] ); ?></li>
				<li><?php esc_html_e( 'Value at today\'s rates:', 'wc-multicurrency-switcher' ); ?> <?php echo esc_html( number_format( $summary['total_current_value'], 2 ) . ' ' . $summary['base_currency'] ); ?></li>
				<li>
					<strong><?php esc_html_e( 'Theoretical gain/loss:', 'wc-multicurrency-switcher' ); ?></strong>
					<span class="<?php echo $summary['total_gain_loss'] >= 0 ? 'wcmcs-status--ok' : 'wcmcs-status--error'; ?>">
						<?php echo esc_html( ( $summary['total_gain_loss'] >= 0 ? '+' : '' ) . number_format( $summary['total_gain_loss'], 2 ) . ' ' . $summary['base_currency'] ); ?>
					</span>
				</li>
				<?php if ( $summary['orders_missing_data'] > 0 ) : ?>
					<li><em><?php echo esc_html( sprintf(
						/* translators: %d: number of orders */
						__( '%d order(s) predate exchange-rate tracking and are excluded from the totals above.', 'wc-multicurrency-switcher' ),
						$summary['orders_missing_data']
					) ); ?></em></li>
				<?php endif; ?>
			</ul>

			<form method="post">
				<?php wp_nonce_field( self::EXPORT_ACTION, 'wcmcs_impact_export_nonce' ); ?>
				<input type="hidden" name="from" value="<?php echo esc_attr( $from ); ?>">
				<input type="hidden" name="to" value="<?php echo esc_attr( $to ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'wc-multicurrency-switcher' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Orders', 'wc-multicurrency-switcher' ); ?></h2>
			<?php if ( empty( $report['rows'] ) ) : ?>
				<p><?php esc_html_e( 'No orders in this period.', 'wc-multicurrency-switcher' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Date', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Currency', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Total', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Historical Value', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Value Today', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Gain/Loss', 'wc-multicurrency-switcher' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $report['rows'] as $row ) : ?>
							<tr>
								<td>#<?php echo esc_html( (string) $row['order_id'] ); ?></td>
								<td><?php echo esc_html( $row['date'] ); ?></td>
								<td><?php echo esc_html( $row['currency'] ); ?></td>
								<td><?php echo esc_html( number_format( $row['total'], 2 ) ); ?></td>
								<td><?php echo esc_html( null === $row['historical_base_value'] ? '—' : number_format( $row['historical_base_value'], 2 ) ); ?></td>
								<td><?php echo esc_html( null === $row['current_base_value'] ? '—' : number_format( $row['current_base_value'], 2 ) ); ?></td>
								<td><?php echo esc_html( null === $row['gain_loss'] ? 'N/A' : ( ( $row['gain_loss'] >= 0 ? '+' : '' ) . number_format( $row['gain_loss'], 2 ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $source
	 * @return array{0: string, 1: string}
	 */
	private static function resolveRange( array $source ): array {
		$from = isset( $source['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $source['from'] ) ? $source['from'] : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$to   = isset( $source['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $source['to'] ) ? $source['to'] : gmdate( 'Y-m-d' );

		return array( sanitize_text_field( $from ), sanitize_text_field( $to ) );
	}

	private static function buildReport(): \WCMCS\Services\Analytics\CurrencyImpactReport {
		$container = Plugin::instance()->container();

		return new \WCMCS\Services\Analytics\CurrencyImpactReport(
			$container->get( 'rate_service' ),
			$container->get( 'currency_service' )
		);
	}
}
