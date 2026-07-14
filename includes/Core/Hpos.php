<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares compatibility with WooCommerce's High-Performance Order
 * Storage (custom order tables) and with the Cart/Checkout blocks.
 *
 * This MUST be hooked to 'before_woocommerce_init' and MUST run before
 * WooCommerce fully initializes, so it's called directly from the main
 * plugin file — not from Plugin::run(), which fires later on
 * 'plugins_loaded' and can't guarantee it runs before WooCommerce reads
 * the feature compatibility list.
 */
class Hpos {

	public static function register(): void {
		add_action( 'before_woocommerce_init', array( self::class, 'declare_compatibility' ) );
	}

	public static function declare_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			WCMCS_FILE,
			true
		);

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			WCMCS_FILE,
			true
		);
	}

	/**
	 * True when HPOS (custom order tables) is the active order storage,
	 * false when orders are still stored as posts. Useful anywhere the
	 * plugin needs to branch on storage mode instead of touching order
	 * data through CRUD methods (e.g. deciding whether an order-meta
	 * WP_Query needs postmeta or orders-meta join clauses).
	 */
	public static function is_enabled(): bool {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
