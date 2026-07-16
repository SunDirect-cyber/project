<?php
namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything that isn't a placement mechanism on its own (widget,
 * shortcode, block already cover that) but is needed to support them:
 * enqueuing JS/CSS, inlining the flag sprite once per page, and the two
 * extra "position options" from the spec that aren't just "drop this
 * somewhere" — floating widget and header menu integration.
 */
class FrontendHooks {

	private static bool $spritePrinted = false;

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_styles' ) );
		add_action( 'wp_footer', array( self::class, 'maybe_enqueue_footer_assets' ), 1 );
		add_action( 'wp_footer', array( self::class, 'maybe_print_sprite' ), 2 );
		add_action( 'wp_footer', array( self::class, 'maybe_render_floating_widget' ), 15 );
		add_filter( 'wp_nav_menu_items', array( self::class, 'maybe_inject_into_menu' ), 10, 2 );
	}

	/** Suffix picked once per request: '.min' unless SCRIPT_DEBUG is on. */
	private static function assetSuffix(): string {
		return ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
	}

	/**
	 * Loads the switcher CSS in <head> — where it belongs, since loading
	 * it late causes a flash of unstyled content for any switcher that
	 * renders earlier in the page (a sidebar widget, a shortcode in the
	 * content) — but only on pages that can actually show a switcher, so
	 * pages with none of the placements below don't pay for CSS they'll
	 * never use. This is necessarily a best-effort check run before the
	 * page has actually rendered: the floating widget and the nav-menu
	 * injection can appear on literally any page once turned on (a
	 * template part, not tied to one post), so those two settings being
	 * enabled always counts as "needed" here, while the shortcode/block/
	 * widget placements can be checked precisely via has_shortcode(),
	 * has_block(), and is_active_widget().
	 */
	public static function maybe_enqueue_styles(): void {
		if ( ! self::pageLikelyNeedsAssets() ) {
			return;
		}

		$suffix = self::assetSuffix();
		wp_enqueue_style( 'wcmcs-currency-switcher', WCMCS_URL . "assets/css/currency-switcher{$suffix}.css", array(), WCMCS_VERSION );
	}

	private static function pageLikelyNeedsAssets(): bool {
		if ( ! empty( get_option( 'wcmcs_floating_widget_enabled' ) ) ) {
			return true;
		}

		if ( '' !== (string) get_option( 'wcmcs_menu_location', '' ) ) {
			return true;
		}

		$post = get_post();

		if ( $post instanceof \WP_Post ) {
			if ( has_shortcode( $post->post_content, Shortcode::TAG ) ) {
				return true;
			}

			if ( function_exists( 'has_block' ) && has_block( Block::NAME, $post ) ) {
				return true;
			}
		}

		if ( is_active_widget( false, false, 'wcmcs_currency_switcher', true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Runs late in the page render (wp_footer), by which point every
	 * widget/shortcode/block on the page has already rendered — so
	 * whether the switcher's JS is actually needed is finally known.
	 * wp_enqueue_script() here still works correctly: WordPress prints
	 * footer scripts via a later wp_footer priority (20), so anything
	 * enqueued up through priority ~19 still gets picked up.
	 */
	public static function maybe_enqueue_footer_assets(): void {
		if ( ! CurrencySwitcherRenderer::assetsNeeded() ) {
			return;
		}

		$suffix = self::assetSuffix();
		wp_enqueue_script( 'wcmcs-currency-switcher', WCMCS_URL . "assets/js/currency-switcher{$suffix}.js", array(), WCMCS_VERSION, true );

		wp_localize_script(
			'wcmcs-currency-switcher',
			'wcmcsSwitcher',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( CurrencySwitchController::ACTION ),
			)
		);
	}

	/**
	 * Inlines the flag sprite's <symbol> defs once, near the end of the
	 * document. A same-document <use href="#id"> can reference a
	 * <symbol> that appears later in the DOM — every modern browser
	 * resolves this live, not just top-to-bottom at parse time — so
	 * footer placement is safe even though switcher markup earlier on
	 * the page references ids defined down here.
	 */
	public static function maybe_print_sprite(): void {
		if ( self::$spritePrinted || ! CurrencySwitcherRenderer::assetsNeeded() ) {
			return;
		}

		$path = WCMCS_PATH . 'assets/images/flags-sprite.svg';

		if ( ! file_exists( $path ) ) {
			return;
		}

		self::$spritePrinted = true;

		// The sprite file's contents are static, plugin-authored SVG
		// markup (see flags-sprite.svg) — not user input — so this is
		// safe to output directly.
		echo file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * "Floating widget" placement: a fixed-position switcher, opt-in via
	 * the 'wcmcs_floating_widget_enabled' setting.
	 */
	public static function maybe_render_floating_widget(): void {
		if ( empty( get_option( 'wcmcs_floating_widget_enabled' ) ) ) {
			return;
		}

		/** @var CurrencySwitcherRenderer $renderer */
		$renderer = Plugin::instance()->container()->get( 'currency_switcher_renderer' );
		$style    = (string) get_option( 'wcmcs_floating_widget_style', CurrencySwitcherRenderer::defaultStyle() );

		$html = $renderer->render( array( 'style' => $style ) );

		if ( '' === $html ) {
			return;
		}

		echo '<div class="wcmcs-floating-widget">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * "Header menu integration" placement: appends the switcher as an
	 * extra item on whichever classic nav menu location the admin picked
	 * (via the 'wcmcs_menu_location' setting) — no theme editing required.
	 * Block themes get the same result by inserting the Currency Switcher
	 * block directly into a Navigation/Header template part instead.
	 *
	 * @param string    $items
	 * @param \stdClass $args
	 */
	public static function maybe_inject_into_menu( $items, $args ): string {
		$targetLocation = (string) get_option( 'wcmcs_menu_location', '' );

		if ( '' === $targetLocation || ( $args->theme_location ?? '' ) !== $targetLocation ) {
			return $items;
		}

		/** @var CurrencySwitcherRenderer $renderer */
		$renderer = Plugin::instance()->container()->get( 'currency_switcher_renderer' );
		$html     = $renderer->render( array( 'style' => CurrencySwitcherRenderer::defaultStyle() ) );

		if ( '' === $html ) {
			return $items;
		}

		return $items . '<li class="wcmcs-menu-item menu-item">' . $html . '</li>';
	}
}
