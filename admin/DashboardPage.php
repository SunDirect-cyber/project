<?php
namespace WCMCS\Admin;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's landing admin page: at-a-glance stat tiles, the health
 * check panel, and a revenue-by-currency breakdown — everything a store
 * owner needs to answer "is this working, and is it worth it" without
 * digging into individual settings screens.
 */
class DashboardPage {

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$container = Plugin::instance()->container();

		/** @var \WCMCS\Services\StatsService $stats */
		$stats = $container->get( 'stats_service' );
		/** @var \WCMCS\Services\RevenueByCurrencyReport $revenueReport */
		$revenueReport = $container->get( 'revenue_by_currency_report' );
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );

		$enabledCount = count( (array) get_option( 'wcmcs_enabled_currencies', array() ) );
		$lastSuccess  = (int) get_option( 'wcmcs_last_rate_success_at', 0 );
		$conversions  = $stats->totalConversions( 30 );
		$revenue      = $revenueReport->revenueByCurrency( 30 );
		$health       = DashboardHealthCheck::run();
		$base         = $currencyService->baseCurrency()->code();

		?>
		<div class="wrap wcmcs-dashboard">
			<h1><?php esc_html_e( 'Multi-Currency Dashboard', 'wc-multicurrency-switcher' ); ?></h1>

			<div class="wcmcs-tiles">
				<div class="wcmcs-tile">
					<span class="wcmcs-tile__value"><?php echo esc_html( (string) $enabledCount ); ?></span>
					<span class="wcmcs-tile__label"><?php esc_html_e( 'Active currencies', 'wc-multicurrency-switcher' ); ?></span>
				</div>
				<div class="wcmcs-tile">
					<span class="wcmcs-tile__value">
						<?php echo $lastSuccess > 0 ? esc_html( human_time_diff( $lastSuccess ) . ' ' . __( 'ago', 'wc-multicurrency-switcher' ) ) : esc_html__( 'Never', 'wc-multicurrency-switcher' ); ?>
					</span>
					<span class="wcmcs-tile__label"><?php esc_html_e( 'Last rate sync', 'wc-multicurrency-switcher' ); ?></span>
				</div>
				<div class="wcmcs-tile">
					<span class="wcmcs-tile__value"><?php echo esc_html( number_format_i18n( $conversions ) ); ?></span>
					<span class="wcmcs-tile__label"><?php esc_html_e( 'Conversions served (30 days)', 'wc-multicurrency-switcher' ); ?></span>
				</div>
			</div>

			<h2><?php esc_html_e( 'Health Check', 'wc-multicurrency-switcher' ); ?></h2>
			<ul class="wcmcs-health-list">
				<?php foreach ( $health as $item ) : ?>
					<li class="wcmcs-health-item wcmcs-health-item--<?php echo esc_attr( $item['level'] ); ?>">
						<?php echo esc_html( self::levelIcon( $item['level'] ) ); ?>
						<?php echo esc_html( $item['message'] ); ?>
					</li>
				<?php endforeach; ?>
				<?php if ( empty( $health ) ) : ?>
					<li class="wcmcs-health-item wcmcs-health-item--ok">✓ <?php esc_html_e( 'Everything looks good.', 'wc-multicurrency-switcher' ); ?></li>
				<?php endif; ?>
			</ul>

			<h2><?php esc_html_e( 'Revenue by Currency (30 days)', 'wc-multicurrency-switcher' ); ?></h2>
			<?php if ( empty( $revenue ) ) : ?>
				<p><?php esc_html_e( 'No paid orders in this period yet.', 'wc-multicurrency-switcher' ); ?></p>
			<?php else : ?>
				<?php $max = max( $revenue ); ?>
				<div class="wcmcs-revenue-bars">
					<?php foreach ( $revenue as $code => $total ) : ?>
						<div class="wcmcs-revenue-row">
							<span class="wcmcs-revenue-row__label">
								<?php echo esc_html( $code ); ?>
								<?php if ( $code === $base ) : ?>
									<em>(<?php esc_html_e( 'base', 'wc-multicurrency-switcher' ); ?>)</em>
								<?php endif; ?>
							</span>
							<span class="wcmcs-revenue-row__bar-track">
								<span class="wcmcs-revenue-row__bar" style="width: <?php echo esc_attr( (string) ( $max > 0 ? round( ( $total / $max ) * 100, 1 ) : 0 ) ); ?>%"></span>
							</span>
							<span class="wcmcs-revenue-row__value"><?php echo esc_html( $currencyService->format( $total, $code ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function levelIcon( string $level ): string {
		switch ( $level ) {
			case 'error':
				return '✕';
			case 'warning':
				return '⚠';
			default:
				return '✓';
		}
	}
}
