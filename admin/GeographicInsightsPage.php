<?php
declare( strict_types=1 );

namespace WCMCS\Admin;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Geographic & Behavioral Insights" admin screen: country-vs-currency
 * mismatches, switch abandonment, and the most common switch pairs —
 * all reading GeographicInsightsReport, which itself reads the
 * wcmcs_currency_events table EventTracker writes to.
 */
class GeographicInsightsPage {

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style( 'wcmcs-admin', WCMCS_URL . 'assets/css/admin.css', array(), WCMCS_VERSION );

		// The underlying report reads wcmcs_currency_events, whose
		// created_at is written in site-local time (see EventTracker) —
		// so the default range needs to match that, not UTC, or the
		// default "last 30 days" would silently drop or include an extra
		// day's worth of events depending on the site's UTC offset.
		$from = isset( $_GET['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ) ? sanitize_text_field( $_GET['from'] ) : gmdate( 'Y-m-d', current_time( 'timestamp' ) - ( 30 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to   = isset( $_GET['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ) ? sanitize_text_field( $_GET['to'] ) : gmdate( 'Y-m-d', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		/** @var \WCMCS\Services\Analytics\GeographicInsightsReport $report */
		$report = Plugin::instance()->container()->get( 'geographic_insights_report' );

		$countryData = $report->countryVsCurrency( $from, $to );
		$pairs       = $report->mostSwitchedPairs( $from, $to );
		$abandonment = $report->abandonment( $from, $to );

		?>
		<div class="wrap wcmcs-geo-insights">
			<h1><?php esc_html_e( 'Geographic & Behavioral Insights', 'wc-multicurrency-switcher' ); ?></h1>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( AdminMenu::SLUG_GEO_INSIGHTS ); ?>">
				<label><?php esc_html_e( 'From', 'wc-multicurrency-switcher' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label>
				<label><?php esc_html_e( 'To', 'wc-multicurrency-switcher' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Update', 'wc-multicurrency-switcher' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Country vs. Currency', 'wc-multicurrency-switcher' ); ?></h2>
			<p><?php esc_html_e( 'Which countries are visiting, and which currency they most often end up with. A flagged mismatch means visitors from that country aren\'t landing on their own currency as often as expected — worth checking whether it\'s enabled, or whether the switcher is visible enough.', 'wc-multicurrency-switcher' ); ?></p>
			<?php if ( empty( $countryData ) ) : ?>
				<p><em><?php esc_html_e( 'No visit data for this period yet.', 'wc-multicurrency-switcher' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Country', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Visits', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Top Currency', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Share', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Expected Currency', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Mismatch?', 'wc-multicurrency-switcher' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $countryData as $country => $row ) : ?>
							<tr>
								<td><?php echo esc_html( $country ); ?></td>
								<td><?php echo esc_html( (string) $row['visits'] ); ?></td>
								<td><?php echo esc_html( $row['top_currency'] ?? '—' ); ?></td>
								<td><?php echo esc_html( $row['top_currency_share'] . '%' ); ?></td>
								<td><?php echo esc_html( $row['expected_currency'] ?? '—' ); ?></td>
								<td><?php echo $row['mismatch'] ? '<span class="wcmcs-status--error">' . esc_html__( 'Yes', 'wc-multicurrency-switcher' ) . '</span>' : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Switch Abandonment', 'wc-multicurrency-switcher' ); ?></h2>
			<p><?php esc_html_e( 'Sessions that switched currency (at least 48 hours ago, to give them a fair chance to check out) but never placed an order.', 'wc-multicurrency-switcher' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Total switches:', 'wc-multicurrency-switcher' ); ?> <?php echo esc_html( (string) $abandonment['total_switches'] ); ?></li>
				<li><?php esc_html_e( 'Abandoned (no order followed):', 'wc-multicurrency-switcher' ); ?> <?php echo esc_html( (string) $abandonment['abandoned'] ); ?></li>
				<li><strong><?php esc_html_e( 'Abandonment rate:', 'wc-multicurrency-switcher' ); ?> <?php echo esc_html( $abandonment['abandonment_rate'] . '%' ); ?></strong></li>
			</ul>

			<h2><?php esc_html_e( 'Most Switched Pairs', 'wc-multicurrency-switcher' ); ?></h2>
			<?php if ( empty( $pairs ) ) : ?>
				<p><em><?php esc_html_e( 'No switches in this period yet.', 'wc-multicurrency-switcher' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'From', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'To', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Switches', 'wc-multicurrency-switcher' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pairs as $pair ) : ?>
							<tr>
								<td><?php echo esc_html( $pair['from'] ); ?></td>
								<td><?php echo esc_html( $pair['to'] ); ?></td>
								<td><?php echo esc_html( (string) $pair['count'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
