<?php
declare( strict_types=1 );

namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warns when another plugin that also touches product pricing per
 * currency is active alongside this one — the real-world failure mode
 * being guarded against is silent double-conversion (two plugins each
 * multiplying a price by their own exchange rate), which is a
 * frequently-reported bug against competing multi-currency plugins and
 * is far worse than a merely missing feature: prices look plausible but
 * are simply wrong. This can't be resolved automatically (there's no
 * safe way to know which of two active pricing engines the store owner
 * actually wants), so the only responsible move is to detect and warn
 * loudly rather than let both run.
 *
 * WPML/WCML gets its own dedicated handling in MultilingualCompat (it
 * needs the additional currency-persists-across-language-switch logic
 * that doesn't apply to any of these) — this class covers the other
 * standalone multi-currency plugins, plus a softer, informational-only
 * notice for multi-vendor plugins (which don't inherently double-convert
 * prices themselves, but are worth flagging since a vendor dashboard
 * showing base-currency prices next to a storefront showing converted
 * ones is a common source of "why don't these numbers match" support
 * tickets).
 *
 * None of these plugins are installed in this environment, so — like
 * MultilingualCompat — detection here is based on each plugin's
 * publicly documented, stable main class/constant rather than a live
 * integration test. Worth confirming the exact signature still matches
 * before relying on it if one of these updates its internals.
 */
class PluginConflictDetector {

	/**
	 * Label => a callable that returns true when that plugin is active.
	 * Each checks a specific main class/constant the plugin itself
	 * defines, not just "is this plugin's folder present", so a
	 * deactivated-but-installed copy doesn't trigger a false warning.
	 *
	 * @var array<string, callable(): bool>
	 */
	private const CURRENCY_PLUGINS = array(
		'WOOCS – WooCommerce Currency Switcher'           => array( self::class, 'hasWoocs' ),
		'Currency Switcher for WooCommerce (Aelia)'       => array( self::class, 'hasAelia' ),
		'Multi Currency for WooCommerce (VillaTheme/FOX)' => array( self::class, 'hasVillaTheme' ),
		'CURCY – Multi Currency for WooCommerce'          => array( self::class, 'hasCurcy' ),
		'YayCurrency'                                     => array( self::class, 'hasYayCurrency' ),
	);

	/**
	 * @var array<string, callable(): bool>
	 */
	private const VENDOR_PLUGINS = array(
		'Dokan Multivendor Marketplace' => array( self::class, 'hasDokan' ),
		'WCFM Marketplace'              => array( self::class, 'hasWcfm' ),
		'WC Vendors'                    => array( self::class, 'hasWcVendors' ),
	);

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'maybe_show_notices' ) );
	}

	/**
	 * @return string[] Human-readable names of every conflicting
	 *                   multi-currency plugin currently active.
	 */
	public static function activeCurrencyPluginConflicts(): array {
		return self::activeFrom( self::CURRENCY_PLUGINS );
	}

	/**
	 * @return string[]
	 */
	public static function activeVendorPlugins(): array {
		return self::activeFrom( self::VENDOR_PLUGINS );
	}

	public static function maybe_show_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- a WooCommerce-defined capability, not WordPress core; WPCS doesn't know WooCommerce's own capability list.
			return;
		}

		$currencyConflicts = self::activeCurrencyPluginConflicts();

		if ( ! empty( $currencyConflicts ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Multi-Currency Switcher:', 'wc-multicurrency-switcher' ),
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of conflicting plugin names */
						__( 'Another active plugin also converts prices by currency: %s. Running two multi-currency plugins at once risks silently double-converting prices. Deactivate one of them.', 'wc-multicurrency-switcher' ),
						implode( ', ', $currencyConflicts )
					)
				)
			);
		}

		$vendorPlugins = self::activeVendorPlugins();

		if ( ! empty( $vendorPlugins ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Multi-Currency Switcher:', 'wc-multicurrency-switcher' ),
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of vendor plugin names */
						__( 'A multi-vendor plugin is also active (%s). Vendor dashboards and reports in that plugin typically show prices in the store\'s base currency regardless of a shopper\'s selected currency — this is expected, not a bug, but worth explaining to vendors so figures aren\'t mistaken for a mismatch.', 'wc-multicurrency-switcher' ),
						implode( ', ', $vendorPlugins )
					)
				)
			);
		}
	}

	/**
	 * @param array<string, callable(): bool> $plugins
	 * @return string[]
	 */
	private static function activeFrom( array $plugins ): array {
		$active = array();

		foreach ( $plugins as $label => $check ) {
			if ( call_user_func( $check ) ) {
				$active[] = $label;
			}
		}

		return $active;
	}

	private static function hasWoocs(): bool {
		return class_exists( 'WOOCS' );
	}

	private static function hasAelia(): bool {
		return class_exists( 'WC_Aelia_CurrencySwitcher' );
	}

	private static function hasVillaTheme(): bool {
		return class_exists( 'WOOMULTI_CURRENCY' );
	}

	private static function hasCurcy(): bool {
		return class_exists( 'Alg_WC_Currency_Switcher' );
	}

	private static function hasYayCurrency(): bool {
		return class_exists( 'YayCurrency' );
	}

	private static function hasDokan(): bool {
		return class_exists( 'WeDevs_Dokan' );
	}

	private static function hasWcfm(): bool {
		return defined( 'WCFM_VERSION' );
	}

	private static function hasWcVendors(): bool {
		return class_exists( 'WC_Vendors' );
	}
}
