<?php
namespace WCMCS\Admin;

use WCMCS\Core\Plugin;
use WCMCS\Services\CurrencyResolutionEngine;
use WCMCS\Services\Geo\GeoCountryDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Display & Behavior" admin screen: the switcher style picker (with a
 * genuinely live preview — all three variants are pre-rendered and
 * swapped with plain CSS/JS show-hide, no AJAX round trip needed), the
 * auto-detection mode, the remember-vs-always-detect toggle, and the
 * confirmation modal toggle. Every one of these is a setting that
 * already had real backend logic from earlier work — this screen is
 * what finally exposes them.
 */
class DisplaySettingsPage {

	private const NONCE_ACTION = 'wcmcs_save_display_settings';

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		if ( isset( $_POST['wcmcs_display_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcmcs_display_settings_nonce'] ) ), self::NONCE_ACTION ) ) {
			self::save( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'wc-multicurrency-switcher' ) . '</p></div>';
		}

		wp_enqueue_style( 'wcmcs-admin', WCMCS_URL . 'assets/css/admin.css', array(), WCMCS_VERSION );
		wp_enqueue_style( 'wcmcs-currency-switcher', WCMCS_URL . 'assets/css/currency-switcher.css', array(), WCMCS_VERSION );
		wp_enqueue_script( 'wcmcs-display-settings', WCMCS_URL . 'assets/js/display-settings.js', array(), WCMCS_VERSION, true );

		$currentStyle      = get_option( 'wcmcs_default_switcher_style', 'dropdown' );
		$detectionMode     = GeoCountryDetector::mode();
		$rememberMode      = CurrencyResolutionEngine::mode();
		$confirmationOn    = ! empty( get_option( 'wcmcs_currency_switch_confirmation', false ) );

		/** @var \WCMCS\Frontend\CurrencySwitcherRenderer $renderer */
		$renderer = Plugin::instance()->container()->get( 'currency_switcher_renderer' );

		$previews = array(
			'dropdown'    => $renderer->render( array( 'style' => 'dropdown' ) ),
			'button_list' => $renderer->render( array( 'style' => 'button_list' ) ),
			'flag_grid'   => $renderer->render( array( 'style' => 'flag_grid' ) ),
		);

		// The preview needs the flag sprite inline on this admin page too
		// — FrontendHooks only prints it on the public-facing site
		// (wp_footer), never in wp-admin.
		$spritePath = WCMCS_PATH . 'assets/images/flags-sprite.svg';
		if ( file_exists( $spritePath ) ) {
			echo file_get_contents( $spritePath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		?>
		<div class="wrap wcmcs-display-settings">
			<h1><?php esc_html_e( 'Display & Behavior', 'wc-multicurrency-switcher' ); ?></h1>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, 'wcmcs_display_settings_nonce' ); ?>

				<h2><?php esc_html_e( 'Switcher Style', 'wc-multicurrency-switcher' ); ?></h2>
				<p><?php esc_html_e( 'The default style for new widgets, the shortcode, the block, and the floating widget when they don\'t specify their own.', 'wc-multicurrency-switcher' ); ?></p>

				<?php if ( empty( array_filter( $previews ) ) ) : ?>
					<p><em><?php esc_html_e( 'Enable at least 2 currencies to see a live preview.', 'wc-multicurrency-switcher' ); ?></em></p>
				<?php endif; ?>

				<div class="wcmcs-style-picker">
					<?php foreach ( array( 'dropdown' => __( 'Dropdown', 'wc-multicurrency-switcher' ), 'button_list' => __( 'Button list', 'wc-multicurrency-switcher' ), 'flag_grid' => __( 'Flag grid', 'wc-multicurrency-switcher' ) ) as $value => $label ) : ?>
						<label class="wcmcs-style-picker__option">
							<input type="radio" name="default_switcher_style" value="<?php echo esc_attr( $value ); ?>" data-wcmcs-style-radio <?php checked( $currentStyle, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="wcmcs-style-preview">
					<?php foreach ( $previews as $style => $html ) : ?>
						<div class="wcmcs-style-preview__panel" data-wcmcs-style-preview="<?php echo esc_attr( $style ); ?>" <?php echo $style !== $currentStyle ? 'hidden' : ''; ?>>
							<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endforeach; ?>
				</div>

				<h2><?php esc_html_e( 'Auto-Detection', 'wc-multicurrency-switcher' ); ?></h2>
				<p>
					<label><input type="radio" name="auto_detection_mode" value="both" <?php checked( $detectionMode, 'both' ); ?>> <?php esc_html_e( 'Geolocation, then browser language as a fallback (recommended)', 'wc-multicurrency-switcher' ); ?></label><br>
					<label><input type="radio" name="auto_detection_mode" value="geolocation" <?php checked( $detectionMode, 'geolocation' ); ?>> <?php esc_html_e( 'Geolocation only', 'wc-multicurrency-switcher' ); ?></label><br>
					<label><input type="radio" name="auto_detection_mode" value="language" <?php checked( $detectionMode, 'language' ); ?>> <?php esc_html_e( 'Browser language only', 'wc-multicurrency-switcher' ); ?></label><br>
					<label><input type="radio" name="auto_detection_mode" value="off" <?php checked( $detectionMode, 'off' ); ?>> <?php esc_html_e( 'Off — always start on the base currency', 'wc-multicurrency-switcher' ); ?></label>
				</p>

				<h2><?php esc_html_e( 'Remembering a Currency', 'wc-multicurrency-switcher' ); ?></h2>
				<p>
					<label><input type="radio" name="remember_mode" value="remember" <?php checked( $rememberMode, 'remember' ); ?>> <?php esc_html_e( 'Remember the auto-detected currency (don\'t re-detect on later visits)', 'wc-multicurrency-switcher' ); ?></label><br>
					<label><input type="radio" name="remember_mode" value="always_detect" <?php checked( $rememberMode, 'always_detect' ); ?>> <?php esc_html_e( 'Always re-detect on every visit', 'wc-multicurrency-switcher' ); ?></label>
				</p>
				<p class="description"><?php esc_html_e( 'A manually chosen currency always wins over auto-detection either way.', 'wc-multicurrency-switcher' ); ?></p>

				<h2><?php esc_html_e( 'Switch Confirmation', 'wc-multicurrency-switcher' ); ?></h2>
				<p>
					<label>
						<input type="checkbox" name="switch_confirmation" value="1" <?php checked( $confirmationOn ); ?>>
						<?php esc_html_e( 'Ask before switching to an auto-detected currency, instead of applying it silently', 'wc-multicurrency-switcher' ); ?>
					</label>
				</p>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'wc-multicurrency-switcher' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function save( array $post ): void {
		$style = $post['default_switcher_style'] ?? 'dropdown';
		if ( in_array( $style, array( 'dropdown', 'button_list', 'flag_grid' ), true ) ) {
			update_option( 'wcmcs_default_switcher_style', $style );
		}

		$detectionMode = $post['auto_detection_mode'] ?? GeoCountryDetector::MODE_BOTH;
		if ( in_array( $detectionMode, array( GeoCountryDetector::MODE_BOTH, GeoCountryDetector::MODE_GEOLOCATION, GeoCountryDetector::MODE_LANGUAGE, GeoCountryDetector::MODE_OFF ), true ) ) {
			update_option( 'wcmcs_auto_detection_mode', $detectionMode );
		}

		$rememberMode = $post['remember_mode'] ?? CurrencyResolutionEngine::MODE_REMEMBER;
		if ( in_array( $rememberMode, array( CurrencyResolutionEngine::MODE_REMEMBER, CurrencyResolutionEngine::MODE_ALWAYS_DETECT ), true ) ) {
			update_option( 'wcmcs_currency_remember_mode', $rememberMode );
		}

		update_option( 'wcmcs_currency_switch_confirmation', ! empty( $post['switch_confirmation'] ) );
	}
}
