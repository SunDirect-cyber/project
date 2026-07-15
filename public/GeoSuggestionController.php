<?php
namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;
use WCMCS\Services\Currency\Currency;
use WCMCS\Services\Geo\GeoSuggestionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the optional "We think you're in Germany — switch to EUR?"
 * confirmation modal, and handles its "no thanks" dismissal. Only ever
 * shows up when confirmation mode is enabled and GeoSuggestionService
 * has something worth asking about (see there for the full logic on
 * when a suggestion applies).
 */
class GeoSuggestionController {

	public const DISMISS_ACTION = 'wcmcs_dismiss_geo_suggestion';
	public const NONCE          = 'wcmcs_geo_suggestion_nonce';

	public static function register(): void {
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( self::class, 'handle_dismiss' ) );
		add_action( 'wp_ajax_nopriv_' . self::DISMISS_ACTION, array( self::class, 'handle_dismiss' ) );
		add_action( 'wp_footer', array( self::class, 'maybe_render_modal' ), 20 );
	}

	public static function handle_dismiss(): void {
		check_ajax_referer( self::DISMISS_ACTION, 'nonce' );

		/** @var GeoSuggestionService $suggestionService */
		$suggestionService = Plugin::instance()->container()->get( 'geo_suggestion_service' );
		$suggestionService->dismiss();

		wp_send_json_success();
	}

	public static function maybe_render_modal(): void {
		if ( ! GeoSuggestionService::isConfirmationModeEnabled() ) {
			return;
		}

		/** @var GeoSuggestionService $suggestionService */
		$suggestionService = Plugin::instance()->container()->get( 'geo_suggestion_service' );
		$suggestion         = $suggestionService->getSuggestion();

		if ( null === $suggestion ) {
			return;
		}

		/** @var \WCMCS\Services\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );
		$currency         = $currencyService->get( $suggestion['currency'] );
		$currencyLabel    = $currency instanceof Currency ? sprintf( '%s (%s)', $currency->name(), $currency->code() ) : $suggestion['currency'];

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_enqueue_script( 'wcmcs-geo-suggestion', WCMCS_URL . "assets/js/geo-suggestion{$suffix}.js", array(), WCMCS_VERSION, true );
		wp_localize_script(
			'wcmcs-geo-suggestion',
			'wcmcsGeoSuggestion',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'switchNonce'   => wp_create_nonce( CurrencySwitchController::ACTION ),
				'dismissNonce'  => wp_create_nonce( self::DISMISS_ACTION ),
				'currency'      => $suggestion['currency'],
			)
		);

		printf(
			'<div class="wcmcs-geo-modal" data-wcmcs-geo-modal role="dialog" aria-modal="true" aria-label="%1$s">
				<div class="wcmcs-geo-modal__box">
					<p>%2$s</p>
					<div class="wcmcs-geo-modal__actions">
						<button type="button" class="button button-primary" data-wcmcs-geo-confirm>%3$s</button>
						<button type="button" class="button" data-wcmcs-geo-dismiss>%4$s</button>
					</div>
				</div>
			</div>',
			esc_attr__( 'Currency suggestion', 'wc-multicurrency-switcher' ),
			esc_html(
				sprintf(
					/* translators: %s: suggested currency, e.g. "Euro (EUR)" */
					__( 'It looks like you\'re visiting from a location that uses %s. Switch to it?', 'wc-multicurrency-switcher' ),
					$currencyLabel
				)
			),
			esc_html__( 'Switch', 'wc-multicurrency-switcher' ),
			esc_html__( 'No thanks', 'wc-multicurrency-switcher' )
		);
	}
}
