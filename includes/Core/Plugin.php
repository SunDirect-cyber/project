<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class. Singleton so the plugin is only ever bootstrapped
 * once, even if another script accidentally includes the main file twice.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Container $container;

	private bool $has_run = false;

	private function __construct() {
		$this->container = new Container();
	}

	// Prevent cloning and unserializing, both of which would create a
	// second instance of a class that is meant to be a singleton.
	private function __clone() {}

	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize a singleton.' );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * Boots the plugin. Safe to call multiple times — it only does
	 * anything on the first call.
	 */
	public function run(): void {
		if ( $this->has_run ) {
			return;
		}
		$this->has_run = true;

		// Registered unconditionally: this is what notices the site owner
		// and self-deactivates gracefully if a requirement stops being met
		// (e.g. WooCommerce gets deactivated later), instead of letting
		// missing WooCommerce classes cause a fatal error further down.
		Compatibility::register();

		Installer::run_if_needed();

		if ( ! Requirements::are_met() ) {
			return;
		}

		$this->register_services();
		$this->load_textdomain();

		do_action( 'wcmcs_loaded', $this );
	}

	/**
	 * Registers the internal services in the DI container. Each service
	 * is added here as it's built out; the container only instantiates a
	 * service the first time something actually asks for it.
	 */
	private function register_services(): void {
		$this->container->set(
			'order_repository',
			static fn () => new \WCMCS\Services\OrderRepository()
		);

		$this->container->set(
			'currency_repository',
			static fn () => new \WCMCS\Services\Currency\CurrencyRepository()
		);

		$this->container->set(
			'currency_formatter',
			static fn () => new \WCMCS\Services\Currency\CurrencyFormatter()
		);

		$this->container->set(
			'currency_service',
			static fn ( Container $c ) => new \WCMCS\Services\CurrencyService(
				$c->get( 'currency_repository' ),
				$c->get( 'currency_formatter' )
			)
		);

		$this->container->set(
			'logger_service',
			static fn () => new \WCMCS\Services\LoggerService()
		);

		$this->container->set(
			'rate_repository',
			static fn () => new \WCMCS\Services\ExchangeRate\RateRepository()
		);

		$this->container->set(
			'provider_registry',
			static fn ( Container $c ) => new \WCMCS\Services\ExchangeRate\ProviderRegistry(
				$c->get( 'logger_service' )
			)
		);

		$this->container->set(
			'rate_provider_chain',
			static fn ( Container $c ) => new \WCMCS\Services\ExchangeRate\RateProviderChain(
				$c->get( 'provider_registry' )->buildOrderedProviders(),
				$c->get( 'logger_service' )
			)
		);

		$this->container->set(
			'cache_service',
			static fn () => new \WCMCS\Services\CacheService()
		);

		$this->container->set(
			'session_service',
			static fn () => new \WCMCS\Services\SessionService()
		);

		$this->container->set(
			'rate_validator',
			static fn ( Container $c ) => new \WCMCS\Services\ExchangeRate\RateValidator(
				$c->get( 'pricing_rule_service' ),
				$c->get( 'logger_service' )
			)
		);

		$this->container->set(
			'rate_service',
			static fn ( Container $c ) => new \WCMCS\Services\ExchangeRate\RateService(
				$c->get( 'rate_provider_chain' ),
				$c->get( 'rate_repository' ),
				$c->get( 'cache_service' ),
				$c->get( 'rate_validator' )
			)
		);

		$this->container->set(
			'rate_failure_monitor',
			static fn () => new RateFailureMonitor()
		);

		$this->container->set(
			'currency_rule_repository',
			static fn () => new \WCMCS\Services\CurrencyRule\CurrencyRuleRepository()
		);

		$this->container->set(
			'pricing_rule_service',
			static fn ( Container $c ) => new \WCMCS\Services\CurrencyRule\PricingRuleService(
				$c->get( 'currency_rule_repository' )
			)
		);

		$this->container->set(
			'price_conversion_service',
			static fn ( Container $c ) => new \WCMCS\Services\PriceConversionService(
				$c->get( 'rate_service' ),
				$c->get( 'pricing_rule_service' ),
				$c->get( 'cache_service' )
			)
		);

		$this->container->set(
			'geo_country_detector',
			static fn () => new \WCMCS\Services\Geo\GeoCountryDetector()
		);

		$this->container->set(
			'geo_currency_resolver',
			static fn ( Container $c ) => new \WCMCS\Services\Geo\GeoCurrencyResolver(
				$c->get( 'geo_country_detector' ),
				$c->get( 'cache_service' ),
				$c->get( 'currency_service' )
			)
		);

		$this->container->set(
			'currency_persistence_service',
			static fn ( Container $c ) => new \WCMCS\Services\CurrencyPersistenceService(
				$c->get( 'session_service' )
			)
		);

		$this->container->set(
			'currency_resolution_engine',
			static fn ( Container $c ) => new \WCMCS\Services\CurrencyResolutionEngine(
				$c->get( 'currency_persistence_service' ),
				$c->get( 'geo_currency_resolver' )
			)
		);

		$this->container->set(
			'geo_suggestion_service',
			static fn ( Container $c ) => new \WCMCS\Services\Geo\GeoSuggestionService(
				$c->get( 'geo_currency_resolver' ),
				$c->get( 'currency_persistence_service' ),
				$c->get( 'currency_service' )
			)
		);

		$this->container->set(
			'currency_switcher_renderer',
			static fn ( Container $c ) => new \WCMCS\Frontend\CurrencySwitcherRenderer(
				$c->get( 'currency_service' ),
				$c->get( 'currency_persistence_service' )
			)
		);

		$this->container->set(
			'stats_service',
			static fn () => new \WCMCS\Services\StatsService()
		);

		$this->container->set(
			'revenue_by_currency_report',
			static fn () => new \WCMCS\Services\RevenueByCurrencyReport()
		);

		do_action( 'wcmcs_register_services', $this->container );

		Cron::register();

		\WCMCS\Admin\RateAjaxController::register();
		\WCMCS\Admin\RateHistoryAjaxController::register();
		\WCMCS\Admin\AdminMenu::register();

		\WCMCS\Frontend\CurrencySwitcherWidget::register();
		\WCMCS\Frontend\Shortcode::register();
		\WCMCS\Frontend\Block::register();
		\WCMCS\Frontend\CurrencySwitchController::register();
		\WCMCS\Frontend\FrontendHooks::register();
		\WCMCS\Frontend\UrlCurrencyOverride::register();
		\WCMCS\Frontend\PriceConverter::register();
		\WCMCS\Frontend\ShippingCostConverter::register();
		\WCMCS\Frontend\CouponConverter::register();
		\WCMCS\Frontend\GatewayCurrencyGuard::register();
		\WCMCS\Frontend\OrderCurrencyRecorder::register();
		\WCMCS\Frontend\GeoSuggestionController::register();

		MultilingualCompat::register();

		$container = $this->container;

		// Priority 20: after UrlCurrencyOverride's default-priority (10)
		// 'init' hook, so a ?currency= link this same request has already
		// been persisted by the time conflict resolution runs — it'll see
		// a non-null, source=url currency and correctly leave it alone.
		add_action(
			'init',
			static function () use ( $container ) {
				$container->get( 'currency_resolution_engine' )->resolve();
			},
			20
		);

		add_action(
			'wp_login',
			static function ( string $user_login, \WP_User $user ) use ( $container ) {
				$container->get( 'currency_persistence_service' )->migrateGuestSessionToUser( (int) $user->ID );
			},
			10,
			2
		);
	}

	private function load_textdomain(): void {
		load_plugin_textdomain( 'wc-multicurrency-switcher', false, dirname( WCMCS_BASENAME ) . '/languages' );
	}
}
