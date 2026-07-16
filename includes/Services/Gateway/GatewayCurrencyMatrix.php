<?php
declare( strict_types=1 );

namespace WCMCS\Services\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which payment gateways support processing a charge in which
 * currencies. A gateway is always assumed to support the store's own
 * base currency (it wouldn't be configured otherwise) — this matrix is
 * only ever consulted for a *non-base* currency.
 *
 * The built-in defaults below cover only what can be stated with real
 * confidence:
 *  - Stripe: documented to support a very broad currency list (135+),
 *    treated here as universally compatible with anything this plugin
 *    offers. Stores with an unusual/exotic active currency should still
 *    spot-check Stripe's current docs.
 *  - PayPal: a long-standing, specific, publicly documented list of
 *    supported currencies (not "any currency") — included explicitly
 *    rather than guessed.
 *  - WooCommerce's own core offline methods (bank transfer, cheque,
 *    cash on delivery): no card network or processor is involved at
 *    all, so there's no real currency restriction to enforce.
 *
 * Any gateway not in this list — including every regional/local
 * processor this plugin has no verified data for — defaults to "assume
 * base-currency only". That's the safe direction to be wrong in: it
 * triggers the base-currency fallback (GatewayCurrencyGuard) rather
 * than letting an unverified foreign-currency charge reach a processor
 * that might reject it.
 *
 * Both the built-in list and unknown gateways can be corrected: by a
 * store owner's override (the "Payment Gateways" admin screen, stored
 * in 'wcmcs_gateway_currency_overrides'), or by the
 * 'wcmcs_gateway_currency_support' filter for a code-level integration.
 */
class GatewayCurrencyMatrix {

	public const WILDCARD = '*';

	private const BUILTIN_SUPPORT = array(
		'stripe' => self::WILDCARD,
		'paypal' => array(
			'AUD',
			'BRL',
			'CAD',
			'CNY',
			'CZK',
			'DKK',
			'EUR',
			'HKD',
			'HUF',
			'ILS',
			'JPY',
			'MYR',
			'MXN',
			'TWD',
			'NZD',
			'NOK',
			'PHP',
			'PLN',
			'GBP',
			'RUB',
			'SGD',
			'SEK',
			'CHF',
			'THB',
			'USD',
		),
		'bacs'   => self::WILDCARD,
		'cheque' => self::WILDCARD,
		'cod'    => self::WILDCARD,
	);

	/**
	 * @return array<string, string|string[]>
	 */
	public static function builtIn(): array {
		return self::BUILTIN_SUPPORT;
	}

	public static function supports( string $gatewayId, string $currencyCode ): bool {
		$overrides = (array) get_option( 'wcmcs_gateway_currency_overrides', array() );
		$entry     = $overrides[ $gatewayId ] ?? ( self::BUILTIN_SUPPORT[ $gatewayId ] ?? null );
		$entry     = apply_filters( 'wcmcs_gateway_currency_support', $entry, $gatewayId );

		if ( self::WILDCARD === $entry ) {
			return true;
		}

		if ( ! is_array( $entry ) ) {
			// Unknown gateway, no override, no filter override — the safe
			// default (see class docblock).
			return false;
		}

		return in_array( strtoupper( $currencyCode ), array_map( 'strtoupper', $entry ), true );
	}

	/**
	 * What the admin screen shows for a gateway: the wildcard, an
	 * explicit currency list, or null if nothing is known about it.
	 *
	 * @return string|string[]|null
	 */
	public static function entryFor( string $gatewayId ) {
		$overrides = (array) get_option( 'wcmcs_gateway_currency_overrides', array() );
		$entry     = $overrides[ $gatewayId ] ?? ( self::BUILTIN_SUPPORT[ $gatewayId ] ?? null );

		return apply_filters( 'wcmcs_gateway_currency_support', $entry, $gatewayId );
	}
}
