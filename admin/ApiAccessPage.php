<?php
declare( strict_types=1 );

namespace WCMCS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "API Access" admin screen: not a settings form (there's nothing to
 * configure — REST API auth uses WordPress's own Application Passwords,
 * managed from each user's own profile screen) but documentation, since
 * an undiscoverable REST API isn't really usable by the external BI
 * tools this was built for.
 */
class ApiAccessPage {

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style( 'wcmcs-admin', WCMCS_URL . 'assets/css/admin.css', array(), WCMCS_VERSION );
		wp_enqueue_script( 'wcmcs-api-access', WCMCS_URL . 'assets/js/api-access.js', array(), WCMCS_VERSION, true );
		wp_localize_script(
			'wcmcs-api-access',
			'wcmcsApiAccess',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( ApiAccessAjaxController::NONCE ),
				'i18n'    => array(
					'saved'      => __( 'Saved.', 'wc-multicurrency-switcher' ),
					'saveFailed' => __( 'Save failed.', 'wc-multicurrency-switcher' ),
				),
			)
		);

		$base        = rest_url( 'wcmcs/v1' );
		$storeBase   = rest_url( 'wcmcs/v1/store' );
		$allowedOrigins = (string) get_option( 'wcmcs_headless_allowed_origins', '' );

		?>
		<div class="wrap wcmcs-api-access">
			<h1><?php esc_html_e( 'API Access', 'wc-multicurrency-switcher' ); ?></h1>

			<p>
				<?php
				printf(
					/* translators: %s: link to profile page */
					wp_kses_post( __( 'Authenticate with a WordPress <a href="%s">Application Password</a> (created from your own profile page) — the same mechanism the entire WordPress REST API already uses, sent as HTTP Basic Auth. No separate API key system to manage.', 'wc-multicurrency-switcher' ) ),
					esc_url( admin_url( 'profile.php#application-passwords-section' ) )
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Base URL', 'wc-multicurrency-switcher' ); ?></h2>
			<p><code><?php echo esc_html( $base ); ?></code></p>

			<h2><?php esc_html_e( 'Endpoints', 'wc-multicurrency-switcher' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Endpoint', 'wc-multicurrency-switcher' ); ?></th><th><?php esc_html_e( 'Description', 'wc-multicurrency-switcher' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>GET /currencies</code></td><td><?php esc_html_e( 'Every enabled currency and its formatting.', 'wc-multicurrency-switcher' ); ?></td></tr>
					<tr><td><code>GET /rates</code></td><td><?php esc_html_e( 'Current live rate from the base currency to each enabled currency.', 'wc-multicurrency-switcher' ); ?></td></tr>
					<tr><td><code>GET /rates/history?target=EUR&limit=30</code></td><td><?php esc_html_e( 'Historical rates for one currency pair.', 'wc-multicurrency-switcher' ); ?></td></tr>
					<tr><td><code>GET /analytics/revenue?from=2026-01-01&to=2026-01-31</code></td><td><?php esc_html_e( 'Revenue-by-currency totals for a date range.', 'wc-multicurrency-switcher' ); ?></td></tr>
					<tr><td><code>GET /analytics/impact?from=2026-01-01&to=2026-01-31</code></td><td><?php esc_html_e( 'The currency conversion impact (gain/loss) report for a date range.', 'wc-multicurrency-switcher' ); ?></td></tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Example', 'wc-multicurrency-switcher' ); ?></h2>
			<pre>curl -u "username:application-password" \
  "<?php echo esc_html( $base ); ?>/rates"</pre>

			<hr>

			<h2><?php esc_html_e( 'Headless Storefront API', 'wc-multicurrency-switcher' ); ?></h2>
			<p>
				<?php esc_html_e( 'Separate, public, unauthenticated endpoints for a decoupled front end (Next.js, React, etc.) with no WordPress login of its own — no Application Password needed. Only read-only data and price conversion; nothing here can change store settings.', 'wc-multicurrency-switcher' ); ?>
			</p>

			<h3><?php esc_html_e( 'Base URL', 'wc-multicurrency-switcher' ); ?></h3>
			<p><code><?php echo esc_html( $storeBase ); ?></code></p>

			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Endpoint', 'wc-multicurrency-switcher' ); ?></th><th><?php esc_html_e( 'Description', 'wc-multicurrency-switcher' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>GET /currencies</code></td><td><?php esc_html_e( 'Base currency and every enabled currency with its formatting.', 'wc-multicurrency-switcher' ); ?></td></tr>
					<tr><td><code>GET /rates</code></td><td><?php esc_html_e( 'Effective (locked/marked-up) rate from the base currency to each enabled currency.', 'wc-multicurrency-switcher' ); ?></td></tr>
					<tr><td><code>GET /convert?amount=49.99&amp;to=EUR</code></td><td><?php esc_html_e( 'Converts an amount, applying this plugin\'s exact rate, markup, and rounding rules. Add &from=CODE to convert from a currency other than the base.', 'wc-multicurrency-switcher' ); ?></td></tr>
				</tbody>
			</table>

			<p>
				<?php esc_html_e( 'There is no server-side "set the visitor\'s currency" endpoint — a headless front end doesn\'t share cookies/sessions with WordPress the way a normal page does. Keep the shopper\'s chosen currency in your front end\'s own state (e.g. localStorage) and pass it as ?to= on each request instead.', 'wc-multicurrency-switcher' ); ?>
			</p>

			<h3><?php esc_html_e( 'Example', 'wc-multicurrency-switcher' ); ?></h3>
			<pre>curl "<?php echo esc_html( $storeBase ); ?>/convert?amount=49.99&to=EUR"</pre>

			<h3><?php esc_html_e( 'Allowed origins (CORS)', 'wc-multicurrency-switcher' ); ?></h3>
			<p>
				<?php esc_html_e( 'One origin per line (scheme + host, e.g. https://shop.example.com) — only these origins will receive Access-Control-Allow-Origin for the headless endpoints above. Leave empty to disable cross-origin access entirely (same-origin requests still work).', 'wc-multicurrency-switcher' ); ?>
			</p>
			<p>
				<textarea id="wcmcs-headless-origins" rows="4" class="large-text code" placeholder="https://shop.example.com"><?php echo esc_textarea( $allowedOrigins ); ?></textarea>
			</p>
			<p>
				<button type="button" class="button button-primary" id="wcmcs-save-headless-origins"><?php esc_html_e( 'Save', 'wc-multicurrency-switcher' ); ?></button>
				<span class="wcmcs-status" id="wcmcs-headless-origins-status"></span>
			</p>
		</div>
		<?php
	}
}
