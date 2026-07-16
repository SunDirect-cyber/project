<?php
namespace WCMCS\Api;

use WCMCS\Core\CapabilityManager;
use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API for external BI tools (Google Data Studio, Power BI, etc.)
 * to pull currency/rate/analytics data — namespace wcmcs/v1.
 *
 * Authentication deliberately uses WordPress's own built-in Application
 * Passwords (core since 5.6) rather than a custom API-key scheme: it's
 * the standard the whole REST API already supports, users generate
 * credentials from their own profile screen, and every endpoint here
 * just gates on current_user_can() exactly like the admin screens do —
 * WordPress resolves the authenticated user from the request before
 * permission_callback ever runs, so there's nothing bespoke to secure
 * or get wrong.
 */
class RestController {

	private const NAMESPACE_ = 'wcmcs/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_,
			'/currencies',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_currencies' ),
				'permission_callback' => array( self::class, 'permission_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/rates',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_rates' ),
				'permission_callback' => array( self::class, 'permission_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/rates/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_rate_history' ),
				'permission_callback' => array( self::class, 'permission_check' ),
				'args'                => array(
					'target' => array(
						'required' => true,
						'type'     => 'string',
					),
					'limit'  => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 30,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/analytics/revenue',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_revenue' ),
				'permission_callback' => array( self::class, 'permission_check' ),
				'args'                => array(
					'from' => array(
						'required' => true,
						'type'     => 'string',
					),
					'to'   => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/analytics/impact',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_impact' ),
				'permission_callback' => array( self::class, 'permission_check' ),
				'args'                => array(
					'from' => array(
						'required' => true,
						'type'     => 'string',
					),
					'to'   => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	public static function permission_check(): bool {
		return current_user_can( CapabilityManager::CAP );
	}

	public static function get_currencies(): \WP_REST_Response {
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );
		$enabled         = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );

		$data = array();

		foreach ( $enabled as $code ) {
			$currency = $currencyService->get( $code );

			if ( null === $currency ) {
				continue;
			}

			$data[] = array_merge( $currency->toArray(), array( 'is_base' => $code === $currencyService->baseCurrency()->code() ) );
		}

		return new \WP_REST_Response( $data, 200 );
	}

	public static function get_rates(): \WP_REST_Response {
		$container = Plugin::instance()->container();
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );
		/** @var \WCMCS\Services\ExchangeRate\RateService $rateService */
		$rateService = $container->get( 'rate_service' );

		$base    = $currencyService->baseCurrency()->code();
		$enabled = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );

		$rates = array();

		foreach ( $enabled as $code ) {
			if ( $code === $base ) {
				continue;
			}

			$rates[ $code ] = $rateService->getRate( $base, $code );
		}

		return new \WP_REST_Response(
			array(
				'base'  => $base,
				'rates' => $rates,
			),
			200
		);
	}

	public static function get_rate_history( \WP_REST_Request $request ): \WP_REST_Response {
		$container = Plugin::instance()->container();
		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = $container->get( 'currency_service' );
		/** @var \WCMCS\Services\ExchangeRate\RateRepository $rateRepository */
		$rateRepository = $container->get( 'rate_repository' );

		$base   = $currencyService->baseCurrency()->code();
		$target = strtoupper( (string) $request->get_param( 'target' ) );
		$limit  = min( 500, max( 1, (int) $request->get_param( 'limit' ) ) );

		return new \WP_REST_Response(
			array(
				'base'    => $base,
				'target'  => $target,
				'history' => $rateRepository->history( $base, $target, $limit ),
			),
			200
		);
	}

	public static function get_revenue( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var \WCMCS\Services\Analytics\CurrencyStatsRepository $repository */
		$repository = Plugin::instance()->container()->get( 'stats_repository' );

		$from = self::sanitizeDate( (string) $request->get_param( 'from' ) );
		$to   = self::sanitizeDate( (string) $request->get_param( 'to' ) );

		if ( null === $from || null === $to ) {
			return new \WP_REST_Response( array( 'message' => __( 'Invalid date range.', 'wc-multicurrency-switcher' ) ), 400 );
		}

		return new \WP_REST_Response( $repository->totalsByCurrency( $from, $to ), 200 );
	}

	public static function get_impact( \WP_REST_Request $request ): \WP_REST_Response {
		$container = Plugin::instance()->container();

		$from = self::sanitizeDate( (string) $request->get_param( 'from' ) );
		$to   = self::sanitizeDate( (string) $request->get_param( 'to' ) );

		if ( null === $from || null === $to ) {
			return new \WP_REST_Response( array( 'message' => __( 'Invalid date range.', 'wc-multicurrency-switcher' ) ), 400 );
		}

		$report = new \WCMCS\Services\Analytics\CurrencyImpactReport(
			$container->get( 'rate_service' ),
			$container->get( 'currency_service' )
		);

		return new \WP_REST_Response( $report->generate( $from, $to ), 200 );
	}

	private static function sanitizeDate( string $value ): ?string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}
}
