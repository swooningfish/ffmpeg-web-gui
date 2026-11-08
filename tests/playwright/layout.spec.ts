import { expect, test, type Page } from '@playwright/test';
import { APP, logIn } from './helpers';

/** Collect console errors and page errors, which include Content-Security-Policy violations. */
function watchForErrors(page: Page) {
	const errors: string[] = [];
	page.on('console', (message) => {
		if (message.type() === 'error') {
			errors.push(message.text());
		}
	});
	page.on('pageerror', (error) => errors.push(error.message));
	return errors;
}

async function hasHorizontalScroll(page: Page) {
	return page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
}

/** Relative brightness (0 to 255) of the page background. */
async function backgroundBrightness(page: Page) {
	return page.evaluate(() => {
		const [r, g, b] = getComputedStyle(document.documentElement).backgroundColor.match(/\d+/g)!.map(Number);
		return 0.2126 * r + 0.7152 * g + 0.0722 * b;
	});
}

test.describe('Layout and styling', () => {
	test('the stylesheets load with no errors or CSP violations', async ({ page }) => {
		const errors = watchForErrors(page);
		await logIn(page);
		// Pico.css is applied: the submit button is styled, not the browser default
		await expect(page.getByRole('button', { name: 'Upload and convert' })).toHaveCSS('border-radius', /^[1-9]/);
		await expect(page.locator('fieldset.card').first()).toHaveCSS('border-top-style', 'solid');
		expect(errors).toEqual([]);
	});

	test.describe('on a phone', () => {
		test.use({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });

		test('the login page fits without scrolling sideways', async ({ page }) => {
			await page.goto(APP);
			expect(await hasHorizontalScroll(page)).toBe(false);
		});

		test('the convert form is one column and fits without scrolling sideways', async ({ page }) => {
			await logIn(page);
			expect(await hasHorizontalScroll(page)).toBe(false);
			const video = await page.getByRole('group', { name: '3. Video' }).boundingBox();
			const audio = await page.getByRole('group', { name: '4. Audio' }).boundingBox();
			expect(audio!.y).toBeGreaterThan(video!.y + video!.height - 1);
		});
	});

	test('on a wide screen the video and audio settings sit side by side', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 });
		await logIn(page);
		const video = await page.getByRole('group', { name: '3. Video' }).boundingBox();
		const audio = await page.getByRole('group', { name: '4. Audio' }).boundingBox();
		expect(Math.abs(audio!.y - video!.y)).toBeLessThan(2);
		expect(audio!.x).toBeGreaterThan(video!.x + video!.width - 1);
	});

	test('follows the light and dark colour scheme', async ({ page }) => {
		await page.emulateMedia({ colorScheme: 'light' });
		await page.goto(APP);
		expect(await backgroundBrightness(page)).toBeGreaterThan(200);

		await page.emulateMedia({ colorScheme: 'dark' });
		expect(await backgroundBrightness(page)).toBeLessThan(60);
	});

	test('keyboard users can reach the form in order', async ({ page }) => {
		await logIn(page);
		await page.getByLabel('Video file').focus();
		const order: string[] = [];
		for (let i = 0; i < 6; i++) {
			await page.keyboard.press('Tab');
			order.push(await page.evaluate(() => document.activeElement?.id ?? ''));
		}
		expect(order).toEqual(['encoding_x264', 'encoding_webm', 'encoding_ogv', 'encoding_stills', 'video_size', 'video_bitrate']);
	});
});
