import { expect, test, type Page } from '@playwright/test';
import { APP, chooseOutputs, CLIP, logIn, NOT_A_VIDEO, PASSWORD } from './helpers';

const JOB_URL = /[?&]job=([0-9a-f]{16})$/;

/** Wait on a conversion's page until the worker has finished and the results show. */
async function waitForResults(page: Page) {
	await expect(page.getByRole('heading', { name: 'Your video is ready' })).toBeVisible({ timeout: 150_000 });
}

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

	test('audio settings switch off while audio is off', async ({ page }) => {
		const settings = page.locator('.audio-settings');
		await expect(settings).toHaveCSS('opacity', '1');
		await page.getByLabel('Enable audio').uncheck();
		await expect(settings).toHaveCSS('opacity', '0.45');
		await expect(page.getByLabel('Sampling rate')).toBeDisabled();
		await expect(page.getByLabel('Mono')).toBeDisabled();
		await page.getByLabel('Enable audio').check();
		await expect(page.getByLabel('Sampling rate')).toBeEnabled();
	});

	test('the browser asks for a file before uploading', async ({ page }) => {
		await page.getByRole('button', { name: 'Upload and convert' }).click();
		expect(await page.getByLabel('Video file').evaluate((input: HTMLInputElement) => input.validity.valueMissing)).toBe(true);
		await expect(page.getByRole('alert')).toHaveCount(0);
	});

	test('a file over the upload limit is refused before uploading', async ({ page }) => {
		let uploaded = false;
		page.on('request', (request) => {
			if (request.method() === 'POST') {
				uploaded = true;
			}
		});
		// Pretend the limit is 1 KB so the test clip is too big
		await page.getByLabel('Video file').evaluate((input) => input.setAttribute('data-max-bytes', '1024'));
		await page.getByLabel('Video file').setInputFiles(CLIP);
		await page.getByRole('button', { name: 'Upload and convert' }).click();
		expect(await page.getByLabel('Video file').evaluate((input: HTMLInputElement) => input.validationMessage)).toMatch(/the limit is 1\.0 KB/);
		expect(uploaded).toBe(false);
	});

	test('converting with no outputs ticked is refused', async ({ page }) => {
		await page.getByLabel('Video file').setInputFiles(CLIP);
		await chooseOutputs(page, {});
		await page.getByRole('button', { name: 'Upload and convert' }).click();
		await expect(page.getByRole('alert')).toHaveText('Please choose at least one output format.');
		await expect(page.getByRole('button', { name: 'Upload and convert' })).toBeEnabled();
		await expect(page).not.toHaveURL(JOB_URL);
	});

	test('a file that is not a video is refused', async ({ page }) => {
		await page.getByLabel('Video file').setInputFiles(NOT_A_VIDEO);
		await chooseOutputs(page, { mp4: true });
		await page.getByRole('button', { name: 'Upload and convert' }).click();
		await expect(page.getByRole('alert')).toHaveText('That file is not a supported video.');
	});

	test('convert a video, follow its progress, then use the results', async ({ page }) => {
		await page.getByLabel('Video file').setInputFiles(CLIP);
		await chooseOutputs(page, { mp4: true, webm: true, stills: true });
		await page.getByLabel('Maximum size').selectOption('320x240');
		await page.getByLabel('Mono').check();
		await page.getByRole('button', { name: 'Upload and convert' }).click();

		// The upload opens the conversion's own page, which follows the worker's progress
		await expect(page).toHaveURL(JOB_URL);
		const jobId = page.url().match(JOB_URL)![1];
		await expect(page.getByText('clip.mp4 ·')).toBeVisible();
		await waitForResults(page);

		// Results
		await expect(page.getByRole('alert')).toHaveCount(0);
		const result = page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'Converted clip.mp4' }) });
		await expect(result.getByRole('button', { name: /^Download MP4 \([\d.]+ (KB|MB)\)$/ })).toBeVisible();
		await expect(result.getByRole('button', { name: /^Download WebM/ })).toBeVisible();
		await expect(result.getByRole('button', { name: /^Download Ogg/ })).toHaveCount(0);

		const stills = result.getByRole('img', { name: /^Still \d of \d$/ });
		await expect(stills).toHaveCount(5); // one a second from 0:01 of the 7 second clip
		for (const still of await stills.all()) {
			await expect.poll(() => still.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth)).toBeGreaterThan(0);
		}

		// Downloads are real video files
		const mp4 = await result.getByRole('button', { name: /^Download MP4/ }).getAttribute('href');
		const response = await page.request.get(mp4!);
		expect(response.status()).toBe(200);
		expect(response.headers()['content-type']).toContain('video/mp4');
		expect((await response.body()).length).toBeGreaterThan(1000);

		// The player page loads and the browser can play one of its sources
		await result.getByRole('button', { name: 'Open the HTML5 player' }).click();
		const video = page.locator('video');
		await expect(video).toHaveAttribute('poster', /_still_\d\.png$/);
		await expect.poll(() => video.evaluate((v: HTMLVideoElement) => v.readyState), { timeout: 20_000 }).toBeGreaterThanOrEqual(1);
		expect(await video.evaluate((v: HTMLVideoElement) => v.videoWidth)).toBeLessThanOrEqual(320);

		// The conversion is listed on the form page, and can be deleted
		await page.goto(APP);
		const row = page.getByRole('row').filter({ has: page.locator(`a[href="?job=${jobId}"]`) });
		await expect(row.getByText('Ready')).toBeVisible();
		page.once('dialog', (dialog) => dialog.accept());
		await row.getByRole('button', { name: 'Delete clip.mp4' }).click();
		await expect(page.locator(`a[href="?job=${jobId}"]`)).toHaveCount(0);
		await page.goto(`${APP}?job=${jobId}`);
		await expect(page.getByRole('heading', { name: 'Conversion not found' })).toBeVisible();
	});

	test('cancelling the delete confirmation keeps the conversion', async ({ page }) => {
		await page.getByLabel('Video file').setInputFiles(CLIP);
		await chooseOutputs(page, { mp4: true });
		await page.getByLabel('Maximum size').selectOption('320x240');
		await page.getByRole('button', { name: 'Upload and convert' }).click();
		await expect(page).toHaveURL(JOB_URL);
		const jobId = page.url().match(JOB_URL)![1];
		await waitForResults(page);

		await page.goto(APP);
		const link = page.locator(`a[href="?job=${jobId}"]`);
		page.once('dialog', (dialog) => dialog.dismiss());
		await page.getByRole('row').filter({ has: link }).getByRole('button', { name: /^Delete/ }).click();
		await expect(link).toHaveCount(1);
	});
});

test.describe('Without JavaScript', () => {
	test.use({ javaScriptEnabled: false });

	test('uploading still works, and the conversion page refreshes itself until it is done', async ({ page }) => {
		await page.goto(APP);
		await page.getByLabel('Password').fill(PASSWORD);
		await page.getByRole('button', { name: 'Log in' }).click();
		await page.getByLabel('Video file').setInputFiles(CLIP);
		await chooseOutputs(page, { mp4: true });
		await page.getByLabel('Maximum size').selectOption('320x240');
		await page.getByRole('button', { name: 'Upload and convert' }).click();
		await expect(page).toHaveURL(JOB_URL);
		await waitForResults(page);
		await expect(page.getByRole('button', { name: /^Download MP4/ })).toBeVisible();
	});

	test('a refused upload shows its error on the form', async ({ page }) => {
		await page.goto(APP);
		await page.getByLabel('Password').fill(PASSWORD);
		await page.getByRole('button', { name: 'Log in' }).click();
		await page.getByLabel('Video file').setInputFiles(NOT_A_VIDEO);
		await chooseOutputs(page, { mp4: true });
		await page.getByRole('button', { name: 'Upload and convert' }).click();
		await expect(page.getByRole('alert')).toHaveText('That file is not a supported video.');
	});
});
