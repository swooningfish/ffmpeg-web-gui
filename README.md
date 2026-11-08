ffmpeg-web-gui
==============
<pre>
Name    : ffmpeg web gui
Author  : Greg Colley
Created : 03/12/2011
Update  : 08/11/2026
Version : 0.4 beta
</pre>

This is a PHP script for FFmpeg which allows you to upload a video and convert it into html5 (mp4 h264, webm, ogv). Using a simple graphical user interface (GUI) you are also able to fine tune the ffmpeg command and optimise the following options:-

* Video size
* Video bit rate
* Frame rate
* De interlacing
* Enable Audio
* Audio sampling rate
* Audio bit rate
* Amount of channels (Stereo or Mono)
* And other features to come!

The script also generates up to 5 stills from the video allowing you to use these as placement holders, and generates an HTML5 player page for the converted videos. HTML5 video is supported by all current browsers.

Uploads are queued and converted one at a time by a background worker, so you can close the page and come back later. Each conversion has its own page with a live progress bar, and the main page lists recent conversions with delete buttons.

Running with Docker (recommended)
---------------------------------

`docker-compose.prod.yml` runs two containers from one image: `app` (Apache, PHP and ffmpeg) and `worker`, which converts the queued videos. Uploads, conversions, logs and the queue are kept on Docker volumes, so they survive rebuilds and restarts. You only need Docker; PHP and ffmpeg are inside the image.

1. Get a copy of the code:
   <pre>git clone https://github.com/swooningfish/ffmpeg-web-gui.git
cd ffmpeg-web-gui</pre>
2. Save a hash of your password as a Docker secret. Keep the file readable (`644`) because the app reads it as the `www-data` user:
   <pre>mkdir -p secrets
docker run --rm php:8.4-cli php -r "echo password_hash('your password', PASSWORD_DEFAULT);" &gt; secrets/password_hash
chmod 644 secrets/password_hash</pre>
3. Start it:
   <pre>docker compose -f docker-compose.prod.yml up -d --build</pre>
4. Open http://your-server:8080/ and log in.

Put it behind a reverse proxy that handles HTTPS (Caddy, nginx, Traefik and so on), otherwise the password is sent unencrypted. Then set these in a `.env` file next to `docker-compose.prod.yml`:
<pre>FFMPEG_GUI_PORT=127.0.0.1:8080        # only the proxy on this machine can reach the app
FFMPEG_GUI_SECURE_COOKIE=true          # send the login cookie over HTTPS only
FFMPEG_GUI_JOB_RETENTION_DAYS=7        # delete finished conversions after this many days (0 = keep)</pre>

To update, run `git pull` and step 3 again. `docker compose -f docker-compose.prod.yml logs worker` shows what the worker is doing. Other settings in `config.php` can be changed with the environment variables listed in `CONFIG_ENV` in `src/jobs.php`, or by adding a `config.local.php` before building the image.

Running without Docker
----------------------

Requirements:

* PHP 8.4 or later (CLI and web), with the `pcntl` extension recommended for the worker. Older PHP releases have known security vulnerabilities (CVEs) and are end of life or receive security fixes only, so keep PHP patched to the latest 8.4+ release.
* ffmpeg and ffprobe 4.3 or later, built with libx264, libvpx 1.7+ (VP9) and libopus. libtheora and libvorbis are only needed for the Ogg output.
* Apache with `mod_rewrite` and `AllowOverride All` for this folder (at minimum `AuthConfig Limit FileInfo Indexes Options`). The `.htaccess` files block web access to uploads, logs, jobs, settings and repository files such as `.git/`, and **Apache ignores them under `AllowOverride None`**, which is the default on many systems. On other web servers, deny web access to `upload/`, `logs/`, `jobs/`, `src/`, `secrets/`, `.git/`, `vendor/`, `docker/`, dot files, `config.php` and `config.local.php` yourself. For extra safety, point `upload_dir`, `log_dir` and `jobs_dir` at folders outside the web root.
* A way to keep the worker running, such as systemd. The worker stops ffmpeg once a conversion has taken `max_execution_time` seconds (default 3600).

