# Hook Reference

Every action and filter this plugin fires, grouped by lifecycle area. All hook names are prefixed `wcmcs_`. Currency codes passed to hooks are always uppercase ISO 4217 (e.g. `USD`).

## Actions

### Plugin lifecycle

#### `wcmcs_loaded`
Fires once, at the end of plugin bootstrap, after every internal service is registered.

```php
do_action( 'wcmcs_loaded', \WCMCS\Core\Plugin $plugin );
```

```php
add_action( 'wcmcs_loaded', function ( $plugin ) {
	// $plugin->container()->get( 'currency_service' ) is now safe to call.
} );
```

#### `wcmcs_register_services`
Fires while the plugin builds its dependency-injection container, after every built-in service is registered — the hook point for registering your own service into the same container (e.g. a custom rate provider).

```php
do_action( 'wcmcs_register_services', \WCMCS\Core\Container $container );
```

```php
add_action( 'wcmcs_register_services', function ( $container ) {
	$container->set( 'my_addon_service', fn () => new My_Addon_Service() );
} );
```

### Currency switching

#### `wcmcs_before_currency_switch`
Fires immediately before a currency switch is persisted — from the switcher widget's AJAX call, a `?currency=` link, or geolocation auto-detection. Not cancellable; use it for side effects that need to see the *previous* currency first.

```php
do_action( 'wcmcs_before_currency_switch', string $code, string $source, ?string $previous );
```

`$source` is one of `SessionService::SOURCE_MANUAL`, `SOURCE_AUTO`, `SOURCE_URL`.

```php
add_action( 'wcmcs_before_currency_switch', function ( $code, $source, $previous ) {
	error_log( "Switching from {$previous} to {$code} (source: {$source})" );
}, 10, 3 );
```

#### `wcmcs_currency_switched`
Fires immediately after a currency switch has been persisted. Same parameters as `wcmcs_before_currency_switch`. This is the plugin's original switch hook and fires for every switch path — including geolocation auto-detection.

```php
do_action( 'wcmcs_currency_switched', string $code, string $source, ?string $previous );
```

```php
add_action( 'wcmcs_currency_switched', function ( $code, $source, $previous ) {
	// Sync the shopper's currency choice to an external CRM, for example.
}, 10, 3 );
```

Note: no hook fires when a shopper "switches" to the currency they already have active — that's a no-op, not a switch.

### Exchange rates

#### `wcmcs_before_rate_fetch`
Fires immediately before the plugin asks its provider chain for a live rate.

```php
do_action( 'wcmcs_before_rate_fetch', string $base, string $target );
```

#### `wcmcs_after_rate_fetch`
Fires after a rate fetch attempt completes, successfully or not.

```php
do_action( 'wcmcs_after_rate_fetch', string $base, string $target, ?float $rate );
```

`$rate` is `null` if every provider failed, or the fetched rate failed validation (out of bounds, too large a swing from the previous rate).

```php
add_action( 'wcmcs_after_rate_fetch', function ( $base, $target, $rate ) {
	if ( null === $rate ) {
		// Alert on a failed fetch.
	}
}, 10, 3 );
```

### Price conversion

#### `wcmcs_before_price_conversion`
Fires before a single price is converted — every product price, shipping cost, coupon amount, or other value this plugin converts passes through here, including values later served from cache.

```php
do_action( 'wcmcs_before_price_conversion', float $amount, string $base, string $active, float $rate );
```

#### `wcmcs_after_price_conversion`
Fires after a price has been converted (or served from cache).

```php
do_action( 'wcmcs_after_price_conversion', string $result, float $amount, string $base, string $active );
```

`$result` is the final converted, rounded price as a numeric string.

### Checkout

#### `wcmcs_before_checkout_currency_lock`
Fires immediately before the plugin locks in an order's currency/rate/base-total metadata at checkout.

```php
do_action( 'wcmcs_before_checkout_currency_lock', int $orderId, string $currency, string $base );
```

#### `wcmcs_after_checkout_currency_lock`
Fires after that metadata has been locked in.

```php
do_action( 'wcmcs_after_checkout_currency_lock', int $orderId, string $currency, string $base, float $rate );
```

`$rate` is `1.0` when the order was placed in the store's base currency.

```php
add_action( 'wcmcs_after_checkout_currency_lock', function ( $orderId, $currency, $base, $rate ) {
	// Forward the locked-in rate to an accounting system.
}, 10, 4 );
```

## Filters

### `wcmcs_supported_currencies`
Filters the store's enabled currency codes — the authoritative list used to build the switcher UI *and* to validate an incoming switch request. Filtering this list is the reliable way to add or remove a currency everywhere the plugin enforces it, not just from the visible dropdown.

