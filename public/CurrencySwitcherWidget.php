<?php
namespace WCMCS\Frontend;

use WCMCS\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classic WordPress widget — drop the switcher into any registered
 * sidebar/widget area (including most themes' footer widget area,
 * covering the "footer" placement option without any extra code).
 */
class CurrencySwitcherWidget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'wcmcs_currency_switcher',
			__( 'Currency Switcher', 'wc-multicurrency-switcher' ),
			array( 'description' => __( 'Lets shoppers switch the store currency.', 'wc-multicurrency-switcher' ) )
		);
	}

	public static function register(): void {
		add_action( 'widgets_init', static fn () => register_widget( self::class ) );
	}

	/**
	 * @param array<string, mixed> $args
	 * @param array<string, mixed> $instance
	 */
	public function widget( $args, $instance ) {
		/** @var CurrencySwitcherRenderer $renderer */
		$renderer = Plugin::instance()->container()->get( 'currency_switcher_renderer' );

		$html = $renderer->render(
			array(
				'style'       => $instance['style'] ?? CurrencySwitcherRenderer::defaultStyle(),
				'show_flag'   => ! empty( $instance['show_flag'] ),
				'show_code'   => ! empty( $instance['show_code'] ) || ! isset( $instance['show_code'] ),
				'show_symbol' => ! empty( $instance['show_symbol'] ),
				'show_name'   => ! empty( $instance['show_name'] ),
			)
		);

		if ( '' === $html ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param array<string, mixed> $instance
	 */
	public function form( $instance ) {
		$title       = $instance['title'] ?? '';
		$style       = $instance['style'] ?? CurrencySwitcherRenderer::defaultStyle();
		$show_flag   = ! empty( $instance['show_flag'] );
		$show_code   = ! isset( $instance['show_code'] ) || ! empty( $instance['show_code'] );
		$show_symbol = ! empty( $instance['show_symbol'] );
		$show_name   = ! empty( $instance['show_name'] );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'wc-multicurrency-switcher' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"><?php esc_html_e( 'Style:', 'wc-multicurrency-switcher' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<option value="dropdown" <?php selected( $style, 'dropdown' ); ?>><?php esc_html_e( 'Dropdown', 'wc-multicurrency-switcher' ); ?></option>
				<option value="button_list" <?php selected( $style, 'button_list' ); ?>><?php esc_html_e( 'Button list', 'wc-multicurrency-switcher' ); ?></option>
				<option value="flag_grid" <?php selected( $style, 'flag_grid' ); ?>><?php esc_html_e( 'Flag grid', 'wc-multicurrency-switcher' ); ?></option>
			</select>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_flag' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_flag' ) ); ?>" <?php checked( $show_flag ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_flag' ) ); ?>"><?php esc_html_e( 'Show flag', 'wc-multicurrency-switcher' ); ?></label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_code' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_code' ) ); ?>" <?php checked( $show_code ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_code' ) ); ?>"><?php esc_html_e( 'Show currency code', 'wc-multicurrency-switcher' ); ?></label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_symbol' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_symbol' ) ); ?>" <?php checked( $show_symbol ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_symbol' ) ); ?>"><?php esc_html_e( 'Show currency symbol', 'wc-multicurrency-switcher' ); ?></label>
		</p>
		<p>
			<input type="checkbox" id="<?php echo esc_attr( $this->get_field_id( 'show_name' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'show_name' ) ); ?>" <?php checked( $show_name ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_name' ) ); ?>"><?php esc_html_e( 'Show currency name', 'wc-multicurrency-switcher' ); ?></label>
		</p>
		<?php
	}

	/**
	 * @param array<string, mixed> $new_instance
	 * @param array<string, mixed> $old_instance
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'       => sanitize_text_field( $new_instance['title'] ?? '' ),
			'style'       => in_array( $new_instance['style'] ?? '', array( 'dropdown', 'button_list', 'flag_grid' ), true ) ? $new_instance['style'] : 'dropdown',
			'show_flag'   => ! empty( $new_instance['show_flag'] ),
			'show_code'   => ! empty( $new_instance['show_code'] ),
			'show_symbol' => ! empty( $new_instance['show_symbol'] ),
			'show_name'   => ! empty( $new_instance['show_name'] ),
		);
	}
}
