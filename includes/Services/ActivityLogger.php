<?php
declare( strict_types=1 );

namespace WCMCS\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Who changed what setting and when" — a thin wrapper over
 * LoggerService (the same wcmcs_logs table already used for rate-fetch
 * failures) that always attaches the acting user, at a dedicated
 * 'activity' log level so it's easy to query separately from operational
 * warnings/errors. Every admin screen that saves a setting should call
 * this alongside its actual save.
 */
class ActivityLogger {

	public const LEVEL = 'activity';

	private LoggerService $logger;

	public function __construct( LoggerService $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function record( string $action, array $context = array() ): void {
		$userId = get_current_user_id();
		$user   = $userId ? get_userdata( $userId ) : null;

		$context['user_id']    = $userId;
		$context['user_login'] = $user instanceof \WP_User ? $user->user_login : 'system';

		$this->logger->log( self::LEVEL, $action, $context );
	}
}
