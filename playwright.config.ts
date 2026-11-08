import { defineConfig, devices } from '@playwright/test';

/*
	Browser tests for the user journeys (log in, convert, results, layout).
	They need the Docker test server running:

		docker compose up -d --build
		npm ci
		npx playwright install chromium
		npm run test:ui

	The worker container converts the uploads, so it must be running too.
*/
export default defineConfig({
	testDir: './tests/playwright',
	globalSetup: './tests/playwright/global-setup.ts',
	// The app only runs one conversion at a time, so the tests can't run in parallel
	fullyParallel: false,
	workers: 1,
	timeout: 180_000,
	expect: { timeout: 10_000 },
	forbidOnly: !!process.env.CI,
	reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list'], ['html', { open: 'on-failure' }]],
	use: {
		baseURL: process.env.BASE_URL ?? 'http://localhost:8080/',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
	],
});
