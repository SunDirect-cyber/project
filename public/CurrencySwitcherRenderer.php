<?php
declare( strict_types=1 );

namespace WCMCS\Frontend;

use WCMCS\Services\CurrencyPersistenceService;
use WCMCS\Services\CurrencyService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the currency switcher markup. Used by all three placement
 * mechanisms (widget, shortcode, block) so they can never drift out of
 * sync with each other — one render method, three ways to invoke it.
 */
class CurrencySwitcherRenderer {

	public const STYLE_DROPDOWN    = 'dropdown';
	public const STYLE_FLAG_GRID   = 'flag_grid';
	public const STYLE_BUTTON_LIST = 'button_list';

	/**
	 * Tracks whether the switcher was rendered anywhere on this page, so
	 * the flag sprite and JS/CSS only get output when something on the
	 * page actually needs them.
	 */
	private static bool $assetsNeeded = false;

	private CurrencyService $currencyService;
	private CurrencyPersistenceService $persistenceService;

	public function __construct( CurrencyService $currencyService, CurrencyPersistenceService $persistenceService ) {
		$this->currencyService    = $currencyService;
		$this->persistenceService = $persistenceService;
	}

	public static function assetsNeeded(): bool {
		return self::$assetsNeeded;
	}

	/**
	 * The site-wide default style (Display & Behavior admin screen) —
	 * what the widget, shortcode, and block all fall back to when
	 * they're placed without an explicit style of their own.
	 */
	public static function defaultStyle(): string {
		$style = (string) get_option( 'wcmcs_default_switcher_style', self::STYLE_DROPDOWN );
		$valid = array( self::STYLE_DROPDOWN, self::STYLE_FLAG_GRID, self::STYLE_BUTTON_LIST );

		return in_array( $style, $valid, true ) ? $style : self::STYLE_DROPDOWN;
	}

	/**
	 * @param array{style?: string, show_flag?: bool, show_code?: bool, show_symbol?: bool, show_name?: bool, class?: string} $args
	 */
	public function render( array $args = array() ): string {
		$args = wp_parse_args(
			$args,
			array(
				'style'       => self::defaultStyle(),
				'show_flag'   => true,
				'show_code'   => true,
				'show_symbol' => false,
				'show_name'   => false,
				'class'       => '',
			)
		);

		$enabledCodes = $this->currencyService->enabledCurrencyCodes();

		// Nothing to switch between — silently render nothing rather than
		// a dropdown with a single, unclickable option.
		if ( count( $enabledCodes ) < 2 ) {
			return '';
		}

		$currentCode = strtoupper( (string) ( $this->persistenceService->getCurrency() ?? $this->currencyService->baseCurrency()->code() ) );

		self::$assetsNeeded = true;

		$style = in_array( $args['style'], array( self::STYLE_DROPDOWN, self::STYLE_FLAG_GRID, self::STYLE_BUTTON_LIST ), true )
			? $args['style']
			: self::STYLE_DROPDOWN;

		$options = array();

		foreach ( $enabledCodes as $code ) {
			$currency = $this->currencyService->get( $code );

			if ( null === $currency ) {
				continue;
			}

			$options[] = array(
				'code'   => $code,
				'name'   => $currency->name(),
				'symbol' => $currency->symbol(),
			);
		}

		if ( count( $options ) < 2 ) {
			return '';
		}

		$method = 'render_' . $style;

		// The primary markup below is JS-driven (its click/change handlers
		// live in currency-switcher.js) — with JS disabled it would render
		// but be entirely unclickable, making currency switching silently
		// impossible for that visitor even though every price on the page
		// is already correctly server-rendered. <noscript> is a real
		// browser mechanism (not just a convention): its contents are
		// parsed and shown only when scripting is off, so this fallback
		// costs JS-enabled visitors nothing while giving JS-disabled ones
		// working, plain ?currency= links (see UrlCurrencyOverride) to
		// switch with instead.
		return $this->$method( $options, $currentCode, $args ) . $this->render_noscript_fallback( $options, $currentCode );
	}

