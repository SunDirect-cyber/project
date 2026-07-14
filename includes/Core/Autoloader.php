<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal PSR-4 autoloader used only when Composer's autoloader is not
 * present (e.g. the plugin was installed as a plain zip without running
 * `composer install`). Maps the WCMCS\ namespace onto /includes.
 */
class Autoloader {

	/**
	 * Namespace prefix to base directory map.
	 *
	 * @var array<string, string>
	 */
	private array $prefixes;

	public function __construct() {
		$this->prefixes = array(
			'WCMCS\\Core\\'     => WCMCS_PATH . 'includes/Core/',
			'WCMCS\\Services\\' => WCMCS_PATH . 'includes/Services/',
			'WCMCS\\Admin\\'    => WCMCS_PATH . 'admin/',
			'WCMCS\\Frontend\\' => WCMCS_PATH . 'public/',
		);
	}

	public function register(): void {
		spl_autoload_register( array( $this, 'load' ) );
	}

	public function load( string $class ): void {
		foreach ( $this->prefixes as $prefix => $base_dir ) {
			if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
				continue;
			}

			$relative_class = substr( $class, strlen( $prefix ) );
			$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require $file;
			}

			return;
		}
	}
}
