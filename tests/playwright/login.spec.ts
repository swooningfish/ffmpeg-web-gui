import { expect, test } from '@playwright/test';
import { APP, logIn } from './helpers';

test.describe('Logging in and out', () => {
	test('the folder URL shows the login page', async ({ page }) => {
		await page.goto('./');
		await expect(page).toHaveTitle(/ffmpeg web gui/);
		await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible();
		await expect(page.getByLabel('Password')).toBeFocused();
		await expect(page.getByRole('button', { name: 'Log out' })).toHaveCount(0);
	});

	test('a wrong password shows an error', async ({ page }) => {
		await page.goto(APP);
		await page.getByLabel('Password').fill('not-the-password');
		await page.getByRole('button', { name: 'Log in' }).click();
		await expect(page.getByRole('alert')).toHaveText('Incorrect password.');
		await expect(page.getByLabel('Password')).toHaveAttribute('aria-invalid', 'true');
		await expect(page.getByRole('heading', { name: 'Convert a video' })).toHaveCount(0);
	});

	test('the browser asks for a password before sending the form', async ({ page }) => {
		await page.goto(APP);
		await page.getByRole('button', { name: 'Log in' }).click();
		expect(await page.getByLabel('Password').evaluate((input: HTMLInputElement) => input.validity.valueMissing)).toBe(true);
		await expect(page.getByRole('alert')).toHaveCount(0);
	});

	test('log in, then log out', async ({ page }) => {
		await logIn(page);
		await page.getByRole('button', { name: 'Log out' }).click();
		await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible();

		// The session really ended: reloading still shows the login page
		await page.goto(APP);
		await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible();
	});
});
