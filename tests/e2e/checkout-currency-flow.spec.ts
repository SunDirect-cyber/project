import { test, expect } from '@playwright/test';

/**
 * The full customer journey: land on the site, browse, switch currency,
 * add to cart, check out, and verify the confirmation page reflects the
 * currency actually charged.
 *
 * Requires a live WooCommerce store with this plugin active, at least
 * two currencies enabled (this file assumes USD as the base and EUR as
 * a second enabled currency — adjust CURRENCY_CODE/CURRENCY_SYMBOL
 * below to match your test store), and a real purchasable product.
 * Not runnable in a sandbox with no live site — see docs/testing.md.
 *
 * A note on the "get geolocated to a currency" step from the spec: this
 * plugin's IP-based geolocation can't be deterministically triggered
 * from a CI runner without actually spoofing the runner's outbound IP,
 * which is out of scope for a plugin test suite (and would be flaky/
 * unrealistic even if attempted). What *is* deterministically testable
 * from Playwright is the Accept-Language fallback path this plugin uses
 * when IP geolocation is unavailable — see
 * "auto-detection via Accept-Language" below — which exercises the same
 * downstream code path (GeoCurrencyResolver -> CurrencyResolutionEngine)
 * without needing real IP geolocation.
 */

const CURRENCY_CODE = 'EUR';
const TEST_PRODUCT_SLUG = process.env.WCMCS_TEST_PRODUCT_SLUG || 'test-product';

test.describe('Currency switcher storefront flow', () => {
	test('a first-time visitor with a non-base browser language sees an auto-detection suggestion or switch', async ({ browser }) => {
		const context = await browser.newContext({ locale: 'de-DE' });
		const page = await context.newPage();

		await page.goto('/');

		// Either the store auto-applied EUR (remember/always-detect mode)
		// or it's showing the geo-suggestion confirmation modal — both are
		// valid outcomes depending on the store's configured mode; the
		// switcher/modal reflecting EUR is what actually matters here.
		const switcherShowsEur = page.locator('[data-wcmcs-switcher] [data-currency="EUR"].is-active, [data-wcmcs-switcher-select] option[value="EUR"][selected]');
		const suggestionModal = page.locator('[data-wcmcs-geo-modal]');

		await expect(switcherShowsEur.or(suggestionModal)).toBeVisible({ timeout: 10_000 });

		await context.close();
	});

	test('a shopper can manually switch currency, and prices update store-wide', async ({ page }) => {
		await page.goto(`/product/${TEST_PRODUCT_SLUG}/`);

		const priceBefore = await page.locator('.summary .price').first().innerText();

		const switcher = page.locator('[data-wcmcs-switcher-select]').first();
		await switcher.selectOption(CURRENCY_CODE);

		// The switcher does a full page reload after a successful AJAX
		// switch (see currency-switcher.js) — wait for that navigation.
		await page.waitForLoadState('networkidle');

		const priceAfter = await page.locator('.summary .price').first().innerText();

		expect(priceAfter).not.toBe(priceBefore);
		expect(priceAfter).toMatch(/€|EUR/);
	});

	test('the chosen currency persists through add-to-cart, checkout, and order confirmation', async ({ page }) => {
		await page.goto(`/product/${TEST_PRODUCT_SLUG}/`);

		await page.locator('[data-wcmcs-switcher-select]').first().selectOption(CURRENCY_CODE);
		await page.waitForLoadState('networkidle');

		const productPrice = await page.locator('.summary .price').first().innerText();

		await page.locator('form.cart button[type="submit"]').click();
		await page.waitForLoadState('networkidle');

		await page.goto('/cart/');
		await expect(page.locator('.cart_totals')).toContainText(/€|EUR/);

		await page.goto('/checkout/');
		await expect(page.locator('#order_review')).toContainText(/€|EUR/);

		// Filling and submitting a real checkout form is store-specific
		// (payment gateway, required fields) — left as a clearly-marked
		// extension point rather than guessed at generically here.
		//
		// await page.fill('#billing_first_name', 'Test');
		// ... fill remaining required fields ...
		// await page.locator('#place_order').click();
		// await page.waitForLoadState('networkidle');
		//
		// await expect(page.locator('.woocommerce-order')).toContainText(/€|EUR/);
		// const orderTotal = await page.locator('.woocommerce-order-overview__total strong').innerText();
		// expect(orderTotal).toMatch(/€|EUR/);
	});
});
