<?php
declare( strict_types=1 );

namespace WCMCS\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects whether the current request is a multi-vendor marketplace
 * plugin's front-end vendor dashboard (Dokan, WCFM, WC Vendors) —
 * product editing screens for vendors, which run on the *front end*
 * (not wp-admin) and so would otherwise pass PriceConverter's
 * shouldApply() check exactly like a normal shopping page.
 *
 * That matters because it's a real, serious bug class, not a cosmetic
 * one: a vendor viewing their own product's price field while their own
 * browser session happens to have a non-base currency selected would
 * see a *converted* price inside the edit form. If they save the form
 * without changing anything, that converted number can get written back
 * as the product's new raw (base-currency) price — silently corrupting
 * the vendor's actual pricing every time they touch the edit screen.
 * PriceConverter excludes these screens for exactly this reason.
 *
 * None of these plugins are installed in this environment, so —
 * like MultilingualCompat and the other best-effort integrations —
 * detection here is based on each plugin's publicly documented,
 * stable functions/query vars rather than a live integration test.
 */
class VendorDashboardDetector {

	public static function isVendorDashboard(): bool {
		return self::isDokanDashboard() || self::isWcfmDashboard() || self::isWcVendorsDashboard();
	}

	private static function isDokanDashboard(): bool {
		return function_exists( 'dokan_is_seller_dashboard' ) && dokan_is_seller_dashboard();
	}

	private static function isWcfmDashboard(): bool {
		if ( ! defined( 'WCFM_VERSION' ) ) {
			return false;
		}

		// WCFM's dashboard is a single page identified by this query var
		// on its own custom endpoint page, not a dedicated is_*() helper.
		return isset( $GLOBALS['WCFM'] ) && (bool) get_query_var( 'wcfm-view', false );
	}

	private static function isWcVendorsDashboard(): bool {
		return class_exists( '\WCV_Vendors' ) && method_exists( '\WCV_Vendors', 'is_vendor_page' ) && \WCV_Vendors::is_vendor_page();
	}
}
