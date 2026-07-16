<?php
declare( strict_types=1 );

namespace WCMCS\Admin;

use WCMCS\Core\Plugin;
use WCMCS\Services\Currency\CurrencyOverrideService;
use WCMCS\Services\CurrencyRule\RoundingRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Currencies" admin screen: enable/disable/reorder currencies (drag
 * and drop), a per-currency settings panel exposing everything built in
 * earlier work (rounding, locked rate, markup, and now formatting
 * overrides), and a preview mode. Built with plain HTML5 drag-and-drop
 * and vanilla JS rather than jQuery UI's sortable() or a React build
 * step this plugin has no tooling for.
 */
class CurrencyManagementPage {

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		/** @var \WCMCS\Services\Currency\CurrencyService $currencyService */
		$currencyService = Plugin::instance()->container()->get( 'currency_service' );
		/** @var \WCMCS\Services\CurrencyRule\PricingRuleService $pricingRules */
		$pricingRules = Plugin::instance()->container()->get( 'pricing_rule_service' );

		$all     = $currencyService->all();
		$enabled = array_values( array_filter( array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) ), static fn ( $c ) => isset( $all[ $c ] ) ) );
		$base    = $currencyService->baseCurrency()->code();

		$settings = array();

		foreach ( $enabled as $code ) {
			$currency = $all[ $code ];
			$rounding = $pricingRules->getRoundingConfig( $code );

			$settings[ $code ] = array(
				'name'               => $currency->name(),
				'symbol'             => $currency->symbol(),
				'decimals'           => $currency->decimals(),
				'symbol_position'    => $currency->symbolPosition(),
				'thousand_separator' => $currency->thousandSeparator(),
				'decimal_separator'  => $currency->decimalSeparator(),
				'has_override'       => ! empty( CurrencyOverrideService::get( $code ) ),
				'locked_rate'        => $pricingRules->getLockedRate( $code ),
				'markup_percent'     => $pricingRules->getMarkupPercent( $code ),
				'rounding_mode'      => $rounding['mode'],
				'rounding_step'      => $rounding['step'],
				'rounding_offset'    => $rounding['offset'],
			);
		}

		$available = array();

		foreach ( $all as $code => $currency ) {
			if ( in_array( $code, $enabled, true ) ) {
				continue;
			}

			$available[] = array( 'code' => $code, 'name' => $currency->name() );
		}

		$products = function_exists( 'get_posts' ) ? get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		) : array();

		wp_enqueue_style( 'wcmcs-admin', WCMCS_URL . 'assets/css/admin.css', array(), WCMCS_VERSION );
		wp_enqueue_script( 'wcmcs-currency-management', WCMCS_URL . 'assets/js/currency-management.js', array(), WCMCS_VERSION, true );
		wp_localize_script(
			'wcmcs-currency-management',
			'wcmcsCurrencyManagement',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( CurrencyManagementAjaxController::NONCE ),
				'baseCurrency'    => $base,
				'roundingModes'   => RoundingRule::modes(),
				'i18n'            => array(
					'saved'          => __( 'Saved.', 'wc-multicurrency-switcher' ),
					'saveFailed'     => __( 'Save failed.', 'wc-multicurrency-switcher' ),
					'confirmRemove'  => __( 'Remove this currency? Its per-currency settings will be kept if you re-add it later.', 'wc-multicurrency-switcher' ),
					'noneSelected'   => __( 'Select at least one currency to add.', 'wc-multicurrency-switcher' ),
					'previewing'     => __( 'Calculating…', 'wc-multicurrency-switcher' ),
				),
			)
		);

		?>
		<div class="wrap wcmcs-currency-management">
			<h1><?php esc_html_e( 'Currencies', 'wc-multicurrency-switcher' ); ?></h1>
			<p><?php esc_html_e( 'Drag to reorder. This order is what shoppers see in the currency switcher.', 'wc-multicurrency-switcher' ); ?></p>

			<ul id="wcmcs-enabled-list" class="wcmcs-currency-list">
				<?php foreach ( $enabled as $code ) : ?>
					<?php $s = $settings[ $code ]; ?>
					<li class="wcmcs-currency-row" draggable="true" data-code="<?php echo esc_attr( $code ); ?>">
						<span class="wcmcs-currency-row__handle" aria-hidden="true">&#9776;</span>
						<span class="wcmcs-currency-row__code"><?php echo esc_html( $code ); ?></span>
						<span class="wcmcs-currency-row__name"><?php echo esc_html( $s['name'] ); ?></span>
						<?php if ( $code === $base ) : ?>
							<span class="wcmcs-currency-row__badge"><?php esc_html_e( 'base', 'wc-multicurrency-switcher' ); ?></span>
						<?php endif; ?>
						<button type="button" class="button" data-wcmcs-configure="<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Configure', 'wc-multicurrency-switcher' ); ?></button>
						<?php if ( $code !== $base ) : ?>
							<button type="button" class="button-link-delete" data-wcmcs-remove="<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Remove', 'wc-multicurrency-switcher' ); ?></button>
						<?php endif; ?>

						<div class="wcmcs-currency-settings" data-wcmcs-settings-panel="<?php echo esc_attr( $code ); ?>" hidden>
							<div class="wcmcs-currency-settings__grid">
								<label><?php esc_html_e( 'Symbol', 'wc-multicurrency-switcher' ); ?>
									<input type="text" data-field="symbol" value="<?php echo esc_attr( $s['symbol'] ); ?>">
								</label>
								<label><?php esc_html_e( 'Decimal places', 'wc-multicurrency-switcher' ); ?>
									<input type="number" min="0" max="4" data-field="decimals" value="<?php echo esc_attr( (string) $s['decimals'] ); ?>">
								</label>
								<label><?php esc_html_e( 'Symbol position', 'wc-multicurrency-switcher' ); ?>
									<select data-field="symbol_position">
										<option value="before" <?php selected( $s['symbol_position'], 'before' ); ?>><?php esc_html_e( 'Before amount', 'wc-multicurrency-switcher' ); ?></option>
										<option value="after" <?php selected( $s['symbol_position'], 'after' ); ?>><?php esc_html_e( 'After amount', 'wc-multicurrency-switcher' ); ?></option>
									</select>
								</label>
								<label><?php esc_html_e( 'Thousand separator', 'wc-multicurrency-switcher' ); ?>
									<input type="text" maxlength="1" data-field="thousand_separator" value="<?php echo esc_attr( $s['thousand_separator'] ); ?>">
								</label>
								<label><?php esc_html_e( 'Decimal separator', 'wc-multicurrency-switcher' ); ?>
									<input type="text" maxlength="1" data-field="decimal_separator" value="<?php echo esc_attr( $s['decimal_separator'] ); ?>">
								</label>
								<label><?php esc_html_e( 'Markup %', 'wc-multicurrency-switcher' ); ?>
									<input type="number" step="0.01" data-field="markup_percent" value="<?php echo esc_attr( (string) $s['markup_percent'] ); ?>">
								</label>
								<label><?php esc_html_e( 'Locked rate (blank = live rate)', 'wc-multicurrency-switcher' ); ?>
									<input type="number" step="0.000001" data-field="locked_rate" value="<?php echo esc_attr( null === $s['locked_rate'] ? '' : (string) $s['locked_rate'] ); ?>">
								</label>
								<label><?php esc_html_e( 'Rounding', 'wc-multicurrency-switcher' ); ?>
									<select data-field="rounding_mode">
										<option value="none" <?php selected( $s['rounding_mode'], 'none' ); ?>><?php esc_html_e( 'None', 'wc-multicurrency-switcher' ); ?></option>
										<option value="nearest" <?php selected( $s['rounding_mode'], 'nearest' ); ?>><?php esc_html_e( 'Nearest step', 'wc-multicurrency-switcher' ); ?></option>
										<option value="charm" <?php selected( $s['rounding_mode'], 'charm' ); ?>><?php esc_html_e( 'Charm (psychological)', 'wc-multicurrency-switcher' ); ?></option>
									</select>
								</label>
								<label><?php esc_html_e( 'Rounding step', 'wc-multicurrency-switcher' ); ?>
									<input type="number" step="0.01" min="0.01" data-field="rounding_step" value="<?php echo esc_attr( (string) $s['rounding_step'] ); ?>">
								</label>
								<label><?php esc_html_e( 'Charm offset', 'wc-multicurrency-switcher' ); ?>
									<input type="number" step="0.01" data-field="rounding_offset" value="<?php echo esc_attr( (string) $s['rounding_offset'] ); ?>">
								</label>
							</div>
							<button type="button" class="button button-primary" data-wcmcs-save-settings="<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Save settings', 'wc-multicurrency-switcher' ); ?></button>
							<span class="wcmcs-status" data-wcmcs-settings-status></span>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<button type="button" class="button button-primary" id="wcmcs-save-order"><?php esc_html_e( 'Save order', 'wc-multicurrency-switcher' ); ?></button>
				<span class="wcmcs-status" id="wcmcs-order-status"></span>
			</p>

			<h2><?php esc_html_e( 'Add Currencies', 'wc-multicurrency-switcher' ); ?></h2>
			<input type="search" id="wcmcs-currency-search" placeholder="<?php esc_attr_e( 'Search 155 currencies by name or code…', 'wc-multicurrency-switcher' ); ?>" class="regular-text">
			<div id="wcmcs-available-list" class="wcmcs-available-list">
				<?php foreach ( $available as $currency ) : ?>
					<label class="wcmcs-available-item" data-search="<?php echo esc_attr( strtolower( $currency['code'] . ' ' . $currency['name'] ) ); ?>">
						<input type="checkbox" value="<?php echo esc_attr( $currency['code'] ); ?>">
						<?php echo esc_html( $currency['code'] . ' — ' . $currency['name'] ); ?>
					</label>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button button-primary" id="wcmcs-add-selected"><?php esc_html_e( 'Add selected', 'wc-multicurrency-switcher' ); ?></button>
			</p>

			<h2><?php esc_html_e( 'Preview', 'wc-multicurrency-switcher' ); ?></h2>
			<p><?php esc_html_e( 'See how a product\'s price would look in every enabled currency, without changing your own browsing currency.', 'wc-multicurrency-switcher' ); ?></p>
			<select id="wcmcs-preview-product">
				<option value=""><?php esc_html_e( '— Select a product —', 'wc-multicurrency-switcher' ); ?></option>
				<?php foreach ( $products as $product ) : ?>
					<option value="<?php echo esc_attr( (string) $product->ID ); ?>"><?php echo esc_html( $product->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" id="wcmcs-preview-button"><?php esc_html_e( 'Preview', 'wc-multicurrency-switcher' ); ?></button>
			<div id="wcmcs-preview-results"></div>
		</div>
		<?php
	}
}
