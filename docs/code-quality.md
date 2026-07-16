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

## Static analysis, strict typing, and remaining PHPDoc coverage

Not yet done as of this document's last update — see the plugin's own task tracking for status. When tackled, this section will cover PHPStan/Psalm configuration and findings, the `declare(strict_types=1)` rollout (and which files were deliberately left out, if any, with reasoning), and the completion of full PHPDoc coverage.
