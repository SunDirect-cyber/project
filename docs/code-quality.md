# Code Quality Standards

## WordPress Coding Standards (WPCS)

```
composer install
vendor/bin/phpcs
vendor/bin/phpcbf   # auto-fixes what it safely can
```

`phpcs.xml.dist` runs `WordPress-Extra` + `WordPress-Docs` against the whole plugin, with three narrow, explicitly-documented exclusions — read the comments in `phpcs.xml.dist` for the full reasoning, summarized here:

1. **`Generic.Arrays.DisallowShortArraySyntax`** — this plugin uses `[]`-free, WPCS-legal short array syntax (`array()`... no, the reverse: it avoids the *legacy* `array()` sniff fighting modern syntax). Cosmetic only.
2. **`WordPress.Files.FileName`** — this is a PSR-4-autoloaded plugin (see `composer.json`); every file is named to match its class exactly, which PSR-4 *requires*. WordPress's classic `class-currency-service.php` naming convention would break autoloading outright, not just a style preference.
3. **`WordPress.NamingConventions.ValidVariableName`** / **`ValidFunctionName.MethodNameInvalid`** — this codebase uses camelCase consistently throughout (a legitimate, common style for modern OOP PHP), not WordPress core's historical procedural snake_case. Renaming ~900 identifiers across 100+ files to satisfy a style this project never chose would be pure mechanical churn with real regression risk (a rename typo is a bug) for zero functional benefit.

These three are architectural/style decisions, not gaps — a plugin has to pick one consistent style, and this one picked PSR-4/OOP/camelCase deliberately, from the first line of code.

### What's actually fixed vs. what remains

Every finding in a security- or correctness-relevant category was individually investigated and either fixed or suppressed with a specific, reviewed reason — never blanket-ignored:

- **SQL preparation** (`WordPress.DB.PreparedSQL.*`, `PreparedSQLPlaceholders.*`): every flagged line was checked against the actual query. All were false positives from phpcs's static analysis being unable to see that an interpolated `{$table}`/`{$this->table()}` is always a hardcoded table name, or that an `array_merge()` argument to `$wpdb->prepare()` produces the right placeholder count — both confirmed safe during the earlier security audit. Along the way, this found and fixed several existing `// phpcs:ignore` comments that named the *wrong* sniff (e.g. `PreparedSQL.NotPrepared` where the actual violation was `PreparedSQL.InterpolatedNotPrepared`), meaning they'd never actually been suppressing anything — phpcs was never run against this codebase before now, so nobody had verified those comments were correct.
- **Output escaping** (`EscapeOutput.*`): all five remaining findings were internal exception messages (`InvalidArgumentException`/`RuntimeException` for programmer errors, never rendered to a browser) or a helper method (`flagOrBadge()`) that already escapes its own output internally but whose return value phpcs can't trace through an `echo $this->method()` call.
- **i18n**: two *genuine* bugs found and fixed, not suppressed — `Cron::register_schedules()` was calling `__( $config['label'], ... )` with a **variable**, which WordPress's translation-extraction tooling can never actually find or add to a `.pot` file (the `__()` call was doing nothing). Rewrote as a literal `__()` call per known slug. A second spot (`NotificationService`) was missing a `translators:` comment on a placeholder string — added.
- **Discouraged functions**: `base64_encode`/`decode` in `EncryptionService` (encoding binary ciphertext, not code obfuscation), `curl_multi_*` in the load-testing CLI tool (the entire point of that command is genuinely concurrent HTTP connections, which `wp_remote_*()` cannot do), and `file_put_contents` in a WP-CLI export command (runs as the system user, not a web request) — all confirmed legitimate and documented inline.
- **482 mechanical formatting violations** (array alignment, spacing) were auto-fixed via `phpcbf` and verified safe: every file re-linted with `php -l`, and the full 83-test unit suite re-run and passing afterward.

**What's left** (~1070 errors, mostly `Squiz.Commenting.FunctionComment.Missing`/`MissingParamTag` and `Generic.Commenting.DocComment.MissingShort`): this is real, honest remaining work — full PHPDoc coverage on every method — not something silently swept under a config exclusion. It's a large, mechanical documentation task rather than a bug-fixing one; tracked as follow-up rather than rushed.

## PHP 8 strict typing

`declare( strict_types=1 );` was added to every first-party PHP file (124 files — every class under `includes/`, `public/`, `admin/`, the test suite, the main plugin file, and `uninstall.php`), placed as the first statement after `<?php`, before `namespace` where one exists. Type hints and return types were already used consistently throughout the codebase from the start (`string $code`, `?float`, `: void`, etc.) — this rollout is what actually makes those declarations enforced (caught at the call site as a `TypeError`) rather than PHP silently coercing a wrong-typed argument.

This was checked for regressions two ways, since there's no live WordPress environment in this sandbox to exercise every admin screen and AJAX endpoint against:

- Every file re-linted with `php -l` (syntax only) and the full 83-test unit suite re-run — all still passing.
- A targeted audit for the highest-risk pattern (an unsanitized `$_GET`/`$_POST` value passed directly into a strictly-typed parameter, where PHP would previously have silently coerced a wrong type instead of throwing): every instance found either already goes through an explicit cast (`(int)`, `(string)`) or into a helper method whose parameter is intentionally untyped (`$value` with no type hint) specifically because it accepts raw superglobal input.

What this can't fully rule out without a live install: an admin screen or AJAX handler path the unit suite doesn't exercise, where some value flows into a typed parameter without an explicit cast and happens to arrive as an unexpected type at runtime. That's a real residual risk inherent to not having WordPress/WooCommerce available to test against directly — see `tests/Integration` for the tier that would actually exercise these paths once that infrastructure exists.

## Static analysis (PHPStan) — blocked in this environment

Attempted via Composer (`phpstan/phpstan`, plus `szepeviktor/phpstan-wordpress` and `php-stubs/woocommerce-stubs` for WordPress/WooCommerce-aware analysis) but could not be installed: every package other than `phpstan/phpstan` itself consistently failed to download through this sandbox's network proxy with a `Could not authenticate against github.com` error across 15+ retries and several different approaches (removing packages one at a time, a fresh lock file, plain `composer update`). This is distinct from the `getcomposer.org` self-check failure the proxy status endpoint logs as an explicit policy 403 — the GitHub failures aren't logged as policy denials, they look like a transient/intermittent issue with this specific proxy's handling of GitHub's zipball-then-git-clone-fallback path, but retrying didn't resolve it.

`phpunit/phpunit` and `squizlabs/php_codesniffer` (+ WPCS) both installed successfully earlier via the same mechanism after a handful of retries, so this isn't a blanket "GitHub is unreachable" situation — just not reliably reproducible for this specific set of packages in this session. Worth simply retrying `composer require --dev phpstan/phpstan szepeviktor/phpstan-wordpress php-stubs/woocommerce-stubs` in an environment with normal network access, or whenever this sandbox's proxy state changes.
