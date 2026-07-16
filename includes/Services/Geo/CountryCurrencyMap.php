<?php
declare( strict_types=1 );

namespace WCMCS\Services\Geo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps an ISO 3166-1 alpha-2 country code to that country's official
 * currency. Every value here is guaranteed to exist in
 * WCMCS\Services\Currency\CurrencyData, so a lookup can never resolve to
 * a currency the rest of the plugin doesn't know how to format.
 *
 * Some countries genuinely accept more than one currency in practice
 * (e.g. a shop near the Swiss border pricing in EUR as well as CHF).
 * Rather than hardcoding those judgment calls, this only ever returns
 * the one official currency — anything more specific is a per-store
 * decision, made via the 'wcmcs_country_currency_map' filter below.
 */
class CountryCurrencyMap {

	/**
	 * @return array<string, string[]> Currency code => list of country codes using it.
	 */
	private static function groups(): array {
		return array(
			'USD' => array( 'US', 'EC', 'SV', 'TL', 'FM', 'MH', 'PW' ),
			'EUR' => array( 'AT', 'BE', 'CY', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PT', 'SK', 'SI', 'ES', 'HR', 'MC', 'SM', 'VA', 'AD', 'ME', 'XK' ),
			'GBP' => array( 'GB', 'GG', 'IM', 'JE' ),
			'JPY' => array( 'JP' ),
			'CNY' => array( 'CN' ),
			'INR' => array( 'IN' ),
			'AUD' => array( 'AU', 'CX', 'CC', 'NF', 'KI', 'NR', 'TV' ),
			'CAD' => array( 'CA' ),
			'CHF' => array( 'CH', 'LI' ),
			'NZD' => array( 'NZ', 'CK', 'NU', 'PN', 'TK' ),
			'MXN' => array( 'MX' ),
			'SEK' => array( 'SE' ),
			'NOK' => array( 'NO', 'SJ', 'BV' ),
			'DKK' => array( 'DK', 'FO', 'GL' ),
			'PLN' => array( 'PL' ),
			'CZK' => array( 'CZ' ),
			'HUF' => array( 'HU' ),
			'RON' => array( 'RO' ),
			'BGN' => array( 'BG' ),
			'RSD' => array( 'RS' ),
			'MKD' => array( 'MK' ),
			'BAM' => array( 'BA' ),
			'ALL' => array( 'AL' ),
			'MDL' => array( 'MD' ),
			'UAH' => array( 'UA' ),
			'BYN' => array( 'BY' ),
			'RUB' => array( 'RU' ),
			'GEL' => array( 'GE' ),
			'AMD' => array( 'AM' ),
			'AZN' => array( 'AZ' ),
			'TRY' => array( 'TR' ),
			'ISK' => array( 'IS' ),
			'KZT' => array( 'KZ' ),
			'UZS' => array( 'UZ' ),
			'KGS' => array( 'KG' ),
			'TJS' => array( 'TJ' ),
			'TMT' => array( 'TM' ),
			'AFN' => array( 'AF' ),
			'PKR' => array( 'PK' ),
			'BDT' => array( 'BD' ),
			'LKR' => array( 'LK' ),
			'NPR' => array( 'NP' ),
			'BTN' => array( 'BT' ),
			'MVR' => array( 'MV' ),
			'MMK' => array( 'MM' ),
			'KHR' => array( 'KH' ),
			'LAK' => array( 'LA' ),
			'VND' => array( 'VN' ),
			'THB' => array( 'TH' ),
			'MYR' => array( 'MY' ),
			'SGD' => array( 'SG' ),
			'IDR' => array( 'ID' ),
			'PHP' => array( 'PH' ),
			'BND' => array( 'BN' ),
			'MOP' => array( 'MO' ),
			'HKD' => array( 'HK' ),
			'TWD' => array( 'TW' ),
			'KRW' => array( 'KR' ),
			'KPW' => array( 'KP' ),
			'MNT' => array( 'MN' ),
			'AED' => array( 'AE' ),
			'SAR' => array( 'SA' ),
			'QAR' => array( 'QA' ),
			'KWD' => array( 'KW' ),
			'BHD' => array( 'BH' ),
			'OMR' => array( 'OM' ),
			'YER' => array( 'YE' ),
			'JOD' => array( 'JO' ),
			'LBP' => array( 'LB' ),
			'SYP' => array( 'SY' ),
			'IQD' => array( 'IQ' ),
			'IRR' => array( 'IR' ),
			'ILS' => array( 'IL' ),
			'EGP' => array( 'EG' ),
			'DZD' => array( 'DZ' ),
			'MAD' => array( 'MA' ),
			'TND' => array( 'TN' ),
			'LYD' => array( 'LY' ),
			'SDG' => array( 'SD' ),
			'SSP' => array( 'SS' ),
			'ETB' => array( 'ET' ),
			'KES' => array( 'KE' ),
			'UGX' => array( 'UG' ),
			'TZS' => array( 'TZ' ),
			'RWF' => array( 'RW' ),
			'BIF' => array( 'BI' ),
			'DJF' => array( 'DJ' ),
			'SOS' => array( 'SO' ),
			'ERN' => array( 'ER' ),
			'GHS' => array( 'GH' ),
			'NGN' => array( 'NG' ),
			'XOF' => array( 'BJ', 'BF', 'CI', 'GW', 'ML', 'NE', 'SN', 'TG' ),
			'XAF' => array( 'CM', 'CF', 'TD', 'CG', 'GQ', 'GA' ),
			'CDF' => array( 'CD' ),
			'AOA' => array( 'AO' ),
			'ZMW' => array( 'ZM' ),
			'MWK' => array( 'MW' ),
			'MZN' => array( 'MZ' ),
			'ZAR' => array( 'ZA' ),
			'LSL' => array( 'LS' ),
			'SZL' => array( 'SZ' ),
			'NAD' => array( 'NA' ),
			'BWP' => array( 'BW' ),
			'GMD' => array( 'GM' ),
			'GNF' => array( 'GN' ),
			'LRD' => array( 'LR' ),
			'SLE' => array( 'SL' ),
			'CVE' => array( 'CV' ),
			'STN' => array( 'ST' ),
			'MRU' => array( 'MR' ),
			'XPF' => array( 'PF', 'NC', 'WF' ),
			'FJD' => array( 'FJ' ),
			'PGK' => array( 'PG' ),
			'SBD' => array( 'SB' ),
			'VUV' => array( 'VU' ),
			'WST' => array( 'WS' ),
			'TOP' => array( 'TO' ),
			'KMF' => array( 'KM' ),
			'MGA' => array( 'MG' ),
			'SCR' => array( 'SC' ),
			'MUR' => array( 'MU' ),
			'BRL' => array( 'BR' ),
			'ARS' => array( 'AR' ),
			'CLP' => array( 'CL' ),
			'COP' => array( 'CO' ),
			'PEN' => array( 'PE' ),
			'BOB' => array( 'BO' ),
			'PYG' => array( 'PY' ),
			'UYU' => array( 'UY' ),
			'VES' => array( 'VE' ),
			'GTQ' => array( 'GT' ),
			'HNL' => array( 'HN' ),
			'NIO' => array( 'NI' ),
			'CRC' => array( 'CR' ),
			'PAB' => array( 'PA' ),
			'DOP' => array( 'DO' ),
			'HTG' => array( 'HT' ),
			'JMD' => array( 'JM' ),
			'TTD' => array( 'TT' ),
			'BBD' => array( 'BB' ),
			'BSD' => array( 'BS' ),
			'BZD' => array( 'BZ' ),
			'GYD' => array( 'GY' ),
			'SRD' => array( 'SR' ),
			'XCD' => array( 'AG', 'DM', 'GD', 'KN', 'LC', 'VC' ),
			'KYD' => array( 'KY' ),
			'BMD' => array( 'BM' ),
			'CUP' => array( 'CU' ),
			'ANG' => array( 'CW', 'SX' ),
			'AWG' => array( 'AW' ),
		);
	}

	/**
	 * @return array<string, string> Country code => currency code.
	 */
	public static function all(): array {
		static $map = null;

		if ( null !== $map ) {
			return $map;
		}

		$map = array();

		foreach ( self::groups() as $currency => $countries ) {
			foreach ( $countries as $country ) {
				$map[ $country ] = $currency;
			}
		}

		/**
		 * Lets a store override the mapping — e.g. a border-region store
		 * that wants CH visitors offered EUR instead of CHF.
		 */
		$map = apply_filters( 'wcmcs_country_currency_map', $map );

		return $map;
	}

	public static function currencyForCountry( string $countryCode ): ?string {
		return self::all()[ strtoupper( $countryCode ) ] ?? null;
	}
}
