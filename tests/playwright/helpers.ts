import { expect, type Page } from '@playwright/test';
import path from 'node:path';

export const APP = 'upload-and-convert.php';
export const PASSWORD = process.env.TEST_PASSWORD ?? 'test-password';

// Made by global-setup.ts
export const FIXTURES = path.join(__dirname, '.fixtures');
export const CLIP = path.join(FIXTURES, 'clip.mp4');
export const NOT_A_VIDEO = path.join(FIXTURES, 'not-a-video.mp4');

export async function logIn(page: Page) {
	await page.goto(APP);
	await page.getByLabel('Password').fill(PASSWORD);
	await page.getByRole('button', { name: 'Log in' }).click();
	await expect(page.getByRole('heading', { name: 'Convert a video' })).toBeVisible();
}

/** Tick or untick the output checkboxes so only the ones named are ticked. */
export async function chooseOutputs(page: Page, outputs: { mp4?: boolean; webm?: boolean; ogg?: boolean; stills?: boolean }) {
	await page.getByLabel(/^MP4/).setChecked(!!outputs.mp4);
	await page.getByLabel(/^WebM/).setChecked(!!outputs.webm);
	await page.getByLabel(/^Ogg/).setChecked(!!outputs.ogg);
	await page.getByLabel('Capture stills').setChecked(!!outputs.stills);
}
