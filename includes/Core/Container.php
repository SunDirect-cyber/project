<?php
namespace WCMCS\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tiny dependency injection container.
 *
 * Services are registered as factory closures and resolved lazily, so a
 * service (e.g. RateService) is only ever instantiated once, on first use,
 * and every other service that depends on it receives the same instance.
 */
class Container {

	/** @var array<string, \Closure> */
	private array $factories = array();

	/** @var array<string, mixed> */
	private array $instances = array();

	/**
	 * Register a factory for a service. The factory receives the container
	 * itself, so services can resolve their own dependencies.
	 */
	public function set( string $id, \Closure $factory ): void {
		$this->factories[ $id ] = $factory;
	}

	/**
	 * Resolve a service by id, creating and caching it on first access.
	 */
	public function get( string $id ) {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( sprintf( 'No service registered for "%s".', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal DI error, a programmer bug never rendered to a browser.
		}

		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->instances[ $id ];
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}
}
