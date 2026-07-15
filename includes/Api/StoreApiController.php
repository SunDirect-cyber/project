<?php
namespace WCMCS\Api;

use WCMCS\Core\Plugin;
use WCMCS\Core\RateLimiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public, unauthenticated REST endpoints for headless/decoupled
 * WooCommerce storefronts (a Next.js/React front end with no PHP-
 * rendered pages at all) to fetch currency data and convert prices —
 * namespace wcmcs/v1/store, deliberately separate from RestController's
 * wcmcs/v1 endpoints, which require an authenticated Application
 * Password and exist for internal BI tooling, not a public storefront.
 *
 * Design choices specific to a decoupled front end:
 *  - No server-side "set the visitor's currency" endpoint. A headless
 *    front end doesn't share PHP sessions/cookies with this backend the
 *    way a normal server-rendered page does, and building a parallel
 *    cross-origin session/nonce mechanism just for this would be a lot
 *    of security-sensitive surface for something the front end can do
 *    more simply itself: keep the chosen currency in its own client-side
 *    state (localStorage) and pass it explicitly as a parameter on every
 *    request that needs it. That's the standard pattern for decoupled
 *    commerce front ends generally, not something specific to this
 *    plugin.
 *  - CORS is opt-in, not open-by-default: these endpoints are safe to
 *    read cross-origin (no secrets, read-only or pure computation), but
 *    this plugin still only sends Access-Control-Allow-Origin for
 *    origins the store owner has explicitly allowed (see
 *    ApiAccessPage), never a blanket "*". A default-open CORS policy on
 *    every install would be a worse default than requiring one
 *    deliberate opt-in step.
 *  - The convert endpoint is rate-limited per IP — the only one of
 *    these three that does any real computation per request, so it's
 *    the one worth protecting from being hammered by a script even
 *    though nothing here is expensive enough to be a true DoS risk on
 *    its own.
 */
class StoreApiController {

	private const NAMESPACE_ = 'wcmcs/v1/store';

	private const CONVERT_RATE_LIMIT_WINDOW  = 2; // seconds between requests, per IP
	private const CONVERT_RATE_LIMIT_ACTION  = 'wcmcs_store_api_convert';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'maybe_send_cors_headers' ), 10, 4 );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_,
			'/currencies',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_currencies' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/rates',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_rates' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/convert',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'convert' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'amount' => array( 'required' => true ),
					'to'     => array( 'required' => true, 'type' => 'string' ),
					'from'   => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Sends Access-Control-Allow-Origin for requests to this namespace's
	 * routes only, and only when the request's Origin header matches one
	 * of the store owner's explicitly configured allowed origins — see
	 * ApiAccessPage. Every other REST namespace (including this plugin's
	 * own authenticated wcmcs/v1 routes) is untouched.
	 *
	 * @param bool              $served
	 * @param \WP_HTTP_Response $result
	 * @param \WP_REST_Request  $request
	 * @param \WP_REST_Server   $server
	 */
	public static function maybe_send_cors_headers( $served, $result, $request, $server ) {
		if ( ! $request instanceof \WP_REST_Request || 0 !== strpos( $request->get_route(), '/' . self::NAMESPACE_ ) ) {
			return $served;
		}

		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

		if ( '' === $origin || ! self::isOriginAllowed( $origin ) ) {
			return $served;
		}

		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Methods: GET' );
		header( 'Vary: Origin' );

		return $served;
	}

	private static function isOriginAllowed( string $origin ): bool {
		$allowed = array_filter( array_map( 'trim', explode( "\n", (string) get_option( 'wcmcs_headless_allowed_origins', '' ) ) ) );

		return in_array( rtrim( $origin, '/' ), array_map( static fn ( $o ) => rtrim( $o, '/' ), $allowed ), true );
	}

	public static function get_currencies(): \WP_REST_Response {
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );
		$base             = $currencyService->baseCurrency()->code();

		$data = array();

		foreach ( $currencyService->enabledCurrencyCodes() as $code ) {
			$currency = $currencyService->get( $code );

			if ( null === $currency ) {
				continue;
			}

			$data[] = array_merge( $currency->toArray(), array( 'is_base' => $code === $base ) );
		}

		return new \WP_REST_Response(
			array(
				'base'       => $base,
				'currencies' => $data,
			),
			200
		);
	}

	public static function get_rates(): \WP_REST_Response {
		$container = Plugin::instance()->container();
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );
		/** @var \WCMCS\Services\PriceConversionService $priceConversion */
		$priceConversion = $container->get( 'price_conversion_service' );

		$base = $currencyService->baseCurrency()->code();
		$rates = array();

		foreach ( $currencyService->enabledCurrencyCodes() as $code ) {
			if ( $code === $base ) {
				continue;
			}

			// The *effective* rate (locked/marked-up), not the raw market
			// rate — this is what convert() below actually uses, and a
			// headless front end doing its own client-side math should
			// see the same number, not a raw rate that would silently
			// disagree with it.
			$rates[ $code ] = $priceConversion->getEffectiveRate( $base, $code );
		}

		return new \WP_REST_Response( array( 'base' => $base, 'rates' => $rates ), 200 );
	}

	public static function convert( \WP_REST_Request $request ) {
		$ip = self::clientIp();

		if ( ! RateLimiter::attempt( self::CONVERT_RATE_LIMIT_ACTION, $ip, self::CONVERT_RATE_LIMIT_WINDOW ) ) {
			return new \WP_REST_Response(
				array( 'message' => __( 'Too many requests — please slow down.', 'wc-multicurrency-switcher' ) ),
				429
			);
		}

		$amountParam = $request->get_param( 'amount' );

		if ( ! is_numeric( $amountParam ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'amount must be a number.', 'wc-multicurrency-switcher' ) ), 400 );
		}

		$amount = (float) $amountParam;

		if ( $amount < 0 ) {
			return new \WP_REST_Response( array( 'message' => __( 'amount cannot be negative.', 'wc-multicurrency-switcher' ) ), 400 );
		}

		$container = Plugin::instance()->container();
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );

		$enabled = $currencyService->enabledCurrencyCodes();
		$base    = $currencyService->baseCurrency()->code();

		$to   = strtoupper( (string) $request->get_param( 'to' ) );
		$from = $request->get_param( 'from' ) ? strtoupper( (string) $request->get_param( 'from' ) ) : $base;

		if ( ! in_array( $to, $enabled, true ) || ( $from !== $base && ! in_array( $from, $enabled, true ) ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Unknown or disabled currency code.', 'wc-multicurrency-switcher' ) ), 400 );
		}

		/** @var \WCMCS\Services\PriceConversionService $priceConversion */
		$priceConversion = $container->get( 'price_conversion_service' );

		if ( $from === $to ) {
			$converted = $amount;
		} else {
			$converted = $priceConversion->convert( $amount, $from, $to );
		}

		if ( null === $converted ) {
			return new \WP_REST_Response( array( 'message' => __( 'No exchange rate is currently available for that pair.', 'wc-multicurrency-switcher' ) ), 503 );
		}

		$currency = $currencyService->get( $to );
		$decimals = null !== $currency ? $currency->decimals() : 2;
		$converted = round( $converted, $decimals );

		return new \WP_REST_Response(
			array(
				'from'      => $from,
				'to'        => $to,
				'amount'    => $amount,
				'converted' => $converted,
				'formatted' => $currencyService->format( $converted, $to ),
			),
			200
		);
	}

	private static function clientIp(): string {
		if ( class_exists( \WC_Geolocation::class ) ) {
			return \WC_Geolocation::get_ip_address();
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}
}
