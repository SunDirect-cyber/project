<?php
declare( strict_types=1 );

namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A dedicated capability for this plugin's admin screens, rather than
 * gating everything on WooCommerce's blanket 'manage_woocommerce' —
 * that capability controls a huge surface (orders, products, coupons,
 * all of WooCommerce's own settings), so there was previously no way to
 * let a store manager adjust currencies without also granting them
 * everything else 'manage_woocommerce' implies (they typically already
 * have it, admittedly, but a site owner who wants a narrower role — a
 * "currency manager" who shouldn't touch orders — had no way to do
 * that). Granted by default to administrator and shop_manager, the
 * WooCommerce role that's exactly "store manager, not full admin".
 */
class CapabilityManager {

	public const CAP = 'manage_wcmcs_currencies';

	private const DEFAULT_ROLES = array( 'administrator', 'shop_manager' );

	public static function register(): void {
		// Idempotent safety net: if a role reset or a broken migration
		// ever strips this capability, it's restored on the next admin
		// page load rather than silently locking store managers out.
		add_action( 'admin_init', array( self::class, 'grantDefaults' ) );
	}

	public static function grantDefaults(): void {
		foreach ( self::DEFAULT_ROLES as $roleName ) {
			$role = get_role( $roleName );

			if ( $role instanceof \WP_Role && ! $role->has_cap( self::CAP ) ) {
				$role->add_cap( self::CAP );
			}
		}
	}

	/**
	 * Mirror of grantDefaults(), called on uninstall so the capability
	 * doesn't linger on roles after the plugin is removed.
	 */
	public static function removeFromAllRoles(): void {
		global $wp_roles;

		if ( ! isset( $wp_roles ) || ! $wp_roles instanceof \WP_Roles ) {
			$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		foreach ( array_keys( $wp_roles->roles ) as $roleName ) {
			$role = get_role( $roleName );

			if ( $role instanceof \WP_Role && $role->has_cap( self::CAP ) ) {
				$role->remove_cap( self::CAP );
			}
		}
	}
}