```php
apply_filters( 'wcmcs_supported_currencies', string[] $codes ): string[]
```

```php
add_filter( 'wcmcs_supported_currencies', function ( $codes ) {
	return array_diff( $codes, array( 'RUB' ) ); // hide one currency for this deployment
} );
```

### `wcmcs_rate_before_apply`
Filters a freshly fetched rate before it's validated and stored to history.

```php
apply_filters( 'wcmcs_rate_before_apply', float $rate, string $base, string $target ): float
```

### `wcmcs_conversion_rate`
Filters the effective rate immediately before it's applied to one specific price conversion. Distinct from `wcmcs_rate_before_apply`: this only affects this one calculation, not what gets recorded to rate history.

```php
apply_filters( 'wcmcs_conversion_rate', float $rate, string $base, string $active, float $amount ): float
```

```php
add_filter( 'wcmcs_conversion_rate', function ( $rate, $base, $active, $amount ) {
	// Shave a fixed spread off every EUR conversion.
	return 'EUR' === $active ? $rate * 0.995 : $rate;
}, 10, 4 );
```

### `wcmcs_rounded_price`
Filters the final converted, rounded price. Fires on every conversion, including one served from cache.

```php
apply_filters( 'wcmcs_rounded_price', string $result, float $amount, string $active, float $rate ): string
```

### `wcmcs_rate_cache_ttl`
Filters how many seconds a fetched rate stays cached before the next request re-fetches it. Defaults to the configured rate-refresh interval.

```php
apply_filters( 'wcmcs_rate_cache_ttl', int $seconds ): int
```

### `wcmcs_rate_deviation_threshold`
Filters the percentage swing (from the previous rate) that RateValidator will reject a new rate for, per currency.

```php
apply_filters( 'wcmcs_rate_deviation_threshold', float $percent, string $currency ): float
```

### `wcmcs_gateway_currency_support`
Filters a payment gateway's currency-support entry in the compatibility matrix GatewayCurrencyGuard checks before checkout.

```php
apply_filters( 'wcmcs_gateway_currency_support', array $entry, string $gatewayId ): array
```

`$entry` has the shape `array{supported_currencies: string[]|null, notes: string}` (`null` supported-currencies means "supports every enabled currency").

```php
add_filter( 'wcmcs_gateway_currency_support', function ( $entry, $gatewayId ) {
	if ( 'my_custom_gateway' === $gatewayId ) {
		$entry['supported_currencies'] = array( 'USD', 'EUR' );
	}
	return $entry;
}, 10, 2 );
```

### `wcmcs_geolocation_result`
Filters the geolocation result before it's cached and used to suggest or auto-apply a currency.

```php
apply_filters( 'wcmcs_geolocation_result', array $result, string $base ): array
```

`$result` has the shape `array{country: ?string, currency: string, detected: bool}`.

```php
add_filter( 'wcmcs_geolocation_result', function ( $result, $base ) {
	// Force a specific currency for a known corporate office IP range.
	return $result;
}, 10, 2 );
```

### `wcmcs_country_currency_map`
Filters the full country-code-to-currency-code lookup table used by geolocation.

```php
apply_filters( 'wcmcs_country_currency_map', array $map ): array
```

### `wcmcs_language_country_map`
Filters the Accept-Language-to-country fallback table used when IP geolocation isn't available.

```php
apply_filters( 'wcmcs_language_country_map', array $map ): array
```

### `wcmcs_currency`
Filters a single resolved `Currency` object right after lookup.

```php
apply_filters( 'wcmcs_currency', ?\WCMCS\Services\Currency\Currency $currency, string $code ): ?Currency
```

### `wcmcs_currency_data`
Filters the raw currency dataset (name, symbol, decimals, formatting) before it's turned into `Currency` objects — the hook point for adding a currency this plugin doesn't ship with, or overriding one's formatting wholesale.

```php
apply_filters( 'wcmcs_currency_data', array $rawData ): array
```

### `wcmcs_format_currency`
Filters a formatted price string.

```php
apply_filters( 'wcmcs_format_currency', string $formatted, float $amount, \WCMCS\Services\Currency\Currency $currency, bool $withSymbol ): string
```

### `wcmcs_convert_price`
Public extension point for converting an arbitrary number using this plugin's exact rate and rounding rules — see [`WCMCS::convert()`](api-reference.md), which is a thin wrapper around this filter.

```php
apply_filters( 'wcmcs_convert_price', float|int|string $amount, ?string $targetCurrency ): float|int|string
```
