<?php
declare( strict_types=1 );

namespace WCMCS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Analytics" admin screen: revenue-by-currency totals, a line chart of
 * the daily trend, a pie chart of currency distribution, time-range
 * filtering (today/7d/30d/custom/year-over-year), all driven by
 * AnalyticsAjaxController reading the pre-aggregated stats table.
 */
class AnalyticsPage {

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style( 'wcmcs-admin', WCMCS_URL . 'assets/css/admin.css', array(), WCMCS_VERSION );
		wp_enqueue_script( 'wcmcs-chart-lite', WCMCS_URL . 'assets/js/chart-lite.js', array(), WCMCS_VERSION, true );
		wp_enqueue_script( 'wcmcs-analytics', WCMCS_URL . 'assets/js/analytics.js', array( 'wcmcs-chart-lite' ), WCMCS_VERSION, true );

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = \WCMCS\Core\Plugin::instance()->container()->get( 'currency_service' );

		wp_localize_script(
			'wcmcs-analytics',
			'wcmcsAnalytics',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( AnalyticsAjaxController::NONCE ),
				'baseCurrency' => $currencyService->baseCurrency()->code(),
				'i18n'         => array(
					'loading'  => __( 'Loading…', 'wc-multicurrency-switcher' ),
					'noData'   => __( 'No data for this period yet.', 'wc-multicurrency-switcher' ),
				),
			)
		);

		?>
		<div class="wrap wcmcs-analytics">
			<h1><?php esc_html_e( 'Analytics', 'wc-multicurrency-switcher' ); ?></h1>

			<div class="wcmcs-analytics__controls">
				<button type="button" class="button" data-wcmcs-range="today"><?php esc_html_e( 'Today', 'wc-multicurrency-switcher' ); ?></button>
				<button type="button" class="button" data-wcmcs-range="7"><?php esc_html_e( 'Last 7 days', 'wc-multicurrency-switcher' ); ?></button>
				<button type="button" class="button" data-wcmcs-range="30"><?php esc_html_e( 'Last 30 days', 'wc-multicurrency-switcher' ); ?></button>
				<input type="date" id="wcmcs-range-from">
				<span>&ndash;</span>
				<input type="date" id="wcmcs-range-to">
				<button type="button" class="button" id="wcmcs-range-custom"><?php esc_html_e( 'Apply', 'wc-multicurrency-switcher' ); ?></button>
				<label>
					<input type="checkbox" id="wcmcs-compare-yoy">
					<?php esc_html_e( 'Compare to same period last year', 'wc-multicurrency-switcher' ); ?>
				</label>
			</div>

			<div id="wcmcs-analytics-status" class="wcmcs-status"></div>

			<div class="wcmcs-analytics__charts">
				<div>
					<h2><?php esc_html_e( 'Revenue Trend', 'wc-multicurrency-switcher' ); ?></h2>
					<canvas id="wcmcs-trend-chart" width="640" height="280"></canvas>
				</div>
				<div>
					<h2><?php esc_html_e( 'Currency Distribution', 'wc-multicurrency-switcher' ); ?></h2>
					<canvas id="wcmcs-distribution-chart" width="320" height="220"></canvas>
				</div>
			</div>

			<h2><?php esc_html_e( 'Revenue by Currency', 'wc-multicurrency-switcher' ); ?></h2>
			<table class="widefat striped" id="wcmcs-totals-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Currency', 'wc-multicurrency-switcher' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'wc-multicurrency-switcher' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'wc-multicurrency-switcher' ); ?></th>
						<th><?php esc_html_e( 'Avg. Order Value', 'wc-multicurrency-switcher' ); ?></th>
						<th><?php esc_html_e( 'Revenue (base currency)', 'wc-multicurrency-switcher' ); ?></th>
						<th id="wcmcs-yoy-header" hidden><?php esc_html_e( 'vs. Last Year', 'wc-multicurrency-switcher' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}
}
