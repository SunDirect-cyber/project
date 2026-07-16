<?php
declare( strict_types=1 );

namespace WCMCS\Services\Currency;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raw ISO 4217 currency data: every currency's name, symbol, number of
 * decimal places, symbol position, and native thousand/decimal
 * separators. This is plain data on purpose — CurrencyRepository is what
 * turns a row of this array into an immutable Currency object.
 *
 * Decimal places follow the ISO 4217 minor unit table (e.g. 0 for
 * zero-decimal currencies like JPY/KRW/VND, 3 for the Gulf dinars like
 * BHD/KWD/OMR), except where real-world usage has diverged so far from
 * the ISO exponent that every payment processor and shop uses the
 * practical value instead (HUF is listed here with 0 decimals, matching
 * how it's actually priced and how WooCommerce core treats it, even
 * though ISO 4217 formally assigns it 2).
 */
class CurrencyData {

	private const SYMBOL_BEFORE = Currency::SYMBOL_BEFORE;
	private const SYMBOL_AFTER  = Currency::SYMBOL_AFTER;

	/**
	 * Currencies formatted the "European" way: 1.234,56 instead of
	 * 1,234.56. Applied as an override on top of the base rows below,
	 * instead of repeating the same two separators on every affected row.
	 */
	private const COMMA_DECIMAL = array(
		'EUR',
		'ARS',
		'BRL',
		'CLP',
		'COP',
		'CZK',
		'DKK',
		'HRK',
		'HUF',
		'NOK',
		'PLN',
		'RON',
		'RSD',
		'RUB',
		'SEK',
		'TRY',
		'UAH',
		'VES',
		'BAM',
		'BGN',
		'MKD',
		'MDL',
		'GEL',
		'BYN',
		'KZT',
		'UZS',
		'KGS',
		'TJS',
		'TMT',
		'AMD',
		'AZN',
		'ISK',
	);

	private const SPACE_THOUSAND = array( 'CZK', 'PLN', 'SEK', 'NOK', 'DKK', 'FKP' );

