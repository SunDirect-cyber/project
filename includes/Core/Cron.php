<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and runs the recurring exchange-rate refresh. The interval
 * is store-configurable (hourly / every 6 hours / daily, via the
 * 'wcmcs_rate_refresh_interval' option) rather than fixed, and changing
 * that setting reschedules the existing cron event instead of leaving
 * a stale one running alongside a new one.
 */
class Cron {

	public const EVENT_HOOK = 'wcmcs_refresh_exchange_rates';

	public const INTERVALS = array(
		'hourly'     => array( 'seconds' => HOUR_IN_SECONDS, 'label' => 'Hourly' ),
		'six_hours'  => array( 'seconds' => HOUR_IN_SECONDS * 6, 'label' => 'Every 6 hours' ),
		'daily'      => array( 'seconds' => DAY_IN_SECONDS, 'label' => 'Daily' ),
	);

	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'register_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::EVENT_HOOK, array( self::class, 'run' ) );
		add_action( 'update_option_wcmcs_rate_refresh_interval', array( self::class, 'reschedule' ), 10, 2 );
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function register_schedules( array $schedules ): array {
		foreach ( self::INTERVALS as $slug => $config ) {
			if ( 'hourly' === $slug ) {
				continue; // WordPress already registers this one.
			}

			$schedules[ $slug ] = array(
				'interval' => $config['seconds'],
				'display'  => __( $config['label'], 'wc-multicurrency-switcher' ),
			);
		}

		return $schedules;
	}

	public static function schedule(): void {
		$interval = self::currentIntervalSlug();

		if ( wp_next_scheduled( self::EVENT_HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), $interval, self::EVENT_HOOK );
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::EVENT_HOOK );
	}

	/**
	 * Fired automatically whenever 'wcmcs_rate_refresh_interval' changes,
	 * so picking a new interval in admin takes effect immediately instead
	 * of waiting for the previously-scheduled interval to elapse first.
	 */
	public static function reschedule( $old_value, $new_value ): void {
		if ( $old_value === $new_value ) {
			return;
		}

		self::unschedule();
		self::schedule();
	}

	/**
	 * Runs on the scheduled cron hook: refreshes every enabled currency
	 * against the store's base currency, then checks whether we should
	 * alert the admin about stale rates.
	 */
	public static function run(): void {
		$plugin = Plugin::instance();

		if ( ! $plugin->container()->has( 'rate_service' ) ) {
			return;
		}

		/** @var \WCMCS\Services\ExchangeRate\RateService $rateService */
		$rateService     = $plugin->container()->get( 'rate_service' );
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $plugin->container()->get( 'currency_service' );

		$base    = $currencyService->baseCurrency()->code();
		$targets = (array) get_option( 'wcmcs_enabled_currencies', array() );

		$results = $rateService->refreshAll( $base, $targets );

		// Distinct from RateFailureMonitor's "last *successful* sync"
		// timestamp — this is "last time the cron actually ran, and what
		// happened", which the admin's Rate Providers screen shows even
		// when every currency happened to fail.
		$anySuccess = false;
		foreach ( $results as $result ) {
			if ( null !== $result['rate'] ) {
				$anySuccess = true;
				break;
			}
		}
		update_option( 'wcmcs_last_cron_run_at', time() );
		update_option( 'wcmcs_last_cron_result', empty( $results ) ? 'skipped' : ( $anySuccess ? 'success' : 'failure' ) );

		if ( $plugin->container()->has( 'webhook_service' ) ) {
			/** @var \WCMCS\Services\WebhookService $webhooks */
			$webhooks = $plugin->container()->get( 'webhook_service' );

			foreach ( $results as $currency => $result ) {
				if ( null !== $result['rate'] ) {
					$webhooks->trigger(
						\WCMCS\Services\WebhookService::EVENT_RATE_UPDATED,
						array( 'base' => $base, 'currency' => $currency, 'rate' => $result['rate'], 'source' => $result['source'] )
					);
				}
			}
		}

		/** @var \WCMCS\Core\RateFailureMonitor $monitor */
		$monitor = $plugin->container()->get( 'rate_failure_monitor' );
		$monitor->recordRefreshResult( $results );

		if ( $plugin->container()->has( 'stats_service' ) ) {
			$plugin->container()->get( 'stats_service' )->pruneOldCounters();
		}
	}

	public static function currentIntervalSlug(): string {
		$slug = (string) get_option( 'wcmcs_rate_refresh_interval', 'hourly' );

		return isset( self::INTERVALS[ $slug ] ) ? $slug : 'hourly';
	}

	public static function intervalToSeconds( string $slug ): int {
		return self::INTERVALS[ $slug ]['seconds'] ?? self::INTERVALS['hourly']['seconds'];
	}
}
