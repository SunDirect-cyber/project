<?php
namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "wcmcs/currency-switcher" Gutenberg block. Registered as a dynamic
 * block (PHP render_callback, no saved markup) so it always renders
 * through the same CurrencySwitcherRenderer as the widget and
 * shortcode, and picks up admin setting changes immediately instead of
 * needing every post re-saved.
 *
 * No @wordpress/scripts/webpack build step here — the editor script is
 * plain JS using the wp.* globals WordPress already provides, registered
 * directly via register_block_type()'s args array instead of a
 * block.json + build pipeline.
 */
class Block {

	public const NAME = 'wcmcs/currency-switcher';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
	}

	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			self::NAME,
			array(
				'attributes'      => array(
					'style'      => array( 'type' => 'string', 'default' => CurrencySwitcherRenderer::STYLE_DROPDOWN ),
					'showFlag'   => array( 'type' => 'boolean', 'default' => true ),
					'showCode'   => array( 'type' => 'boolean', 'default' => true ),
					'showSymbol' => array( 'type' => 'boolean', 'default' => false ),
					'showName'   => array( 'type' => 'boolean', 'default' => false ),
				),
				'render_callback' => array( self::class, 'render' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render( array $attributes ): string {
		/** @var CurrencySwitcherRenderer $renderer */
		$renderer = Plugin::instance()->container()->get( 'currency_switcher_renderer' );

		return $renderer->render(
			array(
				'style'       => $attributes['style'] ?? CurrencySwitcherRenderer::STYLE_DROPDOWN,
				'show_flag'   => $attributes['showFlag'] ?? true,
				'show_code'   => $attributes['showCode'] ?? true,
				'show_symbol' => $attributes['showSymbol'] ?? false,
				'show_name'   => $attributes['showName'] ?? false,
			)
		);
	}

	public static function enqueue_editor_assets(): void {
		wp_enqueue_script(
			'wcmcs-block-editor',
			WCMCS_URL . 'assets/js/block-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			WCMCS_VERSION,
			true
		);
	}
}
