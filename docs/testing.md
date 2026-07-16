# Testing

Three separate tiers, each answering a different question, each requiring different infrastructure to run. Don't run the wrong tier expecting it to catch what only another tier can.

| Tier | Answers | Requires | Status in this repo |
|---|---|---|---|
| Unit (`tests/Unit`) | Is this class's logic correct in isolation? | PHP + Composer only | **83 tests, genuinely passing** |
| Integration (`tests/Integration`) | Does this plugin behave correctly against real WordPress + WooCommerce? | A real WordPress test install + MySQL | Scaffolded, not run here |
| E2E (`tests/e2e`) | Does a real customer's browser journey work end to end? | A live/staging WooCommerce store | Scaffolded, not run here |

The honest reason two of the three tiers say "scaffolded, not run here": this plugin was built and tested in a sandboxed environment with no WordPress install, no MySQL, no network access to download WordPress core, and no browser-reachable staging site. Every test file in `tests/Integration` and `tests/e2e` is written to the real frameworks' actual conventions and is ready to run as-is once that infrastructure exists (a CI job, or a developer's local environment) — but claiming they were run here would be dishonest. The unit tests, by contrast, need nothing but PHP itself, so they really were run, repeatedly, while building this plugin — see the test files for tests that caught and fixed real bugs during development (e.g. `CompetitorImporterTest` catching a currency-validation bypass, `PriceConversionServiceTest`'s cache-invalidation tests).

## Unit tests

```
composer install
composer test
```

Runs in well under a second. Every WordPress function these tests need is stubbed in `tests/Unit/WpStubs.php` — plain global arrays backing `get_option`/`apply_filters`/transients/etc., reset before every test by `WcmcsUnitTestCase::setUp()`. No mocking framework beyond PHPUnit's own, no database, no HTTP.

Covers the core logic explicitly called out as needing isolated coverage:

- **Currency conversion math** — `PriceConversionServiceTest` (rate composition: locked rate vs. live rate + markup, rounding, cache behavior).
- **Rounding engine** — `RoundingRuleTest` (nearest/charm/none modes, the non-positive-step fallback, charm rounding's own boundary behavior).
- **Rate validation** — `RateValidatorTest` (bounds checking, deviation-threshold rejection, the per-currency filter).
- **Conflict resolution logic** — `CurrencyResolutionEngineTest` (the precedence rule between manual/url/auto currency sources).
- **Edge cases from the plugin's Edge Case & Failure Handling work** — `CurrencyDataEdgeCasesTest` (zero-decimal JPY/KRW, three-decimal BHD/KWD, micro/luxury-amount rounding with no float drift), `VendorDashboardDetectorTest` and `CachingPluginCompatTest` (the vendor-dashboard price-corruption bug and the cache-poisoning scenario, both found and fixed during that work).

### Adding a new unit test

Extend `WCMCS\Tests\Unit\WcmcsUnitTestCase`, not `PHPUnit\Framework\TestCase` directly — it guarantees the stub state (options/filters/transients) is reset before your test runs. If your class calls a WordPress function `WpStubs.php` doesn't have yet, add the smallest faithful stub there, not a one-off mock local to your test file (see `is_admin`/`has_action`/`nocache_headers` for examples of the right size). If a class needs a *different* value for the same global constant across tests (this came up for `EncryptionService`'s `AUTH_KEY`/`AUTH_SALT`), use `@runInSeparateProcess` rather than trying to redefine a PHP constant — see the comment in `EncryptionServiceTest` for a case where even that didn't fully work and what we did instead (accepted a documented, deliberate gap rather than distort the test).

## Integration tests

Real `WP_UnitTestCase`-based tests against a live WordPress + WooCommerce + MySQL environment — the only tier that can verify `get_price()` actually comes back converted after WooCommerce's real filter pipeline runs, that a variable product's price range is genuinely correct, that a variation's cached transient doesn't leak one currency's price into another's.

Setup (standard WordPress plugin testing scaffold — nothing bespoke to this plugin):

```
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
composer test:integration
```

`bin/install-wp-tests.sh` is the standard script `wp scaffold plugin-tests` generates; if this repo doesn't have one yet, grab the canonical version from the WordPress plugin-tests scaffold and place it at `bin/install-wp-tests.sh`. It downloads WordPress core and the WP PHPUnit test framework into `/tmp/wordpress-tests-lib` (or wherever `WP_TESTS_DIR` points) and creates the test database. WooCommerce itself must also be present in that WordPress install's `wp-content/plugins` for `tests/Integration/bootstrap.php` to load it.

## End-to-end (E2E) tests

Playwright, against a real running store (staging — never production, since the checkout test places a real order):

```
cd tests/e2e
npm install
BASE_URL=https://staging.example.com WCMCS_TEST_PRODUCT_SLUG=your-test-product npm test
```

`checkout-currency-flow.spec.ts` walks the journey the spec asks for: land on the site with a non-base browser language (exercising auto-detection), browse to a product, manually switch currency, add to cart, and verify the chosen currency is reflected through checkout. The actual "place order and verify the confirmation page" step is left as a marked extension point rather than guessed at generically, since checkout form fields and required steps are specific to each store's configured payment gateway and shipping setup.

A note on testing geolocation specifically: this plugin's IP-based detection can't be deterministically triggered from a CI runner without spoofing the runner's outbound IP, which is out of scope for a plugin's own test suite. The auto-detection test instead exercises the Accept-Language fallback path (`GeoCountryDetector`), which is deterministic from Playwright via browser context locale — and goes through the exact same downstream resolution code (`GeoCurrencyResolver` → `CurrencyResolutionEngine`) that real IP geolocation would.
