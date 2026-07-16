<?php
namespace WCMCS\Cli;

use WCMCS\Core\SettingsPortability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export/import settings from the command line — the same
 * SettingsPortability logic the admin Import/Export screen uses, so a
 * deploy script gets the exact same validation guarantees a human
 * clicking "Import" gets.
 *
 * Registered as `wp wcmcs settings <subcommand>`.
 */
class SettingsCommand {

	/**
	 * Exports settings to a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to write the export to.
	 *
	 * [--include-api-keys]
	 * : Include rate provider API keys in the export.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcmcs settings export ./wcmcs-settings.json
	 */
	public function export( array $args, array $assoc_args ): void {
		$file = $args[0] ?? '';

		if ( '' === $file ) {
			\WP_CLI::error( 'A file path is required.' );
			return;
		}

		$data    = SettingsPortability::export( ! empty( $assoc_args['include-api-keys'] ) );
		$written = file_put_contents( $file, wp_json_encode( $data, JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI context: runs as the system user with direct filesystem access, not a web request; WP_Filesystem's credential-prompt flow doesn't apply here.

		if ( false === $written ) {
			\WP_CLI::error( "Could not write to {$file}." );
			return;
		}

		\WP_CLI::success( "Exported settings to {$file}." );
	}

	/**
	 * Imports settings from a JSON file. Rejects the whole file, with no
	 * changes applied, if any part of it fails validation.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a previously exported settings file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcmcs settings import ./wcmcs-settings.json
	 */
	public function import( array $args ): void {
		$file = $args[0] ?? '';

		if ( '' === $file || ! file_exists( $file ) ) {
			\WP_CLI::error( "File not found: {$file}" );
			return;
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data     = json_decode( $contents, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			\WP_CLI::error( 'File is not valid JSON.' );
			return;
		}

		$result = SettingsPortability::import( $data );

		if ( ! $result['success'] ) {
			\WP_CLI::error( "Import rejected:\n - " . implode( "\n - ", $result['errors'] ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Imported %d settings.', count( $result['applied'] ) ) );
	}
}
