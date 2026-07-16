<?php
declare( strict_types=1 );

namespace WCMCS\Admin;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Exchange Rates" admin page: a per-currency historical rate chart
 * (rendered with our own small canvas line-chart script — no external
 * charting library dependency), a "Refresh Rates Now" button, and a
 * rollback control for reverting to a previous rate snapshot.
 */
class RateHistoryPage {

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );
		$base            = $currencyService->baseCurrency()->code();
		$enabled         = (array) get_option( 'wcmcs_enabled_currencies', array() );

		?>
		<div class="wrap wcmcs-exchange-rates">
			<h1><?php esc_html_e( 'Exchange Rates', 'wc-multicurrency-switcher' ); ?></h1>

			<?php if ( empty( $enabled ) ) : ?>
				<p>
					<?php esc_html_e( 'No currencies are enabled yet. Enable at least one currency in the plugin settings to see rate history here.', 'wc-multicurrency-switcher' ); ?>
				</p>
				<?php return; ?>
			<?php endif; ?>

			<p class="wcmcs-base-currency">
				<?php
				printf(
					/* translators: %s: base currency code */
					esc_html__( 'Base currency: %s', 'wc-multicurrency-switcher' ),
					'<strong>' . esc_html( $base ) . '</strong>'
				);
				?>
			</p>

			<div class="wcmcs-toolbar">
				<label for="wcmcs-target-currency"><?php esc_html_e( 'Currency', 'wc-multicurrency-switcher' ); ?></label>
				<select id="wcmcs-target-currency">
					<?php foreach ( $enabled as $code ) : ?>
						<?php
						$code     = strtoupper( $code );
						$currency = $currencyService->get( $code );
						?>
						<option value="<?php echo esc_attr( $code ); ?>">
							<?php echo esc_html( $currency ? "{$code} — {$currency->name()}" : $code ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<button type="button" class="button button-primary" id="wcmcs-refresh-now">
					<?php esc_html_e( 'Refresh Rates Now', 'wc-multicurrency-switcher' ); ?>
				</button>

				<span id="wcmcs-refresh-status" class="wcmcs-status" aria-live="polite"></span>
			</div>

			<canvas id="wcmcs-rate-chart" width="900" height="320" role="img"
				aria-label="<?php esc_attr_e( 'Exchange rate history chart', 'wc-multicurrency-switcher' ); ?>">
			</canvas>

			<h2><?php esc_html_e( 'History', 'wc-multicurrency-switcher' ); ?></h2>
			<table class="widefat striped" id="wcmcs-history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'wc-multicurrency-switcher' ); ?></th>
						<th><?php esc_html_e( 'Rate', 'wc-multicurrency-switcher' ); ?></th>
						<th><?php esc_html_e( 'Source', 'wc-multicurrency-switcher' ); ?></th>
						<th><?php esc_html_e( 'Action', 'wc-multicurrency-switcher' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}

	public static function enqueue(): void {
		wp_enqueue_style(
			'wcmcs-admin',
			WCMCS_URL . 'assets/css/admin.css',
			array(),
			WCMCS_VERSION
		);

		wp_enqueue_script(
			'wcmcs-rate-history',
			WCMCS_URL . 'assets/js/rate-history.js',
			array(),
			WCMCS_VERSION,
			true
		);

		wp_localize_script(
			'wcmcs-rate-history',
			'wcmcsRateHistory',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'historyNonce'     => wp_create_nonce( RateHistoryAjaxController::NONCE ),
				'refreshNonce'     => wp_create_nonce( RateAjaxController::nonceAction() ),
				'refreshNonceField' => RateAjaxController::nonceName(),
				'baseCurrency'     => $base,
				'i18n'             => array(
					'refreshing'    => __( 'Refreshing…', 'wc-multicurrency-switcher' ),
					'refreshDone'   => __( 'Rates refreshed.', 'wc-multicurrency-switcher' ),
					'refreshFailed' => __( 'Refresh failed.', 'wc-multicurrency-switcher' ),
					'confirmRollback' => __( 'Revert to this rate? This will be recorded as a new history entry.', 'wc-multicurrency-switcher' ),
					'rollbackDone'  => __( 'Rate reverted.', 'wc-multicurrency-switcher' ),
					'rollbackFailed' => __( 'Rollback failed.', 'wc-multicurrency-switcher' ),
					'loading'       => __( 'Loading…', 'wc-multicurrency-switcher' ),
					'noData'        => __( 'No history yet for this currency.', 'wc-multicurrency-switcher' ),
				),
			)
		);
	}
}
