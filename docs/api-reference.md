# Public PHP API

A global `WCMCS` class — no namespace, no `use` statement needed — for other plugins or a theme's `functions.php` to work with currencies without touching this plugin's internals. Every method is static.

Every method checks internally whether the plugin is actually active and safely usable; when it isn't, methods return a sane default (`false`, `null`, an empty array, or the store's raw `woocommerce_currency` option) rather than throwing. You can also check this yourself with `WCMCS::isActive()`.

## `WCMCS::isActive(): bool`

Whether the plugin's services are available right now.

```php
if ( WCMCS::isActive() ) {
	// safe to call any other method below
}
```

## `WCMCS::currentCurrency(): string`

The currency the current visitor is shopping in — their own choice if they've made one, otherwise the store's base currency.

```php
$currency = WCMCS::currentCurrency(); // e.g. "EUR"
```

## `WCMCS::baseCurrency(): string`

The store's base currency (WooCommerce > Settings > General), regardless of what any visitor has selected.

```php
$base = WCMCS::baseCurrency(); // e.g. "USD"
```

## `WCMCS::currencies(): string[]`

The store's currently enabled currency codes.

```php
foreach ( WCMCS::currencies() as $code ) {
	echo $code . "\n";
}
```

## `WCMCS::allCurrencies(): array`

Full details (name, symbol, decimals, formatting) for every enabled currency, keyed by code.

```php
$eur = WCMCS::allCurrencies()['EUR'];
echo $eur['symbol']; // "€"
```

## `WCMCS::convert( float $amount, ?string $toCurrency = null, ?string $fromCurrency = null ): ?float`

Converts an amount, applying this plugin's exact rate, markup, rounding, and decimal-precision rules — the same result a shopper would actually see on a product page. Defaults: `$fromCurrency` is the store's base currency, `$toCurrency` is the current visitor's active currency.

Returns `null` if conversion isn't currently possible (no rate available). Treat `null` as "fall back to the original amount" — never as zero.

```php
$priceInVisitorCurrency = WCMCS::convert( 49.99 );

$priceInEur = WCMCS::convert( 49.99, 'EUR' );

$fromGbpToEur = WCMCS::convert( 49.99, 'EUR', 'GBP' );
```

## `WCMCS::format( float $amount, ?string $currency = null ): string`

Formats an amount using a currency's native symbol, position, and separators. Defaults to the current visitor's active currency.

```php
echo WCMCS::format( 1234.5, 'EUR' ); // "1.234,50 €" (or however that currency is configured)
```

## `WCMCS::setCurrency( string $code ): bool`

Force-sets the current visitor's active currency — persisted exactly like a manual switcher click (user meta if logged in, session/cookie either way), firing the same `wcmcs_before_currency_switch` / `wcmcs_currency_switched` hooks a normal switch does.

Returns `false` without doing anything if `$code` isn't one of the store's currently enabled currencies.

```php
if ( ! WCMCS::setCurrency( 'CAD' ) ) {
	// CAD isn't enabled on this store
}
```

## `WCMCS::historicalRate( string $targetCurrency, string $date, ?string $baseCurrency = null ): ?float`

The exchange rate recorded for `$targetCurrency` (against the store's base currency, or an explicit `$baseCurrency`) on a specific calendar date (`Y-m-d`) — the closing (last-recorded-that-day) rate. Returns `null` if no rate was ever recorded for that pair on that date, or if `$date` isn't a valid `Y-m-d` string.

```php
$rateOnNewYears = WCMCS::historicalRate( 'EUR', '2026-01-01' );
```

## Extending instead of calling

If you need to influence behavior rather than just read data — adding a currency's data, adjusting a rate before it's applied, reacting to a switch — use the [hook reference](hooks-reference.md) instead. The API above is for reading and driving currency state from outside the plugin; hooks are for changing how the plugin itself behaves.
