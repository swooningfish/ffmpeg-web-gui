import { expect, test } from '@playwright/test';
import { chooseOutputs, CLIP, logIn, NOT_A_VIDEO } from './helpers';

test.describe('Converting a video', () => {
	test.beforeEach(async ({ page }) => {
		await logIn(page);
	});

	test('the form is grouped into labelled steps', async ({ page }) => {
		for (const step of ['1. Choose a video', '2. Choose what to make', '3. Video', '4. Audio']) {
			await expect(page.getByRole('group', { name: step })).toBeVisible();
		}
		// Every control can be found by its label
		for (const label of ['Video file', 'Maximum size', 'Video bitrate', 'Frame rate', 'Deinterlace', 'Enable audio', 'Sampling rate', 'Stereo', 'Mono', 'Capture stills']) {
			await expect(page.getByLabel(label, { exact: true })).toBeVisible();
		}
		await expect(page.getByLabel('Bitrate', { exact: true })).toBeVisible();
		await expect(page.getByRole('group', { name: 'Audio channels' })).toBeVisible();
	});

	test('the upload limit and accepted types are shown', async ({ page }) => {
		await expect(page.getByLabel('Video file')).toHaveAccessibleDescription(/Up to [\d.]+ MB\. Accepted: MP4, .*MOV/);
	});

	test('audio settings fade out while audio is off', async ({ page }) => {
		const settings = page.locator('.audio-settings');
		await expect(settings).toHaveCSS('opacity', '1');
		await page.getByLabel('Enable audio').uncheck();
		await expect(settings).toHaveCSS('opacity', '0.45');
	});

	test('the browser asks for a file before uploading', async ({ page }) => {
		await page.getByRole('button', { name: 'Upload and convert' }).click();
		expect(await page.getByLabel('Video file').evaluate((input: HTMLInputElement) => input.validity.valueMissing)).toBe(true);
		await expect(page.getByRole('alert')).toHaveCount(0);
	});

	test('converting with no outputs ticked is refused', async ({ page }) => {
		await page.getByLabel('Video file').setInputFiles(CLIP);
		await chooseOutputs(page, {});
		await page.getByRole('button', { name: 'Upload and convert' }).click();
		await expect(page.getByRole('alert')).toHaveText('Please choose at least one output format.');
	});

	test('a file that is not a video is refused', async ({ page }) => {
		await page.getByLabel('Video file').setInputFiles(NOT_A_VIDEO);
		await chooseOutputs(page, { mp4: true });
		await page.getByRole('button', { name: 'Upload and convert' }).click();
		await expect(page.getByRole('alert')).toHaveText('That file is not a supported video.');
	});

	test('convert a video, then use the results', async ({ page }) => {
		await page.getByLabel('Video file').setInputFiles(CLIP);
		await chooseOutputs(page, { mp4: true, webm: true, stills: true });
		await page.getByLabel('Maximum size').selectOption('320x240');
		await page.getByLabel('Mono').check();
		await page.getByRole('button', { name: 'Upload and convert' }).click();

		// Results
		await expect(page.getByRole('heading', { name: 'Converted clip.mp4' })).toBeVisible({ timeout: 150_000 });
		await expect(page.getByRole('alert')).toHaveCount(0);
		const result = page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'Converted clip.mp4' }) });
		await expect(result.getByRole('link', { name: 'Download MP4' })).toBeVisible();
		await expect(result.getByRole('link', { name: 'Download WebM' })).toBeVisible();
		await expect(result.getByRole('link', { name: 'Download Ogg' })).toHaveCount(0);

		const stills = result.getByRole('img', { name: /^Still \d of \d$/ });
		await expect(stills).toHaveCount(5); // one a second from 0:01 of the 7 second clip
		for (const still of await stills.all()) {
			await expect.poll(() => still.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth)).toBeGreaterThan(0);
		}

		// The form keeps the choices that were just used
		await expect(page.getByLabel('Maximum size')).toHaveValue('320x240');
		await expect(page.getByLabel('Mono')).toBeChecked();
		await expect(page.getByLabel(/^Ogg/)).not.toBeChecked();

		// Downloads are real video files
		const mp4 = await result.getByRole('link', { name: 'Download MP4' }).getAttribute('href');
		const response = await page.request.get(mp4!);
		expect(response.status()).toBe(200);
		expect(response.headers()['content-type']).toContain('video/mp4');
		expect((await response.body()).length).toBeGreaterThan(1000);

		// The player page loads and the browser can play one of its sources
		await result.getByRole('link', { name: 'Open the HTML5 player' }).click();
		const video = page.locator('video');
		await expect(video).toHaveAttribute('poster', /_still_\d\.png$/);
		await expect.poll(() => video.evaluate((v: HTMLVideoElement) => v.readyState), { timeout: 20_000 }).toBeGreaterThanOrEqual(1);
		expect(await video.evaluate((v: HTMLVideoElement) => v.videoWidth)).toBeLessThanOrEqual(320);
	});
});
