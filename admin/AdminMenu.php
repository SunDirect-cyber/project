<?php
namespace WCMCS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's top-level admin menu. Kept as a single small
 * class that just wires up menu slugs to page-render callbacks — each
 * page's actual markup/behaviour lives in its own class, so this file
 * stays a thin table of contents as more admin screens get added.
 */
class AdminMenu {

	public const CAPABILITY = \WCMCS\Core\CapabilityManager::CAP;
	public const SLUG       = 'wcmcs';
	public const SLUG_EXCHANGE_RATES = 'wcmcs-exchange-rates';
	public const SLUG_GATEWAYS       = 'wcmcs-gateways';
	public const SLUG_CURRENCIES     = 'wcmcs-currencies';
	public const SLUG_PROVIDERS      = 'wcmcs-providers';
	public const SLUG_DISPLAY        = 'wcmcs-display';
	public const SLUG_IMPORT_EXPORT  = 'wcmcs-import-export';
	public const SLUG_ACTIVITY_LOG   = 'wcmcs-activity-log';
	public const SLUG_ANALYTICS      = 'wcmcs-analytics';

	private static string $exchangeRatesHook = '';

	/** @var string[] Hook suffixes of every wcmcs admin page, for the CSS-only enqueue. */
	private static array $allHooks = array();

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue' ) );
	}

	public static function add_menu(): void {
		add_menu_page(
			__( 'Multi-Currency', 'wc-multicurrency-switcher' ),
			__( 'Multi-Currency', 'wc-multicurrency-switcher' ),
			self::CAPABILITY,
			self::SLUG,
			array( DashboardPage::class, 'render' ),
			'dashicons-money-alt'
		);

		$dashboardHook = add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'wc-multicurrency-switcher' ),
			__( 'Dashboard', 'wc-multicurrency-switcher' ),
			self::CAPABILITY,
			self::SLUG,
			array( DashboardPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Currencies', 'wc-multicurrency-switcher' ),
			__( 'Currencies', 'wc-multicurrency-switcher' ),
			self::CAPABILITY,
			self::SLUG_CURRENCIES,
			array( CurrencyManagementPage::class, 'render' )
		);

		self::$exchangeRatesHook = add_submenu_page(
			self::SLUG,
			__( 'Exchange Rates', 'wc-multicurrency-switcher' ),
			__( 'Exchange Rates', 'wc-multicurrency-switcher' ),
			self::CAPABILITY,
			self::SLUG_EXCHANGE_RATES,
			array( RateHistoryPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Rate Providers', 'wc-multicurrency-switcher' ),
			__( 'Rate Providers', 'wc-multicurrency-switcher' ),
			self::CAPABILITY,
			self::SLUG_PROVIDERS,
			array( RateProviderConfigPage::class, 'render' )
		);

		$gatewaysHook = add_submenu_page(
			self::SLUG,
			__( 'Payment Gateways', 'wc-multicurrency-switcher' ),
			__( 'Payment Gateways', 'wc-multicurrency-switcher' ),
			self::CAPABILITY,
			self::SLUG_GATEWAYS,
			array( GatewayMatrixPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Display & Behavior', 'wc-multicurrency-switcher' ),
			__( 'Display & Behavior', 'wc-multicurrency-switcher' ),
			self::CAPABILITY,
			self::SLUG_DISPLAY,
			array( DisplaySettingsPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Analytics', 'wc-multicurrency-switcher' ),
			__( 'Analytics', 'wc-multicurrency-switcher' ),
			self::CAPABILITY,
			self::SLUG_ANALYTICS,
			array( AnalyticsPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Import / Export', 'wc-multicurrency-switcher' ),
			__( 'Import / Export', 'wc-multicurrency-switcher' ),
			self::CAPABILITY,
			self::SLUG_IMPORT_EXPORT,
			array( ImportExportPage::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Activity Log', 'wc-multicurrency-switcher' ),
			__( 'Activity Log', 'wc-multicurrency-switcher' ),
			self::CAPABILITY,
			self::SLUG_ACTIVITY_LOG,
			array( ActivityLogPage::class, 'render' )
		);

		self::$allHooks = array_filter( array( $dashboardHook, self::$exchangeRatesHook, $gatewaysHook ) );
	}

	/**
	 * Only loads this page's JS/CSS on this page — never globally across
	 * wp-admin — by comparing against the hook suffix WordPress itself
	 * generated for it.
	 */
	public static function maybe_enqueue( string $hook ): void {
		if ( ! in_array( $hook, self::$allHooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'wcmcs-admin', WCMCS_URL . 'assets/css/admin.css', array(), WCMCS_VERSION );

		if ( $hook === self::$exchangeRatesHook ) {
			RateHistoryPage::enqueue();
		}
	}
}
