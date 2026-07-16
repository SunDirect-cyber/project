import { defineConfig, devices } from '@playwright/test';

/**
 * These tests need a real, running WooCommerce store with this plugin
 * active — set BASE_URL to it (a staging site is fine; never point
 * this at a production store, since the checkout test places a real
 * order). Not runnable in a sandbox with no live site to test against;
 * see docs/testing.md for setup.
 */
export default defineConfig({
	testDir: '.',
	fullyParallel: false, // the checkout flow shares cart/session state across steps within a test
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: 'html',
	use: {
		baseURL: process.env.BASE_URL || 'http://localhost:8080',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
	],
});
