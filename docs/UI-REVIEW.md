# UI review and improvement plan

Branch: `ui` · Reviewed: 8 Nov 2026 · Scope: `upload-and-convert.php` (login, form, results) and the generated `*_player.html` page.

This is a proposal only. Nothing in the app has changed yet. The [open questions](#open-questions) at the end decide which phases go ahead.

---

## What the UI looks like today

One PHP page renders three states: "set a password" notice, login form, and the convert form with results above it. Styling is about 40 lines of inline CSS (floated 250px labels, 120px inputs, `.clear` divs). There is no JavaScript. The `ui` branch was cut from `main` at `4c16b5d`.

## Findings

### 1. No feedback during a long upload and conversion (biggest problem)
- A 100 MB upload plus a multi-minute ffmpeg run gives no feedback apart from the browser's own spinner. The page only says "can take several minutes".
- The submit button stays active, so a second click starts a second request, which then fails with "Another conversion is running".
- There is no upload progress, even though the browser could report it.
- An oversized file is only rejected **after** the whole upload finishes. The 100 MB limit isn't shown anywhere on the form.

### 2. Form order and grouping
- The order is file, then video settings, then audio settings, and only then **which formats to make**. The main decision comes last.
- The "HTML5 Video Compatible" heading groups output formats together with "Capture stills", which is not a format.
- The Stereo and Mono radios sit on two separate rows with no "Audio channels" label, so they read like two unrelated options.
- The audio settings stay active when "Enable audio" is unticked.
- There are no `<fieldset>`/`<legend>` groups, so screen readers get one flat list of 14 controls.

### 3. Options are dated
- The default size is 560x304. The largest choices are 800x600 and 1600 kbit/s. There is no 720p or 1080p.
- The frame rate is always forced (default 30 fps), so 25 fps PAL or 24 fps film sources get resampled. There is no "same as source" option.
- Audio sample rates of 11025 and 22050 Hz are rarely useful today, and WebM/Opus ignores the setting anyway (it is fixed at 48 kHz).
- Most users don't need bitrate numbers at all. Quality presets such as Small, Balanced and High, with the raw settings under an "Advanced" section, would be easier.

### 4. Results panel
- The results show as green text with `MP4 | WebM` links, 100px still thumbnails and a link to the player page.
- No video plays on the page, file sizes aren't shown, and the links have no copy buttons.
- When something fails, the message says "See the ffmpeg log in the logs folder", but users can't reach that folder from the web.
- Results disappear on reload and there is no list of earlier conversions. The README asks admins to "delete old folders regularly", but the UI has no way to do that.

### 5. Layout, responsiveness and look
- The fixed 250px label column breaks on phones. At 375px wide, labels and controls squeeze together and the file input shows "N…n" instead of the file name.
- Only browser default controls are used, with no spacing, colour, or focus styling and no dark mode.
- The name appears three ways: "FFmpeg GUI" in `<title>`, "ffmpeg web gui" in `<h1>`, and "ffmpeg web gui" in the README.
- The login page is a single floated row with no context.
- A `<!-- Todo … -->` comment about iPod/PSP formats ships in the page HTML.

### 6. Accessibility
- Error messages have no `role="alert"`, so screen readers don't announce them.
- Checkbox labels come before their boxes. The common pattern is box first, then label, and that also gives a larger click target.
- All stills have the same `alt="Still"`.
- Focus outlines are the browser defaults, with nothing styled to make them clearer.

### 7. Code structure (affects how easy UI work is)
- The markup, helper functions (`renderSelect`, `renderCheckbox`) and request handling are all in one file. The helpers are declared halfway through the file.
- The CSS is inline, which is why the CSP needs `style-src 'unsafe-inline'`. Moving it to a file lets that be removed, which also improves security.
- The CSP blocks inline scripts, so any JavaScript has to go in a separate `.js` file. That fits a no-build approach.
- The e2e tests (`tests/e2e/run.sh`) grep the page for strings such as `ffmpeg web gui`, `name="csrf" value="…"` and `converted/<id>/`. A redesign must keep those, or update the tests in the same change.

### 8. Player page
- The generated `*_player.html` is a bare `<video>` tag plus links. It could reuse the app's stylesheet, show the stills and show file sizes.
- The README screenshots are from v0.1 and need replacing after any redesign.

---

## Proposed steps

Each phase can ship as its own PR, and each one works without the next.

| Phase | What | Size | Changes behaviour? |
|---|---|---|---|
| **1. Restructure and restyle** | Move CSS to `assets/app.css` and drop `'unsafe-inline'` from the CSP. Split the markup into small templates. Use `fieldset`/`legend` groups ordered File → Outputs → Video → Audio. Add a responsive one-column layout on phones, consistent naming, `role="alert"` errors, the upload limit shown on the form, a tidier login page, dark mode, and remove the HTML Todo comment. | M | No (same form fields and POST) |
| **2. Progressive-enhancement JS** | Add `assets/app.js` with no framework and no build step. It disables the submit button on submit, shows a real upload progress bar via `XMLHttpRequest`, then a "Converting…" state. It checks file size and type before uploading, and greys out the audio settings when audio is off. Without JS the form works exactly as it does today. | S–M | No |
| **3. Better results** | A results card with an inline `<video>` preview, per-file sizes, a larger stills gallery, copy-link buttons and the tail of the ffmpeg error shown on failure. Restyle the generated player page to match. | M | Slightly (error detail shown to the user) |
| **4. Background jobs and real progress** *(optional, bigger)* | Start ffmpeg outside the web request and track it with a job status file and `-progress` output. The page polls for a real percentage and ETA, and there is a "Recent conversions" list with delete buttons. This also removes the long-request / 504 timeout problem from the README. | L | Yes: architecture and deployment |
| **5. Modern presets** *(optional)* | Quality presets (Small / Balanced / High), 720p and 1080p sizes, "same as source" frame rate, and CRF-based quality, with the current controls under "Advanced". | M | Yes: encoding defaults and output |
| **7. YouTube Shorts output** *(requested 8 Nov 2026)* | A new "YouTube Short (9:16 MP4)" output: 1080x1920 H.264/AAC, vertical. A landscape source is cropped to 9:16 (`crop=ih*9/16:ih:X:0,scale=1080:1920`), and the user picks the crop position. Optional "fit with blurred background" instead of cropping, plus start/end trim, because Shorts are capped at 3 minutes. With JS, a crop box can be dragged over a preview frame. Without JS, a Left / Centre / Right choice. | M | Yes: new output format |
| **6. Docs** | New README screenshots, and update the e2e/unit tests for anything renamed. | S | No |

Suggested order: 1 → 2 → 3, then decide on 4 and 5. Phase 2 already covers most of the "no feedback" problem: upload progress is real, and conversion shows a clear busy state. Only Phase 4 can give a true conversion percentage.

---

## Decisions so far (8 Nov 2026)

| Question | Answer |
|---|---|
| Scope | Phase 4, background jobs (only option ticked; see note) |
| Visual style | Vendored Pico.css (committed to the repo, no CDN) |
| JavaScript | Yes, a small vanilla JS file as progressive enhancement |
| Encoding defaults | Modernise them (this is Phase 5) |

**Note:** "Pico.css" and "modernise defaults" only make sense with Phases 1 and 5. The working assumption is therefore **Phases 1–5**, built in this order: 1 (restyle with Pico) → 4 (background jobs, with its own progress UI, so Phase 2's in-request busy state is reduced to the upload progress bar and the pre-upload checks) → 3 (results and history) → 5 (presets) → 6 (docs). Confirm this before work starts.

**Still to decide before Phase 4:**
- **Where it runs.** Background jobs need PHP to start a detached process (`proc_open`/`exec` with `nohup` or `setsid`) or a cron or systemd worker. Is the live host a normal Linux server or VM where that's allowed, or shared hosting where `exec` may be disabled?
- **Queue behaviour.** Keep one conversion at a time and queue the rest, or reject new uploads while one is running (today's behaviour)?
- **Retention.** Should converted jobs be deleted automatically after N days, or only by hand from the history list?

**YouTube Shorts (Phase 7), to decide:**
- **Crop control.** A simple Left / Centre / Right choice, or a draggable crop box on a preview frame? The box needs an upload-then-configure step, which fits the background-job flow.
- **Landscape sources.** Always crop, or also offer "fit with blurred background", which keeps the whole picture?
- **Length.** Trim to a chosen start time and 3-minute maximum, or just cut automatically at 3:00?
- **Short only.** Should the Short be an extra output alongside MP4/WebM, or should "Shorts only" skip the other formats?

## Progress

**Phase 1, restyle and restructure: done on `ui` (not committed yet).**
- Pico.css 2.0.6 is vendored in `assets/vendor/pico/` with its MIT licence. App tweaks are in `assets/app.css`. Light and dark mode follow the system setting.
- The markup moved to `src/views/` (`layout`, `login`, `setup`, `convert`, `results`, `messages`). `upload-and-convert.php` now only handles the request.
- The rendering helpers (`renderSelect`, `renderCheckbox`, `renderRadios`, `isChecked`) moved into `src/functions.php` and have unit tests.
- The form is in numbered steps: file → outputs → video and audio side by side, stacking to one column on phones. Each checkbox has a hint. The audio settings fade out when audio is off (CSS only). The real upload limit (the smallest of the config and PHP limits) and the accepted types are shown under the file field.
- Errors use `role="alert"`, inputs sit inside their labels, Stereo/Mono are grouped as "Audio channels", stills have numbered alt text, and the Todo comment is gone from the HTML.
- The CSP no longer allows `'unsafe-inline'`. It now has `img-src 'self' data:` for Pico's built-in icons, plus `object-src 'none'` and `base-uri 'none'`.
- Form field names and POST values are unchanged, so existing e2e tests and scripts still work.

**Browser tests: added.** Playwright (`tests/playwright/`, 17 tests) covers logging in and out, form structure and labels, validation errors, a full conversion including stills, downloads and the player page, phone and desktop layouts, dark mode, CSP errors and keyboard order. They run in a new `browser` job in GitHub Actions.

**Not verified yet.** No PHP or Docker was available while building, so PHP syntax, the unit tests, the e2e tests and Playwright still need to run in CI or in Docker. The layout was checked with a static render of the templates at desktop and phone widths, in light and dark mode.

**Phase 4, background jobs: done on `ui-jobs` (PR into `ui`).** Decisions taken on 8 Nov 2026:
- **Everything runs in Docker.** A production compose file was added alongside the test one.
- **Uploads queue** instead of being refused while a conversion runs.
- **Finished conversions are deleted after 7 days** (`job_retention_days`).

What changed:
- The web request only checks the upload, runs ffprobe and writes a job file to `jobs/`. It then redirects (303) to `?job=<id>`, so a refresh never sends the upload again.
- `src/worker.php` converts queued jobs one at a time, holding `logs/convert.lock`. It writes ffmpeg's `-progress` output beside the job file and deletes old jobs every 10 minutes. On start it marks jobs that were left running as interrupted.
- **Job page:**
  - A progress bar, the current step ("WebM (2 of 3)"), an ETA and the queue position.
  - `assets/app.js` checks `?job=<id>&format=json` every 1.5 s. Without JavaScript the page refreshes itself every 5 s.
  - When the job finishes, the page shows the results card, with file sizes on the download buttons.
- **Convert page:**
  - `assets/app.js` checks the file size before uploading and shows an upload progress bar. It also stops double submits and switches the audio settings off with "Enable audio".
  - A "Recent conversions" list (latest 20) has status badges and Delete buttons, with a confirmation step when JavaScript is on. A running job can't be deleted.
- **Docker:**
  - The Dockerfile has `production` and `test` stages.
  - `docker-compose.yml` (test) and `docker-compose.prod.yml` (production) both run an `app` and a `worker` container from one image, sharing volumes for `upload/`, `converted/`, `logs/` and `jobs/`.
  - In production the password hash comes from a Docker secret (`secrets/password_hash`). Other settings come from `FFMPEG_GUI_*` environment variables.
- **Settings:** they are now loaded by `loadConfig()` in this order: `config.php`, then `config.local.php`, then environment variables. The web page and the worker share it.
- **Tests:**
  - Unit tests (`tests/unit/JobsTest.php`) cover the job store, the queue order, deleting and clean-up, reading progress, status text and config loading.
  - The e2e tests now wait on job pages. They also check queueing behind a held lock, deleting, CSRF on delete, retention clean-up, status JSON access, and that `jobs/` is blocked from the web.
  - Playwright now follows the upload through to the results and deletes from the recent list. It checks the size limit before upload, and that the app works without JavaScript.

**Next:** Phase 3 (results polish, player page restyle), then Phase 5 (modern presets) and Phase 7 (YouTube Shorts). Shorts still need the crop-control and length answers above.

## Open questions

1. **Scope.** Which phases go ahead now: just 1–3, or also 4 (background jobs) and/or 5 (new presets)?
2. **Visual style.** Hand-written plain CSS with no dependencies, a small vendored classless CSS framework (e.g. Pico.css, one file, no CDN), or a specific brand or colour scheme?
3. **JavaScript.** Is a small vanilla JS file (progressive enhancement, no build) acceptable, or should the app stay zero-JS?
4. **Conversion model.** Keep converting inside the web request (simple to deploy, with Phase 2 adding a busy state), or move to background jobs with real progress and a history list (more moving parts: a worker or detached process, a status endpoint and cleanup)?
5. **Encoding defaults.** Is it OK to change the default output (e.g. 720p at CRF quality instead of 560x304 at 700 kbit/s)? That changes what existing users get unless they pick the old values.
6. **Ogg/Theora and the old formats.** Keep Ogg as an option, drop it, or hide it under Advanced? And should the iPod/PSP/GIF/MP3 Todo list be removed or kept as a future roadmap?
7. **Failure detail.** Is it OK to show the last few lines of the ffmpeg log to the logged-in user on failure? They can include server paths.
8. **Name.** Should the product be called "ffmpeg web gui", "FFmpeg Web GUI" or something else everywhere?
