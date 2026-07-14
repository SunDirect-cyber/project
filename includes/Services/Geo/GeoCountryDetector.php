<?php
namespace WCMCS\Services\Geo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects the visitor's country, trying each signal in order and
 * falling through silently if one isn't available:
 *
 *  1. WooCommerce's own geolocation (WC_Geolocation), restricted to its
 *     local MaxMind GeoLite2 database — WooCommerce already downloads
 *     and maintains that database for its own tax/shipping features, so
 *     this plugin reuses it instead of shipping and parsing a second
 *     copy of the same binary database itself. Explicitly disables
 *     WC_Geolocation's third-party API fallback (ip-api.com) so this
 *     never makes an external network call — if the local database
 *     isn't present, detection just returns null and falls through to
 *     the next signal instead of leaking the visitor's IP to a
 *     third party.
 *  2. The browser's Accept-Language header, mapped to a country.
 *
 * A null return means both signals failed — the caller should fall back
 * to the store's base currency.
 */
class GeoCountryDetector {

	/**
	 * A minimal language -> most-likely-country fallback, used only when
	 * the Accept-Language header has no explicit region subtag (e.g.
	 * "fr" instead of "fr-CA"). Deliberately small and filterable rather
	 * than exhaustive — a wrong guess here just means an auto-detected
	 * currency that's one step less precise, never a fatal error.
	 */
	private const LANGUAGE_TO_COUNTRY = array(
		'en' => 'US', 'fr' => 'FR', 'de' => 'DE', 'es' => 'ES', 'it' => 'IT',
		'pt' => 'PT', 'nl' => 'NL', 'ja' => 'JP', 'zh' => 'CN', 'ko' => 'KR',
		'ru' => 'RU', 'ar' => 'SA', 'hi' => 'IN', 'pl' => 'PL', 'tr' => 'TR',
		'sv' => 'SE', 'da' => 'DK', 'no' => 'NO', 'fi' => 'FI', 'cs' => 'CZ',
		'el' => 'GR', 'he' => 'IL', 'th' => 'TH', 'vi' => 'VN', 'id' => 'ID',
		'uk' => 'UA', 'ro' => 'RO', 'hu' => 'HU',
	);

	public function detect(): ?string {
		$country = $this->fromWooCommerceGeolocation();

		if ( null !== $country ) {
			return $country;
		}

		return $this->fromAcceptLanguageHeader();
	}

	private function fromWooCommerceGeolocation(): ?string {
		if ( ! class_exists( \WC_Geolocation::class ) ) {
			return null;
		}

		$ip = \WC_Geolocation::get_ip_address();

		if ( empty( $ip ) ) {
			return null;
		}

		// $fallback=false, $api_fallback=false: local database only, no
		// external HTTP request under any circumstance.
		$result  = \WC_Geolocation::geolocate_ip( $ip, false, false );
		$country = $result['country'] ?? '';

		return '' !== $country ? strtoupper( $country ) : null;
	}

	private function fromAcceptLanguageHeader(): ?string {
		$header = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';

		if ( '' === $header ) {
			return null;
		}

		// Accept-Language looks like "fr-CA,fr;q=0.9,en;q=0.8" — take the
		// highest-priority tag (the first one, since browsers list them
		// in preference order already).
		$primary = trim( explode( ',', $header )[0] );
		$primary = explode( ';', $primary )[0]; // drop a "q=0.9" suffix, if present on the first entry.

		if ( '' === $primary ) {
			return null;
		}

		$parts = preg_split( '/[-_]/', $primary );

		if ( isset( $parts[1] ) && 2 === strlen( $parts[1] ) ) {
			return strtoupper( $parts[1] );
		}

		$language = strtolower( $parts[0] );
		$fallback = apply_filters( 'wcmcs_language_country_map', self::LANGUAGE_TO_COUNTRY );

		return $fallback[ $language ] ?? null;
	}
}
