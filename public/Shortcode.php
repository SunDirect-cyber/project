<?php
declare( strict_types=1 );

namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [wcmcs_currency_switcher] — placeable anywhere shortcodes render:
 * post/page content, text widgets, many page builders, theme templates
 * via do_shortcode().
 */
class Shortcode {

	public const TAG = 'wcmcs_currency_switcher';

	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	/**
	 * @param array<string, mixed>|string $atts
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'style'       => CurrencySwitcherRenderer::defaultStyle(),
				'show_flag'   => 'yes',
				'show_code'   => 'yes',
				'show_symbol' => 'no',
				'show_name'   => 'no',
				'class'       => '',
			),
			$atts,
			self::TAG
		);

		/** @var CurrencySwitcherRenderer $renderer */
		$renderer = Plugin::instance()->container()->get( 'currency_switcher_renderer' );

		return $renderer->render(
			array(
				'style'       => $atts['style'],
				'show_flag'   => self::toBool( $atts['show_flag'] ),
				'show_code'   => self::toBool( $atts['show_code'] ),
				'show_symbol' => self::toBool( $atts['show_symbol'] ),
				'show_name'   => self::toBool( $atts['show_name'] ),
				'class'       => sanitize_html_class( $atts['class'] ),
			)
		);
	}

	private static function toBool( $value ): bool {
		return in_array( strtolower( (string) $value ), array( 'yes', 'true', '1' ), true );
	}
}
