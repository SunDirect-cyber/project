<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules the daily stats aggregation — deliberately a separate cron
 * event from the rate-refresh one (Cron.php): they run at different,
 * independently-configurable frequencies for different reasons (rates
 * need to be fresh soon after they change; a day's sales can only be
 * aggregated once that day is actually over), and tying them together
 * would mean an hourly rate-refresh interval wastefully re-aggregating
 * the same day's stats every hour.
 */
class AnalyticsCron {

	public const EVENT_HOOK = 'wcmcs_aggregate_daily_stats';

	public static function register(): void {
		add_action( self::EVENT_HOOK, array( self::class, 'run' ) );
	}

	public static function schedule(): void {
		if ( wp_next_scheduled( self::EVENT_HOOK ) ) {
			return;
		}

		// Just after midnight site time, so "yesterday" is fully closed
		// out before it's aggregated.
		$firstRun = strtotime( 'tomorrow 00:30:00' );

		wp_schedule_event( $firstRun, 'daily', self::EVENT_HOOK );
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::EVENT_HOOK );
	}

	public static function run(): void {
		$plugin = Plugin::instance();

		if ( ! $plugin->container()->has( 'stats_aggregation_service' ) ) {
			return;
		}

		/** @var \WCMCS\Services\Analytics\CurrencyStatsAggregationService $aggregator */
		$aggregator = $plugin->container()->get( 'stats_aggregation_service' );
		/** @var \WCMCS\Services\Analytics\CurrencyStatsRepository $repository */
		$repository = $plugin->container()->get( 'stats_repository' );

		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// First run ever: backfill a reasonable window so the analytics
		// screens aren't empty the moment this feature ships on an
		// existing store with order history — rather than only starting
		// to accumulate data from today onward.
		if ( ! $repository->hasAnyData() ) {
			$aggregator->aggregateRange( gmdate( 'Y-m-d', strtotime( '-30 days' ) ), $yesterday );
			return;
		}

		$aggregator->aggregateDate( $yesterday );
	}
}
