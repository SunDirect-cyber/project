<?php
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

		$base = rest_url( 'wcmcs/v1' );

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
		</div>
		<?php
	}
}
