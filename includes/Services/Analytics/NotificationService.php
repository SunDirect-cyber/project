<?php
namespace WCMCS\Services\Analytics;

use WCMCS\Services\CurrencyService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The two email types this feature sends: a periodic currency
 * performance summary, and an anomaly alert. Both are plain,
 * information-dense text emails — a store owner reading this on a
 * phone doesn't need HTML styling, just the numbers.
 */
class NotificationService {

	private CurrencyStatsRepository $statsRepository;
	private CurrencyService $currencyService;

	public function __construct( CurrencyStatsRepository $statsRepository, CurrencyService $currencyService ) {
		$this->statsRepository = $statsRepository;
		$this->currencyService  = $currencyService;
	}

	/**
	 * @return string[] Recipient addresses, falling back to the site admin email if none configured.
	 */
	public static function recipients(): array {
		$configured = (string) get_option( 'wcmcs_notification_recipients', '' );
		$emails     = array_filter( array_map( 'trim', explode( ',', $configured ) ), 'is_email' );

		if ( empty( $emails ) ) {
			$admin = get_option( 'admin_email' );
			return $admin ? array( $admin ) : array();
		}

		return array_values( $emails );
	}

	public function sendSummary( string $period ): void {
		$recipients = self::recipients();

		if ( empty( $recipients ) ) {
			return;
		}

		$days  = 'weekly' === $period ? 7 : 1;
		$from  = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$to    = gmdate( 'Y-m-d', strtotime( '-1 day' ) ); // yesterday — today isn't over yet.
		$totals = $this->statsRepository->totalsByCurrency( $from, $to );

		if ( empty( $totals ) ) {
			return; // Nothing to report — don't send an empty email.
		}

		$siteName = get_bloginfo( 'name' );
		$subject  = sprintf(
			/* translators: 1: site name, 2: "daily" or "weekly" */
			__( '[%1$s] %2$s currency performance summary', 'wc-multicurrency-switcher' ),
			$siteName,
			'weekly' === $period ? __( 'Weekly', 'wc-multicurrency-switcher' ) : __( 'Daily', 'wc-multicurrency-switcher' )
		);

		$lines = array(
			sprintf( __( 'Currency performance for %1$s to %2$s:', 'wc-multicurrency-switcher' ), $from, $to ),
			'',
		);

		arsort( $totals );

		foreach ( $totals as $currency => $data ) {
			$lines[] = sprintf(
				'%s: %d orders, %s revenue (%s %s equivalent), avg order value %s',
				$currency,
				$data['order_count'],
				number_format( $data['revenue'], 2 ) . ' ' . $currency,
				number_format( $data['revenue_base_currency'], 2 ),
				$this->currencyService->baseCurrency()->code(),
				number_format( $data['average_order_value'], 2 ) . ' ' . $currency
			);
		}

		wp_mail( $recipients, $subject, implode( "\n", $lines ) );
	}

	/**
	 * @param array{conversion_drops: array, zero_sales: array, rate_stale: bool} $anomalies
	 */
	public function sendAnomalyAlert( array $anomalies ): void {
		$recipients = self::recipients();

		if ( empty( $recipients ) ) {
			return;
		}

		$lines = array( __( 'The Multi-Currency Switcher plugin detected the following:', 'wc-multicurrency-switcher' ), '' );

		foreach ( $anomalies['conversion_drops'] as $drop ) {
			$lines[] = sprintf(
				/* translators: 1: currency, 2: drop percent, 3: recent avg, 4: baseline avg */
				__( '- %1$s orders dropped %2$s%% (now averaging %3$s/day, was %4$s/day)', 'wc-multicurrency-switcher' ),
				$drop['currency'],
				$drop['drop_percent'],
				$drop['recent_avg'],
				$drop['baseline_avg']
			);
		}

		foreach ( $anomalies['zero_sales'] as $zero ) {
			$lines[] = sprintf(
				/* translators: 1: currency, 2: number of days */
				__( '- %1$s has had visitor interest but zero completed orders in the last %2$d days', 'wc-multicurrency-switcher' ),
				$zero['currency'],
				$zero['days']
			);
		}

		if ( count( $lines ) <= 2 ) {
			return; // Nothing actually flagged.
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Currency performance alert', 'wc-multicurrency-switcher' ),
			get_bloginfo( 'name' )
		);

		wp_mail( $recipients, $subject, implode( "\n", $lines ) );
	}
}
