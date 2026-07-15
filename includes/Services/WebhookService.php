<?php
namespace WCMCS\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fires outbound webhooks for external business systems: rates
 * updating, a new currency being enabled, an anomaly being detected.
 * Every delivery is signed (HMAC-SHA256 over the raw JSON body, using
 * that webhook's own secret) via an X-Wcmcs-Signature header — the
 * same pattern Stripe/GitHub/etc. use, so the receiving system can
 * verify a payload genuinely came from this store and wasn't forged or
 * tampered with in transit, rather than trusting the network alone.
 */
class WebhookService {

	public const EVENT_RATE_UPDATED     = 'rate.updated';
	public const EVENT_CURRENCY_ADDED   = 'currency.added';
	public const EVENT_ANOMALY_DETECTED = 'anomaly.detected';

	/**
	 * @return array{id: string, url: string, events: string[], secret: string}[]
	 */
	public static function all(): array {
		return (array) get_option( 'wcmcs_webhooks', array() );
	}

	/**
	 * @param string[] $events
	 */
	public static function register( string $url, array $events ): string {
		$webhooks = self::all();
		$id       = wp_generate_uuid4();

		$webhooks[] = array(
			'id'     => $id,
			'url'    => esc_url_raw( $url ),
			'events' => array_values( array_intersect( $events, self::validEvents() ) ),
			'secret' => wp_generate_password( 32, false ),
		);

		update_option( 'wcmcs_webhooks', $webhooks );

		return $id;
	}

	public static function remove( string $id ): void {
		$webhooks = array_values( array_filter( self::all(), static fn ( $w ) => $w['id'] !== $id ) );

		update_option( 'wcmcs_webhooks', $webhooks );
	}

	/**
	 * @return string[]
	 */
	public static function validEvents(): array {
		return array( self::EVENT_RATE_UPDATED, self::EVENT_CURRENCY_ADDED, self::EVENT_ANOMALY_DETECTED );
	}

	/**
	 * Sends a test ping to one specific webhook regardless of which
	 * events it's subscribed to — used by the admin "Send Test" button.
	 * Unlike trigger()'s fire-and-forget deliveries, this one blocks and
	 * reports the actual HTTP result, since a human is waiting on it.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function sendTest( string $webhookId ): array {
		foreach ( self::all() as $webhook ) {
			if ( $webhook['id'] !== $webhookId ) {
				continue;
			}

			$response = $this->deliver(
				$webhook,
				wp_json_encode(
					array(
						'event'     => 'test',
						'site_url'  => home_url(),
						'timestamp' => gmdate( 'c' ),
						'data'      => array( 'message' => 'This is a test webhook delivery.' ),
					)
				),
				true
			);

			if ( is_wp_error( $response ) ) {
				return array( 'success' => false, 'message' => $response->get_error_message() );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			return array(
				'success' => $status >= 200 && $status < 300,
				'message' => sprintf( 'HTTP %d', $status ),
			);
		}

		return array( 'success' => false, 'message' => __( 'Webhook not found.', 'wc-multicurrency-switcher' ) );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function trigger( string $event, array $data ): void {
		$payload = array(
			'event'     => $event,
			'site_url'  => home_url(),
			'timestamp' => gmdate( 'c' ),
			'data'      => $data,
		);

		$body = wp_json_encode( $payload );

		foreach ( self::all() as $webhook ) {
			if ( ! in_array( $event, $webhook['events'], true ) ) {
				continue;
			}

			$this->deliver( $webhook, $body );
		}
	}

	/**
	 * @param array{id: string, url: string, secret: string} $webhook
	 * @return array|\WP_Error Only meaningful when $blocking is true.
	 */
	private function deliver( array $webhook, string $body, bool $blocking = false ) {
		$signature = hash_hmac( 'sha256', $body, $webhook['secret'] );

		return wp_remote_post(
			$webhook['url'],
			array(
				'timeout'  => 8,
				'headers'  => array(
					'Content-Type'      => 'application/json',
					'X-Wcmcs-Signature' => $signature,
				),
				'body'     => $body,
				// Fire-and-forget for real triggers: a slow/unreachable
				// receiver must never block the request that caused this
				// (a checkout, a cron run) — only the admin's explicit
				// "Send Test" click waits for a real response.
				'blocking' => $blocking,
			)
		);
	}
}