Installation:

1. Get a copy of the code:
   <pre>git clone https://github.com/swooningfish/ffmpeg-web-gui.git</pre>
2. Give the web server user write access to `upload/`, `converted/`, `logs/` and `jobs/`, for example:
   <pre>sudo chown -R www-data:www-data upload/ converted/ logs/ jobs/</pre>
3. Generate a password hash:
   <pre>php -r "echo password_hash('your password', PASSWORD_DEFAULT), PHP_EOL;"</pre>
4. Create `config.local.php` with the hash and the paths to ffmpeg and ffprobe. Any setting in `config.php` can be overridden here:
   <pre>&lt;?php
return array(
    'password_hash' =&gt; '$2y$10$...',
    'ffmpeg'        =&gt; '/usr/bin/ffmpeg',
    'ffprobe'       =&gt; '/usr/bin/ffprobe',
);</pre>
5. Run the worker as the web server user, for example with a systemd service:
   <pre>[Unit]
Description=ffmpeg web gui worker
After=network.target

[Service]
User=www-data
ExecStart=/usr/bin/php /var/www/ffmpeg-web-gui/src/worker.php
Restart=always

[Install]
WantedBy=multi-user.target</pre>
   Or from cron every minute: `php src/worker.php --once` converts everything queued, then exits.
6. Open `upload-and-convert.php` in your browser and log in.

The upload limit is 100MB. To change it, update `max_file_size` in your config and `upload_max_filesize` / `post_max_size` in `.htaccess` (mod_php, which the Docker image uses) or `.user.ini` (PHP-FPM).

To update your copy run `git pull` in the ffmpeg-web-gui directory, then restart the worker.

Running the tests
-----------------

GitHub Actions runs every test on each push and pull request (see `.github/workflows/tests.yml`). To run them yourself:

* **Syntax check and unit tests** (needs PHP and Composer):
  <pre>composer install
vendor/bin/phpunit</pre>
* **End-to-end tests** (needs Docker). These start the test server (Apache with ffmpeg, plus the worker), convert real test videos, and check the queue, deleting and old-job clean-up, that uploads of PHP files, fake videos and playlists are rejected, and that private folders return 403:
  <pre>docker compose up -d --build
docker compose exec -T app bash -s &lt; tests/e2e/run.sh
docker compose down -v</pre>
  While it is running you can also try the app at http://localhost:8080/upload-and-convert.php with the password `test-password`. The test server only listens on this computer, because that password is public. `down -v` also deletes its volumes (test uploads, conversions and logs).

* **Browser tests** (needs Docker and Node.js 20+). Playwright drives Chromium through the main user journeys: logging in and out, uploading and following a conversion to its results, the recent conversions list, errors, using the app without JavaScript, phone and wide-screen layouts, dark mode and keyboard order:
  <pre>docker compose up -d --build
npm ci
npx playwright install chromium
npm run test:ui
docker compose down -v</pre>
  Set `BASE_URL` to test another server. `npm run test:ui:report` opens the last report, with screenshots and traces of any failure.

Don't run `composer install` or `npm ci` on your live server; they are only needed for the tests.

Security notes
--------------

* The app requires a password unless you set `'require_login' => false`. Only do that if the site is already protected some other way.
* After 5 wrong passwords in a row from one IP address, each further try has to wait 5 minutes (`max_login_failures`, `login_lockout`).
* If HTTPS is handled by a proxy or load balancer, set `'secure_cookie' => true` so the login cookie is only sent over HTTPS.
* Only one conversion runs at a time and the others wait in a queue, so one user can keep the server busy. Keep the app away from untrusted users.
* Converted files are stored in `converted/<random id>/` and can be downloaded by anyone who has the link. Finished conversions are deleted after `job_retention_days` (default 7), or straight away with their Delete button.
* Uploaded originals are deleted after conversion by default (`delete_upload_after_convert`).

Comments and suggestion on the code, and future feature requests are welcome.