	/**
	 * @param array{code: string, name: string, symbol: string}[] $options
	 */
	private function render_noscript_fallback( array $options, string $current ): string {
		$currentUrl = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		ob_start();
		?>
		<noscript>
			<!-- Only ever rendered by the browser when JS is disabled — hides
				the (otherwise unclickable, JS-driven) widget above and shows
				these plain links instead. -->
			<style>.wcmcs-switcher--dropdown,.wcmcs-switcher--buttons,.wcmcs-switcher--flag-grid{display:none}</style>
			<ul class="wcmcs-switcher wcmcs-switcher--noscript">
				<?php foreach ( $options as $option ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'currency', $option['code'], $currentUrl ) ); ?>"<?php echo $option['code'] === $current ? ' aria-current="true"' : ''; ?>>
							<?php echo esc_html( $option['code'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</noscript>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array{code: string, name: string, symbol: string}[] $options
	 */
	private function render_dropdown( array $options, string $current, array $args ): string {
		ob_start();
		?>
		<div class="wcmcs-switcher wcmcs-switcher--dropdown <?php echo esc_attr( $args['class'] ); ?>" data-wcmcs-switcher>
			<label class="screen-reader-text" for="wcmcs-switcher-<?php echo esc_attr( wp_unique_id() ); ?>">
				<?php esc_html_e( 'Currency', 'wc-multicurrency-switcher' ); ?>
			</label>
			<select class="wcmcs-switcher__select" data-wcmcs-switcher-select>
				<?php foreach ( $options as $option ) : ?>
					<option value="<?php echo esc_attr( $option['code'] ); ?>" <?php selected( $current, $option['code'] ); ?>>
						<?php echo esc_html( $this->optionLabel( $option, $args ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array{code: string, name: string, symbol: string}[] $options
	 */
	private function render_button_list( array $options, string $current, array $args ): string {
		ob_start();
		?>
		<div class="wcmcs-switcher wcmcs-switcher--buttons <?php echo esc_attr( $args['class'] ); ?>" data-wcmcs-switcher role="group" aria-label="<?php esc_attr_e( 'Currency', 'wc-multicurrency-switcher' ); ?>">
			<?php foreach ( $options as $option ) : ?>
				<button
					type="button"
					class="wcmcs-switcher__button<?php echo $option['code'] === $current ? ' is-active' : ''; ?>"
					data-wcmcs-switcher-option
					data-currency="<?php echo esc_attr( $option['code'] ); ?>"
					aria-pressed="<?php echo $option['code'] === $current ? 'true' : 'false'; ?>"
				>
					<?php echo $this->flagOrBadge( $option['code'], $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flagOrBadge() already escapes its own output internally (esc_attr/esc_html), see its docblock. ?>
					<?php echo esc_html( $this->optionLabel( $option, $args ) ); ?>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array{code: string, name: string, symbol: string}[] $options
	 */
	private function render_flag_grid( array $options, string $current, array $args ): string {
		$args['show_flag'] = true;

		ob_start();
		?>
		<div class="wcmcs-switcher wcmcs-switcher--flag-grid <?php echo esc_attr( $args['class'] ); ?>" data-wcmcs-switcher role="group" aria-label="<?php esc_attr_e( 'Currency', 'wc-multicurrency-switcher' ); ?>">
			<?php foreach ( $options as $option ) : ?>
				<button
					type="button"
					class="wcmcs-switcher__tile<?php echo $option['code'] === $current ? ' is-active' : ''; ?>"
					data-wcmcs-switcher-option
					data-currency="<?php echo esc_attr( $option['code'] ); ?>"
					aria-pressed="<?php echo $option['code'] === $current ? 'true' : 'false'; ?>"
					title="<?php echo esc_attr( $option['name'] ); ?>"
				>
					<?php echo $this->flagOrBadge( $option['code'], $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flagOrBadge() already escapes its own output internally (esc_attr/esc_html), see its docblock. ?>
					<span class="wcmcs-switcher__tile-code"><?php echo esc_html( $option['code'] ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array{code: string, name: string, symbol: string} $option
	 */
	private function optionLabel( array $option, array $args ): string {
		$parts = array();

		if ( ! empty( $args['show_code'] ) ) {
			$parts[] = $option['code'];
		}

		if ( ! empty( $args['show_symbol'] ) ) {
			$parts[] = $option['symbol'];
		}

		if ( ! empty( $args['show_name'] ) ) {
			$parts[] = $option['name'];
		}

		return implode( ' ', $parts ) ?: $option['code'];
	}

	private function flagOrBadge( string $code, array $args ): string {
		if ( empty( $args['show_flag'] ) ) {
			return '';
		}

		if ( FlagRegistry::hasFlag( $code ) ) {
			return sprintf(
				'<svg class="wcmcs-switcher__flag" aria-hidden="true" focusable="false"><use href="#%s"></use></svg>',
				esc_attr( FlagRegistry::symbolId( $code ) )
			);
		}

		// No bundled flag for this currency — a plain badge, never a
		// guessed or wrong flag.
		return sprintf( '<span class="wcmcs-switcher__flag wcmcs-switcher__flag--badge" aria-hidden="true">%s</span>', esc_html( substr( $code, 0, 2 ) ) );
	}
}
