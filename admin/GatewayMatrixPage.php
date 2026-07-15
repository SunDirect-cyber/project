<?php
namespace WCMCS\Admin;

use WCMCS\Services\Gateway\GatewayCurrencyMatrix;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Payment Gateways" admin screen: shows, for every registered
 * WooCommerce payment gateway, which of the store's enabled currencies
 * it's considered to support — the built-in matrix (Stripe, PayPal,
 * WooCommerce's own offline methods) plus whatever the store owner has
 * overridden — and lets them correct it. This is what
 * GatewayCurrencyGuard actually reads at checkout, so getting a gateway
 * marked correctly here directly controls whether shoppers see the
 * base-currency fallback notice for it.
 */
class GatewayMatrixPage {

	private const NONCE_ACTION = 'wcmcs_save_gateway_matrix';

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		if ( isset( $_POST['wcmcs_gateway_matrix_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcmcs_gateway_matrix_nonce'] ) ), self::NONCE_ACTION ) ) {
			self::save( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'wc-multicurrency-switcher' ) . '</p></div>';
		}

		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$enabled  = array_map( 'strtoupper', (array) get_option( 'wcmcs_enabled_currencies', array() ) );

		?>
		<div class="wrap wcmcs-gateway-matrix">
			<h1><?php esc_html_e( 'Payment Gateway Currency Support', 'wc-multicurrency-switcher' ); ?></h1>
			<p><?php esc_html_e( 'Every gateway always supports your store\'s base currency. This controls what happens for your other enabled currencies: if a gateway isn\'t marked as supporting a currency, shoppers who select that currency and pick this gateway are automatically charged in your base currency instead, with an on-screen notice.', 'wc-multicurrency-switcher' ); ?></p>

			<?php if ( empty( $gateways ) ) : ?>
				<p><?php esc_html_e( 'No payment gateways are registered.', 'wc-multicurrency-switcher' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( empty( $enabled ) ) : ?>
				<p><?php esc_html_e( 'No currencies are enabled yet — nothing to configure until you enable at least one.', 'wc-multicurrency-switcher' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, 'wcmcs_gateway_matrix_nonce' ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Gateway', 'wc-multicurrency-switcher' ); ?></th>
							<th><?php esc_html_e( 'Supports all currencies', 'wc-multicurrency-switcher' ); ?></th>
							<?php foreach ( $enabled as $code ) : ?>
								<th><?php echo esc_html( $code ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $gateways as $gateway ) : ?>
							<?php
							$entry      = GatewayCurrencyMatrix::entryFor( $gateway->id );
							$isWildcard = GatewayCurrencyMatrix::WILDCARD === $entry;
							$supported  = is_array( $entry ) ? array_map( 'strtoupper', $entry ) : array();
							?>
							<tr>
								<td><?php echo esc_html( $gateway->get_method_title() ?: $gateway->id ); ?> <code><?php echo esc_html( $gateway->id ); ?></code></td>
								<td>
									<input type="checkbox"
										name="wcmcs_gateway[<?php echo esc_attr( $gateway->id ); ?>][wildcard]"
										value="1"
										class="wcmcs-gateway-wildcard"
										<?php checked( $isWildcard ); ?>>
								</td>
								<?php foreach ( $enabled as $code ) : ?>
									<td>
										<input type="checkbox"
											name="wcmcs_gateway[<?php echo esc_attr( $gateway->id ); ?>][currencies][]"
											value="<?php echo esc_attr( $code ); ?>"
											<?php checked( $isWildcard || in_array( $code, $supported, true ) ); ?>
											<?php disabled( $isWildcard ); ?>>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
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
		$submitted = isset( $post['wcmcs_gateway'] ) && is_array( $post['wcmcs_gateway'] ) ? $post['wcmcs_gateway'] : array();
		$overrides = array();

		foreach ( $submitted as $gatewayId => $config ) {
			$gatewayId = sanitize_key( $gatewayId );

			if ( ! empty( $config['wildcard'] ) ) {
				$overrides[ $gatewayId ] = GatewayCurrencyMatrix::WILDCARD;
				continue;
			}

			$currencies = isset( $config['currencies'] ) && is_array( $config['currencies'] )
				? array_map( static fn ( $c ) => strtoupper( sanitize_text_field( $c ) ), $config['currencies'] )
				: array();

			$overrides[ $gatewayId ] = array_values( array_unique( $currencies ) );
		}

		update_option( 'wcmcs_gateway_currency_overrides', $overrides );
	}
}
