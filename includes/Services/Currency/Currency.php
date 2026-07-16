<?php
declare( strict_types=1 );

namespace WCMCS\Services\Currency;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object describing one ISO 4217 currency and how it's
 * natively formatted. "Immutable" here means: every property is set once,
 * in the constructor, and never touched again — there are no setters, so
 * a Currency instance can be passed around and cached without ever
 * worrying about something else mutating it underneath you. (The project
 * targets PHP 7.4+, which has no `readonly` keyword, so immutability is
 * enforced by convention — private properties, constructor-only writes,
 * getters only — rather than by the language.)
 */
final class Currency {

	public const SYMBOL_BEFORE = 'before';
	public const SYMBOL_AFTER  = 'after';

	private string $code;
	private string $name;
	private string $symbol;
	private int $decimals;
	private string $symbolPosition;
	private string $thousandSeparator;
	private string $decimalSeparator;

	public function __construct(
		string $code,
		string $name,
		string $symbol,
		int $decimals,
		string $symbolPosition,
		string $thousandSeparator,
		string $decimalSeparator
	) {
		if ( 3 !== strlen( $code ) ) {
			throw new \InvalidArgumentException( sprintf( 'Currency code must be a 3-letter ISO 4217 code, got "%s".', $code ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal validation error, a programmer bug never rendered to a browser.
		}

		if ( ! in_array( $symbolPosition, array( self::SYMBOL_BEFORE, self::SYMBOL_AFTER ), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid symbol position "%s".', $symbolPosition ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal validation error, a programmer bug never rendered to a browser.
		}

		if ( $decimals < 0 ) {
			throw new \InvalidArgumentException( 'Decimals cannot be negative.' );
		}

		$this->code              = strtoupper( $code );
		$this->name              = $name;
		$this->symbol            = $symbol;
		$this->decimals          = $decimals;
		$this->symbolPosition    = $symbolPosition;
		$this->thousandSeparator = $thousandSeparator;
		$this->decimalSeparator  = $decimalSeparator;
	}

	public function code(): string {
		return $this->code;
	}

	public function name(): string {
		return $this->name;
	}

	public function symbol(): string {
		return $this->symbol;
	}

	public function decimals(): int {
		return $this->decimals;
	}

	public function symbolPosition(): string {
		return $this->symbolPosition;
	}

	public function isSymbolBefore(): bool {
		return self::SYMBOL_BEFORE === $this->symbolPosition;
	}

	public function thousandSeparator(): string {
		return $this->thousandSeparator;
	}

	public function decimalSeparator(): string {
		return $this->decimalSeparator;
	}

	/**
	 * Value objects compare by value, not by identity — two Currency
	 * instances built from the same code represent the same currency.
	 */
	public function equals( Currency $other ): bool {
		return $this->code === $other->code;
	}

	/**
	 * @return array{code: string, name: string, symbol: string, decimals: int, symbol_position: string, thousand_separator: string, decimal_separator: string}
	 */
	public function toArray(): array {
		return array(
			'code'               => $this->code,
			'name'               => $this->name,
			'symbol'             => $this->symbol,
			'decimals'           => $this->decimals,
			'symbol_position'    => $this->symbolPosition,
			'thousand_separator' => $this->thousandSeparator,
			'decimal_separator'  => $this->decimalSeparator,
		);
	}
}
