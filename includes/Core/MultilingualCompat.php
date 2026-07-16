<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility layer for sites running WPML or Polylang alongside this
 * plugin. Two separate concerns:
 *
 *  1. Data safety: WPML's own commerce extension (WooCommerce
 *     Multilingual, "WCML") ships its own multi-currency feature. If
 *     that's active at the same time as this plugin, both would be
 *     filtering product prices independently — a real risk of silently
 *     corrupting prices (e.g. double-converting them). We can't safely
 *     auto-disable a specific side of that conflict, so this warns the
 *     store owner instead of guessing.
 *  2. Persistence across a language switch: neither WPML nor Polylang
 *     touch this plugin's session/cookie/user-meta storage, so a chosen
 *     currency survives a language switch on its own in the common case
 *     (one domain, language selected via subdirectory or a query
 *     param). The one real gap is a WPML site configured for a
 *     *separate domain or subdomain per language* — a guest's currency
 *     cookie, scoped to one domain, doesn't carry over to another. Since
 *     this plugin already has a validated, safe ?currency= URL override
 *     (see UrlCurrencyOverride), the fix is to have the language
 *     switcher's own links carry that parameter forward, which is what
 *     this class adds.
 *
 * Note: this environment has neither plugin installed, so this class is
 * verified by code review against WPML/Polylang's documented, stable
 * hooks rather than a live integration test — worth confirming on a
 * staging site running one of them before relying on it in production.
 */
class MultilingualCompat {

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'maybe_show_wcml_conflict_notice' ) );
		add_filter( 'icl_ls_languages', array( self::class, 'append_currency_to_wpml_links' ) );
		add_filter( 'pll_the_languages', array( self::class, 'append_currency_to_polylang_links' ) );
	}

	public static function isWpmlActive(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	public static function isPolylangActive(): bool {
		return function_exists( 'pll_current_language' );
	}

	public static function isWooCommerceMultilingualActive(): bool {
		return defined( 'WCML_VERSION' ) || class_exists( 'woocommerce_wpml' );
	}

	public static function maybe_show_wcml_conflict_notice(): void {
		if ( ! self::isWooCommerceMultilingualActive() ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- a WooCommerce-defined capability, not WordPress core; WPCS doesn't know WooCommerce's own capability list.
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html__(
				'WooCommerce Multi-Currency Switcher and WooCommerce Multilingual\'s (WPML) own multi-currency feature are both active. Running two multi-currency systems at the same time can corrupt prices. We recommend disabling multi-currency in one of them.',
				'wc-multicurrency-switcher'
			)
		);
	}

	/**
	 * WPML's classic language switcher (icl_ls_languages / the
	 * [wpml_language_switcher] shortcode and widget) filters this array
	 * before rendering, each entry carrying a 'url'. Appending the
	 * current currency here means clicking a language link keeps the
	 * chosen currency even on a WPML setup using separate
	 * domains/subdomains per language, where the currency cookie alone
	 * wouldn't otherwise carry across.
	 *
	 * @param array<int|string, array<string, mixed>> $languages
	 * @return array<int|string, array<string, mixed>>
	 */
	public static function append_currency_to_wpml_links( $languages ) {
		if ( ! is_array( $languages ) ) {
			return $languages;
		}

		$currency = self::currentCurrency();

		if ( null === $currency ) {
			return $languages;
		}

		foreach ( $languages as &$language ) {
			if ( is_array( $language ) && ! empty( $language['url'] ) ) {
				$language['url'] = add_query_arg( 'currency', $currency, $language['url'] );
			}
		}
		unset( $language );

		return $languages;
	}

	/**
	 * Polylang's pll_the_languages() only exposes per-language URLs
	 * through this filter when the caller requested the raw array (e.g.
	 * pll_the_languages( array( 'raw' => 1 ) ), which is how most custom
	 * language-switcher templates and menu integrations use it. When
	 * Polylang instead returns pre-rendered HTML strings, there's no
	 * reliable URL to modify — this is a no-op in that case rather than
	 * trying to regex-edit markup it doesn't own.
	 *
	 * @param array<int|string, mixed> $languages
	 * @return array<int|string, mixed>
	 */
	public static function append_currency_to_polylang_links( $languages ) {
		if ( ! is_array( $languages ) ) {
			return $languages;
		}

		$currency = self::currentCurrency();

		if ( null === $currency ) {
			return $languages;
		}

		foreach ( $languages as &$language ) {
			if ( is_array( $language ) && ! empty( $language['url'] ) ) {
				$language['url'] = add_query_arg( 'currency', $currency, $language['url'] );
			}
		}
		unset( $language );

		return $languages;
	}

	private static function currentCurrency(): ?string {
		if ( ! Plugin::instance()->container()->has( 'currency_persistence_service' ) ) {
			return null;
		}

		/** @var \WCMCS\Services\CurrencyPersistenceService $persistence */
		$persistence = Plugin::instance()->container()->get( 'currency_persistence_service' );

		return $persistence->getCurrency();
	}
}
