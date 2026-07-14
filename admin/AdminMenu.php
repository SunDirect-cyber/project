<?php
namespace WCMCS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's top-level admin menu. Kept as a single small
 * class that just wires up menu slugs to page-render callbacks — each
 * page's actual markup/behaviour lives in its own class (e.g.
 * RateHistoryPage), so this file stays a thin table of contents as more
 * admin screens (settings, currency rules, logs) get added later.
 */
class AdminMenu {

	public const CAPABILITY = 'manage_woocommerce';
	public const SLUG       = 'wcmcs';

	private static string $exchangeRatesHook = '';

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
			array( RateHistoryPage::class, 'render' ),
			'dashicons-money-alt'
		);

		self::$exchangeRatesHook = add_submenu_page(
			self::SLUG,
			__( 'Exchange Rates', 'wc-multicurrency-switcher' ),
			__( 'Exchange Rates', 'wc-multicurrency-switcher' ),
			self::CAPABILITY,
			self::SLUG,
			array( RateHistoryPage::class, 'render' )
		);
	}

	/**
	 * Only loads this page's JS/CSS on this page — never globally across
	 * wp-admin — by comparing against the hook suffix WordPress itself
	 * generated for it.
	 */
	public static function maybe_enqueue( string $hook ): void {
		if ( $hook === self::$exchangeRatesHook ) {
			RateHistoryPage::enqueue();
		}
	}
}
