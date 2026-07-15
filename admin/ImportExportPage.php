<?php
namespace WCMCS\Admin;

use WCMCS\Core\Plugin;
use WCMCS\Core\SettingsPortability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Import / Export" admin screen. Export triggers a direct JSON file
 * download (handled on admin_init, before any HTML output — sending a
 * Content-Disposition header from inside a normal page render is too
 * late, WordPress's admin chrome has already started printing by then).
 * Import validates the uploaded file completely before writing anything
 * (see SettingsPortability::import()) and reports exactly what was
 * rejected rather than guessing or partially applying it.
 */
class ImportExportPage {

	private const EXPORT_ACTION = 'wcmcs_export_settings';
	private const IMPORT_ACTION = 'wcmcs_import_settings';

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_handle_export' ) );
	}

	public static function maybe_handle_export(): void {
		if ( ! isset( $_POST['wcmcs_export_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcmcs_export_nonce'] ) ), self::EXPORT_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wc-multicurrency-switcher' ) );
		}

		$includeApiKeys = ! empty( $_POST['include_api_keys'] );
		$data           = SettingsPortability::export( $includeApiKeys );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wcmcs-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$importResult = null;

		if (
			isset( $_POST['wcmcs_import_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcmcs_import_nonce'] ) ), self::IMPORT_ACTION ) &&
			! empty( $_FILES['wcmcs_import_file']['tmp_name'] )
		) {
			$importResult = self::handle_import( $_FILES['wcmcs_import_file'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		?>
		<div class="wrap wcmcs-import-export">
			<h1><?php esc_html_e( 'Import / Export', 'wc-multicurrency-switcher' ); ?></h1>

			<?php if ( null !== $importResult ) : ?>
				<?php if ( $importResult['success'] ) : ?>
					<div class="notice notice-success"><p>
						<?php
						printf(
							/* translators: %d: number of settings applied */
							esc_html__( 'Import successful. %d settings applied.', 'wc-multicurrency-switcher' ),
							count( $importResult['applied'] )
						);
						?>
					</p></div>
				<?php else : ?>
					<div class="notice notice-error">
						<p><strong><?php esc_html_e( 'Import rejected — no changes were made:', 'wc-multicurrency-switcher' ); ?></strong></p>
						<ul>
							<?php foreach ( $importResult['errors'] as $error ) : ?>
								<li><?php echo esc_html( $error ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Export', 'wc-multicurrency-switcher' ); ?></h2>
			<p><?php esc_html_e( 'Download every setting this plugin stores as a JSON file, for staging-to-production migration or replicating configuration to another site.', 'wc-multicurrency-switcher' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( self::EXPORT_ACTION, 'wcmcs_export_nonce' ); ?>
				<label>
					<input type="checkbox" name="include_api_keys" value="1">
					<?php esc_html_e( 'Include rate provider API keys (leave unchecked unless you specifically need them in the file)', 'wc-multicurrency-switcher' ); ?>
				</label>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Download Export', 'wc-multicurrency-switcher' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Import', 'wc-multicurrency-switcher' ); ?></h2>
			<p><?php esc_html_e( 'Upload a settings export from this plugin. The file is fully validated before anything is changed — an invalid or incompatible file is rejected with no effect.', 'wc-multicurrency-switcher' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::IMPORT_ACTION, 'wcmcs_import_nonce' ); ?>
				<input type="file" name="wcmcs_import_file" accept="application/json,.json" required>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'wc-multicurrency-switcher' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array{tmp_name: string, error: int} $file
	 * @return array{success: bool, errors: string[], applied: array<string, mixed>}
	 */
	private static function handle_import( array $file ): array {
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return array( 'success' => false, 'errors' => array( __( 'File upload failed.', 'wc-multicurrency-switcher' ) ), 'applied' => array() );
		}

		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $contents ) {
			return array( 'success' => false, 'errors' => array( __( 'Could not read the uploaded file.', 'wc-multicurrency-switcher' ) ), 'applied' => array() );
		}

		$data = json_decode( $contents, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array( 'success' => false, 'errors' => array( __( 'File is not valid JSON.', 'wc-multicurrency-switcher' ) ), 'applied' => array() );
		}

		$result = SettingsPortability::import( $data );

		if ( $result['success'] ) {
			/** @var \WCMCS\Services\ActivityLogger $activity */
			$activity = Plugin::instance()->container()->get( 'activity_logger' );
			$activity->record( 'Imported settings', array( 'settings_count' => count( $result['applied'] ) ) );
		}

		return $result;
	}
}
