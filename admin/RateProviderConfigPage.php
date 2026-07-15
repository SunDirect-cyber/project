<?php
namespace WCMCS\Admin;

use WCMCS\Core\Cron;
use WCMCS\Core\EncryptionService;
use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Rate Providers" admin screen: API keys with live connection testing,
 * drag-and-drop priority order (the same order RateProviderChain
 * actually tries them in), the sync frequency, and a visual read-out of
 * the cron job's own status.
 */
class RateProviderConfigPage {

	private const LABELS = array(
		'exchangerate-api'  => 'exchangerate-api.com',
		'ecb'               => 'European Central Bank (free, no key)',
		'openexchangerates' => 'Open Exchange Rates',
		'fixer'             => 'Fixer.io',
		'manual'            => 'Manual rates (fallback)',
	);

	private const KEY_OPTIONS = array(
		'openexchangerates' => 'wcmcs_provider_openexchangerates_key',
		'fixer'              => 'wcmcs_provider_fixer_key',
		'exchangerate-api'   => 'wcmcs_provider_exchangerateapi_key',
	);

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$container = Plugin::instance()->container();

		/** @var \WCMCS\Services\ExchangeRate\ProviderRegistry $registry */
		$registry  = $container->get( 'provider_registry' );
		$providers = $registry->buildOrderedProviders();
		/** @var \WCMCS\Services\LoggerService $logger */
		$logger = $container->get( 'logger_service' );

		wp_enqueue_style( 'wcmcs-admin', WCMCS_URL . 'assets/css/admin.css', array(), WCMCS_VERSION );
		wp_enqueue_script( 'wcmcs-rate-provider-config', WCMCS_URL . 'assets/js/rate-provider-config.js', array(), WCMCS_VERSION, true );
		wp_localize_script(
			'wcmcs-rate-provider-config',
			'wcmcsRateProviderConfig',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( RateProviderConfigAjaxController::NONCE ),
				'i18n'    => array(
					'saved'      => __( 'Saved.', 'wc-multicurrency-switcher' ),
					'saveFailed' => __( 'Save failed.', 'wc-multicurrency-switcher' ),
					'testing'    => __( 'Testing…', 'wc-multicurrency-switcher' ),
				),
			)
		);

		$nextRun     = wp_next_scheduled( Cron::EVENT_HOOK );
		$lastRunAt   = (int) get_option( 'wcmcs_last_cron_run_at', 0 );
		$lastResult  = (string) get_option( 'wcmcs_last_cron_result', '' );
		$lastSuccess = (int) get_option( 'wcmcs_last_rate_success_at', 0 );
		$currentInterval = Cron::currentIntervalSlug();

		?>
		<div class="wrap wcmcs-rate-providers">
			<h1><?php esc_html_e( 'Rate Providers', 'wc-multicurrency-switcher' ); ?></h1>

			<h2><?php esc_html_e( 'Cron Status', 'wc-multicurrency-switcher' ); ?></h2>
			<ul class="wcmcs-cron-status">
				<li>
					<strong><?php esc_html_e( 'Next run:', 'wc-multicurrency-switcher' ); ?></strong>
					<?php echo $nextRun ? esc_html( date_i18n( 'Y-m-d H:i:s', $nextRun ) ) : esc_html__( 'Not scheduled', 'wc-multicurrency-switcher' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Last run:', 'wc-multicurrency-switcher' ); ?></strong>
					<?php if ( $lastRunAt > 0 ) : ?>
						<?php echo esc_html( human_time_diff( $lastRunAt ) . ' ' . __( 'ago', 'wc-multicurrency-switcher' ) ); ?>
						—
						<span class="wcmcs-cron-result wcmcs-cron-result--<?php echo esc_attr( $lastResult ); ?>"><?php echo esc_html( $lastResult ); ?></span>
					<?php else : ?>
						<?php esc_html_e( 'Never run yet', 'wc-multicurrency-switcher' ); ?>
					<?php endif; ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Last successful sync:', 'wc-multicurrency-switcher' ); ?></strong>
					<?php echo $lastSuccess > 0 ? esc_html( human_time_diff( $lastSuccess ) . ' ' . __( 'ago', 'wc-multicurrency-switcher' ) ) : esc_html__( 'Never', 'wc-multicurrency-switcher' ); ?>
				</li>
			</ul>

			<?php $recentErrors = $logger->recent( 'error', 5 ); ?>
			<?php if ( ! empty( $recentErrors ) ) : ?>
				<h3><?php esc_html_e( 'Recent errors', 'wc-multicurrency-switcher' ); ?></h3>
				<ul class="wcmcs-recent-errors">
					<?php foreach ( $recentErrors as $entry ) : ?>
						<li><code><?php echo esc_html( $entry['created_at'] ); ?></code> — <?php echo esc_html( $entry['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Sync Frequency', 'wc-multicurrency-switcher' ); ?></h2>
			<select id="wcmcs-sync-interval">
				<?php foreach ( Cron::INTERVALS as $slug => $config ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $currentInterval, $slug ); ?>><?php echo esc_html( $config['label'] ); ?></option>
				<?php endforeach; ?>
			</select>

			<h2><?php esc_html_e( 'Provider Priority', 'wc-multicurrency-switcher' ); ?></h2>
			<p><?php esc_html_e( 'Drag to reorder. Rates are fetched from the first provider that succeeds, in this order.', 'wc-multicurrency-switcher' ); ?></p>

			<ul id="wcmcs-provider-list" class="wcmcs-provider-list">
				<?php foreach ( $providers as $provider ) : ?>
					<?php
					$slug        = $provider->getSourceName();
					$needsKey    = isset( self::KEY_OPTIONS[ $slug ] );
					// Never echo the real (decrypted) key back into the page
					// source — only whether one is already set, so the
					// password field can show a masked placeholder instead
					// of the live secret.
					$hasKey      = $needsKey && '' !== EncryptionService::getDecryptedOption( self::KEY_OPTIONS[ $slug ] );
					?>
					<li class="wcmcs-provider-row" draggable="true" data-slug="<?php echo esc_attr( $slug ); ?>">
						<span class="wcmcs-provider-row__handle" aria-hidden="true">&#9776;</span>
						<span class="wcmcs-provider-row__label"><?php echo esc_html( self::LABELS[ $slug ] ?? $slug ); ?></span>
						<span class="wcmcs-provider-row__status">
							<?php echo $provider->isConfigured() ? '✓ ' . esc_html__( 'Configured', 'wc-multicurrency-switcher' ) : '— ' . esc_html__( 'No key', 'wc-multicurrency-switcher' ); ?>
						</span>
						<?php if ( $needsKey ) : ?>
							<input type="password" class="regular-text" placeholder="<?php echo esc_attr( $hasKey ? __( '•••••••• (key set — leave blank to keep it)', 'wc-multicurrency-switcher' ) : __( 'API key', 'wc-multicurrency-switcher' ) ); ?>" value="" autocomplete="off" data-wcmcs-key="<?php echo esc_attr( $slug ); ?>">
						<?php endif; ?>
						<button type="button" class="button" data-wcmcs-test="<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Test Connection', 'wc-multicurrency-switcher' ); ?></button>
						<span class="wcmcs-status" data-wcmcs-test-result="<?php echo esc_attr( $slug ); ?>"></span>
					</li>
				<?php endforeach; ?>
			</ul>

			<p>
				<button type="button" class="button button-primary" id="wcmcs-save-providers"><?php esc_html_e( 'Save', 'wc-multicurrency-switcher' ); ?></button>
				<span class="wcmcs-status" id="wcmcs-providers-status"></span>
			</p>
		</div>
		<?php
	}
}