	/**
	 * @return array<string, array{name: string, symbol: string, decimals: int, position: string}>
	 */
	public static function raw(): array {
		$b = self::SYMBOL_BEFORE;
		$a = self::SYMBOL_AFTER;

		$rows = array(
			'AED' => array(
				'name'     => 'UAE Dirham',
				'symbol'   => 'د.إ',
				'decimals' => 2,
				'position' => $b,
			),
			'AFN' => array(
				'name'     => 'Afghan Afghani',
				'symbol'   => '؋',
				'decimals' => 2,
				'position' => $a,
			),
			'ALL' => array(
				'name'     => 'Albanian Lek',
				'symbol'   => 'L',
				'decimals' => 2,
				'position' => $a,
			),
			'AMD' => array(
				'name'     => 'Armenian Dram',
				'symbol'   => '֏',
				'decimals' => 2,
				'position' => $a,
			),
			'ANG' => array(
				'name'     => 'Netherlands Antillean Guilder',
				'symbol'   => 'ƒ',
				'decimals' => 2,
				'position' => $b,
			),
			'AOA' => array(
				'name'     => 'Angolan Kwanza',
				'symbol'   => 'Kz',
				'decimals' => 2,
				'position' => $a,
			),
			'ARS' => array(
				'name'     => 'Argentine Peso',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'AUD' => array(
				'name'     => 'Australian Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'AWG' => array(
				'name'     => 'Aruban Florin',
				'symbol'   => 'ƒ',
				'decimals' => 2,
				'position' => $b,
			),
			'AZN' => array(
				'name'     => 'Azerbaijani Manat',
				'symbol'   => '₼',
				'decimals' => 2,
				'position' => $a,
			),
			'BAM' => array(
				'name'     => 'Bosnia-Herzegovina Convertible Mark',
				'symbol'   => 'KM',
				'decimals' => 2,
				'position' => $a,
			),
			'BBD' => array(
				'name'     => 'Barbadian Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'BDT' => array(
				'name'     => 'Bangladeshi Taka',
				'symbol'   => '৳',
				'decimals' => 2,
				'position' => $b,
			),
			'BGN' => array(
				'name'     => 'Bulgarian Lev',
				'symbol'   => 'лв',
				'decimals' => 2,
				'position' => $a,
			),
			'BHD' => array(
				'name'     => 'Bahraini Dinar',
				'symbol'   => '.د.ب',
				'decimals' => 3,
				'position' => $b,
			),
			'BIF' => array(
				'name'     => 'Burundian Franc',
				'symbol'   => 'FBu',
				'decimals' => 0,
				'position' => $a,
			),
			'BMD' => array(
				'name'     => 'Bermudan Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'BND' => array(
				'name'     => 'Brunei Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'BOB' => array(
				'name'     => 'Bolivian Boliviano',
				'symbol'   => 'Bs.',
				'decimals' => 2,
				'position' => $b,
			),
			'BRL' => array(
				'name'     => 'Brazilian Real',
				'symbol'   => 'R$',
				'decimals' => 2,
				'position' => $b,
			),
			'BSD' => array(
				'name'     => 'Bahamian Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'BTN' => array(
				'name'     => 'Bhutanese Ngultrum',
				'symbol'   => 'Nu.',
				'decimals' => 2,
				'position' => $b,
			),
			'BWP' => array(
				'name'     => 'Botswanan Pula',
				'symbol'   => 'P',
				'decimals' => 2,
				'position' => $b,
			),
			'BYN' => array(
				'name'     => 'Belarusian Ruble',
				'symbol'   => 'Br',
				'decimals' => 2,
				'position' => $a,
			),
			'BZD' => array(
				'name'     => 'Belize Dollar',
				'symbol'   => 'BZ$',
				'decimals' => 2,
				'position' => $b,
			),
			'CAD' => array(
				'name'     => 'Canadian Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'CDF' => array(
				'name'     => 'Congolese Franc',
				'symbol'   => 'FC',
				'decimals' => 2,
				'position' => $a,
			),
			'CHF' => array(
				'name'     => 'Swiss Franc',
				'symbol'   => 'CHF',
				'decimals' => 2,
				'position' => $b,
			),
			'CLP' => array(
				'name'     => 'Chilean Peso',
				'symbol'   => '$',
				'decimals' => 0,
				'position' => $b,
			),
			'CNY' => array(
				'name'     => 'Chinese Yuan',
				'symbol'   => '¥',
				'decimals' => 2,
				'position' => $b,
			),
			'COP' => array(
				'name'     => 'Colombian Peso',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'CRC' => array(
				'name'     => 'Costa Rican Colón',
				'symbol'   => '₡',
				'decimals' => 2,
				'position' => $b,
			),
			'CUP' => array(
				'name'     => 'Cuban Peso',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'CVE' => array(
				'name'     => 'Cape Verdean Escudo',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $a,
			),
			'CZK' => array(
				'name'     => 'Czech Koruna',
				'symbol'   => 'Kč',
				'decimals' => 2,
				'position' => $a,
			),
			'DJF' => array(
				'name'     => 'Djiboutian Franc',
				'symbol'   => 'Fdj',
				'decimals' => 0,
				'position' => $a,
			),
			'DKK' => array(
				'name'     => 'Danish Krone',
				'symbol'   => 'kr',
				'decimals' => 2,
				'position' => $a,
			),
			'DOP' => array(
				'name'     => 'Dominican Peso',
				'symbol'   => 'RD$',
				'decimals' => 2,
				'position' => $b,
			),
			'DZD' => array(
				'name'     => 'Algerian Dinar',
				'symbol'   => 'دج',
				'decimals' => 2,
				'position' => $a,
			),
			'EGP' => array(
				'name'     => 'Egyptian Pound',
				'symbol'   => '£',
				'decimals' => 2,
				'position' => $b,
			),
			'ERN' => array(
				'name'     => 'Eritrean Nakfa',
				'symbol'   => 'Nfk',
				'decimals' => 2,
				'position' => $b,
			),
			'ETB' => array(
				'name'     => 'Ethiopian Birr',
				'symbol'   => 'Br',
				'decimals' => 2,
				'position' => $a,
			),
			'EUR' => array(
				'name'     => 'Euro',
				'symbol'   => '€',
				'decimals' => 2,
				'position' => $a,
			),
			'FJD' => array(
				'name'     => 'Fijian Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'FKP' => array(
				'name'     => 'Falkland Islands Pound',
				'symbol'   => '£',
				'decimals' => 2,
				'position' => $b,
			),
			'GBP' => array(
				'name'     => 'British Pound',
				'symbol'   => '£',
				'decimals' => 2,
				'position' => $b,
			),
			'GEL' => array(
				'name'     => 'Georgian Lari',
				'symbol'   => '₾',
				'decimals' => 2,
				'position' => $a,
			),
			'GHS' => array(
				'name'     => 'Ghanaian Cedi',
				'symbol'   => '₵',
				'decimals' => 2,
				'position' => $b,
			),
			'GIP' => array(
				'name'     => 'Gibraltar Pound',
				'symbol'   => '£',
				'decimals' => 2,
				'position' => $b,
			),
			'GMD' => array(
				'name'     => 'Gambian Dalasi',
				'symbol'   => 'D',
				'decimals' => 2,
				'position' => $b,
			),
			'GNF' => array(
				'name'     => 'Guinean Franc',
				'symbol'   => 'FG',
				'decimals' => 0,
				'position' => $a,
			),
			'GTQ' => array(
				'name'     => 'Guatemalan Quetzal',
				'symbol'   => 'Q',
				'decimals' => 2,
				'position' => $b,
			),
			'GYD' => array(
				'name'     => 'Guyanaese Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'HKD' => array(
				'name'     => 'Hong Kong Dollar',
				'symbol'   => 'HK$',
				'decimals' => 2,
				'position' => $b,
			),
			'HNL' => array(
				'name'     => 'Honduran Lempira',
				'symbol'   => 'L',
				'decimals' => 2,
				'position' => $b,
			),
			'HRK' => array(
				'name'     => 'Croatian Kuna',
				'symbol'   => 'kn',
				'decimals' => 2,
				'position' => $a,
			),
			'HTG' => array(
				'name'     => 'Haitian Gourde',
				'symbol'   => 'G',
				'decimals' => 2,
				'position' => $b,
			),
			'HUF' => array(
				'name'     => 'Hungarian Forint',
				'symbol'   => 'Ft',
				'decimals' => 0,
				'position' => $a,
			),
			'IDR' => array(
				'name'     => 'Indonesian Rupiah',
				'symbol'   => 'Rp',
				'decimals' => 2,
				'position' => $b,
			),
			'ILS' => array(
				'name'     => 'Israeli New Shekel',
				'symbol'   => '₪',
				'decimals' => 2,
				'position' => $b,
			),
			'INR' => array(
				'name'     => 'Indian Rupee',
				'symbol'   => '₹',
				'decimals' => 2,
				'position' => $b,
			),
			'IQD' => array(
				'name'     => 'Iraqi Dinar',
				'symbol'   => 'ع.د',
				'decimals' => 3,
				'position' => $b,
			),
			'IRR' => array(
				'name'     => 'Iranian Rial',
				'symbol'   => '﷼',
				'decimals' => 2,
				'position' => $a,
			),
			'ISK' => array(
				'name'     => 'Icelandic Króna',
				'symbol'   => 'kr',
				'decimals' => 0,
				'position' => $a,
			),
			'JMD' => array(
				'name'     => 'Jamaican Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'JOD' => array(
				'name'     => 'Jordanian Dinar',
				'symbol'   => 'د.ا',
				'decimals' => 3,
				'position' => $b,
			),
			'JPY' => array(
				'name'     => 'Japanese Yen',
				'symbol'   => '¥',
				'decimals' => 0,
				'position' => $b,
			),
			'KES' => array(
				'name'     => 'Kenyan Shilling',
				'symbol'   => 'KSh',
				'decimals' => 2,
				'position' => $b,
			),
			'KGS' => array(
				'name'     => 'Kyrgystani Som',
				'symbol'   => 'лв',
				'decimals' => 2,
				'position' => $a,
			),
			'KHR' => array(
				'name'     => 'Cambodian Riel',
				'symbol'   => '៛',
				'decimals' => 2,
				'position' => $b,
			),
			'KMF' => array(
				'name'     => 'Comorian Franc',
				'symbol'   => 'CF',
				'decimals' => 0,
				'position' => $a,
			),
			'KPW' => array(
				'name'     => 'North Korean Won',
				'symbol'   => '₩',
				'decimals' => 2,
				'position' => $b,
			),
			'KRW' => array(
				'name'     => 'South Korean Won',
				'symbol'   => '₩',
				'decimals' => 0,
				'position' => $b,
			),
			'KWD' => array(
				'name'     => 'Kuwaiti Dinar',
				'symbol'   => 'د.ك',
				'decimals' => 3,
				'position' => $b,
			),
			'KYD' => array(
				'name'     => 'Cayman Islands Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'KZT' => array(
				'name'     => 'Kazakhstani Tenge',
				'symbol'   => '₸',
				'decimals' => 2,
				'position' => $a,
			),
			'LAK' => array(
				'name'     => 'Laotian Kip',
				'symbol'   => '₭',
				'decimals' => 2,
				'position' => $b,
			),
			'LBP' => array(
				'name'     => 'Lebanese Pound',
				'symbol'   => 'ل.ل',
				'decimals' => 2,
				'position' => $b,
			),
			'LKR' => array(
				'name'     => 'Sri Lankan Rupee',
				'symbol'   => 'Rs',
				'decimals' => 2,
				'position' => $b,
			),
			'LRD' => array(
				'name'     => 'Liberian Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'LSL' => array(
				'name'     => 'Lesotho Loti',
				'symbol'   => 'L',
				'decimals' => 2,
				'position' => $b,
			),
			'LYD' => array(
				'name'     => 'Libyan Dinar',
				'symbol'   => 'ل.د',
				'decimals' => 3,
				'position' => $b,
			),
			'MAD' => array(
				'name'     => 'Moroccan Dirham',
				'symbol'   => 'د.م.',
				'decimals' => 2,
				'position' => $b,
			),
			'MDL' => array(
				'name'     => 'Moldovan Leu',
				'symbol'   => 'L',
				'decimals' => 2,
				'position' => $a,
			),
			'MGA' => array(
				'name'     => 'Malagasy Ariary',
				'symbol'   => 'Ar',
				'decimals' => 2,
				'position' => $b,
			),
			'MKD' => array(
				'name'     => 'Macedonian Denar',
				'symbol'   => 'ден',
				'decimals' => 2,
				'position' => $a,
			),
			'MMK' => array(
				'name'     => 'Myanma Kyat',
				'symbol'   => 'K',
				'decimals' => 2,
				'position' => $b,
			),
			'MNT' => array(
				'name'     => 'Mongolian Tugrik',
				'symbol'   => '₮',
				'decimals' => 2,
				'position' => $b,
			),
			'MOP' => array(
				'name'     => 'Macanese Pataca',
				'symbol'   => 'MOP$',
				'decimals' => 2,
				'position' => $b,
			),
			'MRU' => array(
				'name'     => 'Mauritanian Ouguiya',
				'symbol'   => 'UM',
				'decimals' => 2,
				'position' => $a,
			),
			'MUR' => array(
				'name'     => 'Mauritian Rupee',
				'symbol'   => '₨',
				'decimals' => 2,
				'position' => $b,
			),
			'MVR' => array(
				'name'     => 'Maldivian Rufiyaa',
				'symbol'   => '.ރ',
				'decimals' => 2,
				'position' => $a,
			),
			'MWK' => array(
				'name'     => 'Malawian Kwacha',
				'symbol'   => 'MK',
				'decimals' => 2,
				'position' => $b,
			),
			'MXN' => array(
				'name'     => 'Mexican Peso',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'MYR' => array(
				'name'     => 'Malaysian Ringgit',
				'symbol'   => 'RM',
				'decimals' => 2,
				'position' => $b,
			),
			'MZN' => array(
				'name'     => 'Mozambican Metical',
				'symbol'   => 'MT',
				'decimals' => 2,
				'position' => $b,
			),
			'NAD' => array(
				'name'     => 'Namibian Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'NGN' => array(
				'name'     => 'Nigerian Naira',
				'symbol'   => '₦',
				'decimals' => 2,
				'position' => $b,
			),
			'NIO' => array(
				'name'     => 'Nicaraguan Córdoba',
				'symbol'   => 'C$',
				'decimals' => 2,
				'position' => $b,
			),
			'NOK' => array(
				'name'     => 'Norwegian Krone',
				'symbol'   => 'kr',
				'decimals' => 2,
				'position' => $a,
			),
			'NPR' => array(
				'name'     => 'Nepalese Rupee',
				'symbol'   => '₨',
				'decimals' => 2,
				'position' => $b,
			),
			'NZD' => array(
				'name'     => 'New Zealand Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'OMR' => array(
				'name'     => 'Omani Rial',
				'symbol'   => 'ر.ع.',
				'decimals' => 3,
				'position' => $b,
			),
			'PAB' => array(
				'name'     => 'Panamanian Balboa',
				'symbol'   => 'B/.',
				'decimals' => 2,
				'position' => $b,
			),
			'PEN' => array(
				'name'     => 'Peruvian Sol',
				'symbol'   => 'S/',
				'decimals' => 2,
				'position' => $b,
			),
			'PGK' => array(
				'name'     => 'Papua New Guinean Kina',
				'symbol'   => 'K',
				'decimals' => 2,
				'position' => $b,
			),
			'PHP' => array(
				'name'     => 'Philippine Peso',
				'symbol'   => '₱',
				'decimals' => 2,
				'position' => $b,
			),
			'PKR' => array(
				'name'     => 'Pakistani Rupee',
				'symbol'   => '₨',
				'decimals' => 2,
				'position' => $b,
			),
			'PLN' => array(
				'name'     => 'Polish Zloty',
				'symbol'   => 'zł',
				'decimals' => 2,
				'position' => $a,
			),
			'PYG' => array(
				'name'     => 'Paraguayan Guarani',
				'symbol'   => '₲',
				'decimals' => 0,
				'position' => $b,
			),
			'QAR' => array(
				'name'     => 'Qatari Rial',
				'symbol'   => 'ر.ق',
				'decimals' => 2,
				'position' => $b,
			),
			'RON' => array(
				'name'     => 'Romanian Leu',
				'symbol'   => 'lei',
				'decimals' => 2,
				'position' => $a,
			),
			'RSD' => array(
				'name'     => 'Serbian Dinar',
				'symbol'   => 'дин.',
				'decimals' => 2,
				'position' => $a,
			),
			'RUB' => array(
				'name'     => 'Russian Ruble',
				'symbol'   => '₽',
				'decimals' => 2,
				'position' => $a,
			),
			'RWF' => array(
				'name'     => 'Rwandan Franc',
				'symbol'   => 'FRw',
				'decimals' => 0,
				'position' => $a,
			),
			'SAR' => array(
				'name'     => 'Saudi Riyal',
				'symbol'   => 'ر.س',
				'decimals' => 2,
				'position' => $b,
			),
			'SBD' => array(
				'name'     => 'Solomon Islands Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'SCR' => array(
				'name'     => 'Seychellois Rupee',
				'symbol'   => '₨',
				'decimals' => 2,
				'position' => $b,
			),
			'SDG' => array(
				'name'     => 'Sudanese Pound',
				'symbol'   => 'ج.س.',
				'decimals' => 2,
				'position' => $b,
			),
			'SEK' => array(
				'name'     => 'Swedish Krona',
				'symbol'   => 'kr',
				'decimals' => 2,
				'position' => $a,
			),
			'SGD' => array(
				'name'     => 'Singapore Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'SHP' => array(
				'name'     => 'Saint Helena Pound',
				'symbol'   => '£',
				'decimals' => 2,
				'position' => $b,
			),
			'SLE' => array(
				'name'     => 'Sierra Leonean Leone',
				'symbol'   => 'Le',
				'decimals' => 2,
				'position' => $b,
			),
			'SOS' => array(
				'name'     => 'Somali Shilling',
				'symbol'   => 'S',
				'decimals' => 2,
				'position' => $b,
			),
			'SRD' => array(
				'name'     => 'Surinamese Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'SSP' => array(
				'name'     => 'South Sudanese Pound',
				'symbol'   => '£',
				'decimals' => 2,
				'position' => $b,
			),
			'STN' => array(
				'name'     => 'São Tomé and Príncipe Dobra',
				'symbol'   => 'Db',
				'decimals' => 2,
				'position' => $a,
			),
			'SYP' => array(
				'name'     => 'Syrian Pound',
				'symbol'   => '£',
				'decimals' => 2,
				'position' => $b,
			),
			'SZL' => array(
				'name'     => 'Swazi Lilangeni',
				'symbol'   => 'L',
				'decimals' => 2,
				'position' => $b,
			),
			'THB' => array(
				'name'     => 'Thai Baht',
				'symbol'   => '฿',
				'decimals' => 2,
				'position' => $b,
			),
			'TJS' => array(
				'name'     => 'Tajikistani Somoni',
				'symbol'   => 'ЅМ',
				'decimals' => 2,
				'position' => $a,
			),
			'TMT' => array(
				'name'     => 'Turkmenistani Manat',
				'symbol'   => 'm',
				'decimals' => 2,
				'position' => $a,
			),
			'TND' => array(
				'name'     => 'Tunisian Dinar',
				'symbol'   => 'د.ت',
				'decimals' => 3,
				'position' => $b,
			),
			'TOP' => array(
				'name'     => 'Tongan Paʻanga',
				'symbol'   => 'T$',
				'decimals' => 2,
				'position' => $b,
			),
			'TRY' => array(
				'name'     => 'Turkish Lira',
				'symbol'   => '₺',
				'decimals' => 2,
				'position' => $a,
			),
			'TTD' => array(
				'name'     => 'Trinidad and Tobago Dollar',
				'symbol'   => 'TT$',
				'decimals' => 2,
				'position' => $b,
			),
			'TWD' => array(
				'name'     => 'New Taiwan Dollar',
				'symbol'   => 'NT$',
				'decimals' => 2,
				'position' => $b,
			),
			'TZS' => array(
				'name'     => 'Tanzanian Shilling',
				'symbol'   => 'TSh',
				'decimals' => 2,
				'position' => $b,
			),
			'UAH' => array(
				'name'     => 'Ukrainian Hryvnia',
				'symbol'   => '₴',
				'decimals' => 2,
				'position' => $b,
			),
			'UGX' => array(
				'name'     => 'Ugandan Shilling',
				'symbol'   => 'USh',
				'decimals' => 0,
				'position' => $b,
			),
			'USD' => array(
				'name'     => 'United States Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'UYU' => array(
				'name'     => 'Uruguayan Peso',
				'symbol'   => '$U',
				'decimals' => 2,
				'position' => $b,
			),
			'UZS' => array(
				'name'     => 'Uzbekistan Som',
				'symbol'   => 'лв',
				'decimals' => 2,
				'position' => $a,
			),
			'VES' => array(
				'name'     => 'Venezuelan Bolívar Soberano',
				'symbol'   => 'Bs.S',
				'decimals' => 2,
				'position' => $b,
			),
			'VND' => array(
				'name'     => 'Vietnamese Dong',
				'symbol'   => '₫',
				'decimals' => 0,
				'position' => $a,
			),
			'VUV' => array(
				'name'     => 'Vanuatu Vatu',
				'symbol'   => 'VT',
				'decimals' => 0,
				'position' => $a,
			),
			'WST' => array(
				'name'     => 'Samoan Tala',
				'symbol'   => 'WS$',
				'decimals' => 2,
				'position' => $b,
			),
			'XAF' => array(
				'name'     => 'Central African CFA Franc',
				'symbol'   => 'FCFA',
				'decimals' => 0,
				'position' => $a,
			),
			'XCD' => array(
				'name'     => 'East Caribbean Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
			'XOF' => array(
				'name'     => 'West African CFA Franc',
				'symbol'   => 'CFA',
				'decimals' => 0,
				'position' => $a,
			),
			'XPF' => array(
				'name'     => 'CFP Franc',
				'symbol'   => '₣',
				'decimals' => 0,
				'position' => $a,
			),
			'YER' => array(
				'name'     => 'Yemeni Rial',
				'symbol'   => '﷼',
				'decimals' => 2,
				'position' => $b,
			),
			'ZAR' => array(
				'name'     => 'South African Rand',
				'symbol'   => 'R',
				'decimals' => 2,
				'position' => $b,
			),
			'ZMW' => array(
				'name'     => 'Zambian Kwacha',
				'symbol'   => 'ZK',
				'decimals' => 2,
				'position' => $b,
			),
			'ZWL' => array(
				'name'     => 'Zimbabwean Dollar',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => $b,
			),
		);

		foreach ( $rows as $code => &$row ) {
			$row['thousand_separator'] = in_array( $code, self::SPACE_THOUSAND, true )
				? ' '
				: ( in_array( $code, self::COMMA_DECIMAL, true ) ? '.' : ',' );

			$row['decimal_separator'] = in_array( $code, self::COMMA_DECIMAL, true ) ? ',' : '.';
		}
		unset( $row );

		return $rows;
	}
}
