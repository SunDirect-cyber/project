<?php
declare( strict_types=1 );

namespace WCMCS\Admin;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Rate Correlation" admin screen: a currency's daily exchange rate
 * stacked above its daily sales for the same range, so a dip in sales
 * that lines up with a rate spike is visible at a glance.
 */
class RateCorrelationPage {

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style( 'wcmcs-admin', WCMCS_URL . 'assets/css/admin.css', array(), WCMCS_VERSION );
		wp_enqueue_script( 'wcmcs-chart-lite', WCMCS_URL . 'assets/js/chart-lite.js', array(), WCMCS_VERSION, true );
		wp_enqueue_script( 'wcmcs-rate-correlation', WCMCS_URL . 'assets/js/rate-correlation.js', array( 'wcmcs-chart-lite' ), WCMCS_VERSION, true );

		$enabled = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );
		$base            = $currencyService->baseCurrency()->code();
		$nonBase         = array_values( array_diff( $enabled, array( $base ) ) );

		wp_localize_script(
			'wcmcs-rate-correlation',
			'wcmcsRateCorrelation',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( RateCorrelationAjaxController::NONCE ),
			)
		);

		?>
		<div class="wrap wcmcs-rate-correlation">
			<h1><?php esc_html_e( 'Rate Correlation', 'wc-multicurrency-switcher' ); ?></h1>

			<?php if ( empty( $nonBase ) ) : ?>
				<p><?php esc_html_e( 'Enable at least one non-base currency to see this report.', 'wc-multicurrency-switcher' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<div class="wcmcs-analytics__controls">
				<select id="wcmcs-correlation-currency">
					<?php foreach ( $nonBase as $code ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $code ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="date" id="wcmcs-correlation-from" value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ); ?>">
				<span>&ndash;</span>
				<input type="date" id="wcmcs-correlation-to" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
				<button type="button" class="button button-primary" id="wcmcs-correlation-update"><?php esc_html_e( 'Update', 'wc-multicurrency-switcher' ); ?></button>
			</div>

			<h2><?php esc_html_e( 'Exchange Rate', 'wc-multicurrency-switcher' ); ?></h2>
			<canvas id="wcmcs-correlation-rate-chart" width="800" height="220"></canvas>

			<h2><?php esc_html_e( 'Sales Revenue', 'wc-multicurrency-switcher' ); ?></h2>
			<canvas id="wcmcs-correlation-sales-chart" width="800" height="220"></canvas>
		</div>
		<?php
	}
}
