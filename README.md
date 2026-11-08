ffmpeg-web-gui
==============
<pre>
Name    : ffmpeg web gui
Author  : Greg Colley
Created : 03/12/2011
Update  : 08/11/2026
Version : 0.4 beta
</pre>

A web page for FFmpeg: upload a video and convert it to HTML5 video (MP4 H.264, WebM and Ogg), with a simple form to fine-tune the conversion.

## TL;DR

You need [Git](https://git-scm.com/downloads) and [Docker](https://docs.docker.com/get-docker/) (Docker Desktop on Windows and macOS). Then run:

```bash
git clone https://github.com/swooningfish/ffmpeg-web-gui.git
cd ffmpeg-web-gui
docker compose up -d --build
```

Open **http://localhost:8080/** and log in with the password **`test-password`**.

The first build takes a few minutes while it downloads PHP and ffmpeg. When you're finished:

```bash
docker compose down
```

This is the local test setup. Its password is public, so it only listens on your own computer. To run it on a server with your own password, see [Run it on a server](#run-it-on-a-server).

## What it does

Upload a video, then tune the ffmpeg command with these options:

* Video size
* Video bit rate
* Frame rate
* De-interlacing
* Enable audio
* Audio sampling rate
* Audio bit rate
* Number of channels (stereo or mono)
* And other features to come!

The app also saves up to 5 stills from the video, which you can use as placeholders, and makes an HTML5 player page for the converted videos. HTML5 video is supported by all current browsers.

Uploads are queued and converted one at a time by a background worker, so you can close the page and come back later. Each conversion has its own page with a live progress bar, and the main page lists recent conversions with delete buttons.

## Running with Docker (recommended)

You only need Git and Docker: PHP, Apache and ffmpeg are inside the image. Use Docker Compose v2 (the `docker compose` command, with a space), which comes with Docker Desktop and current Docker Engine packages.

Both setups run two containers from one image: `app` (Apache, PHP and ffmpeg) and `worker`, which converts the queued videos. Uploads, conversions, logs and the queue are kept on Docker volumes, so they survive rebuilds and restarts.

| File | Use it for | Password | Listens on |
|---|---|---|---|
| `docker-compose.yml` | Trying the app on your own computer, and the tests | `test-password` | `127.0.0.1:8080` (this computer only) |
| `docker-compose.prod.yml` | A real server | Your own, from a Docker secret | Port `8080` on every network interface (you can change this) |

### Try it on your computer

1. Get a copy of the code:
   ```bash
   git clone https://github.com/swooningfish/ffmpeg-web-gui.git
   cd ffmpeg-web-gui
   ```
2. Build and start it:
   ```bash
   docker compose up -d --build
   ```
3. Open http://localhost:8080/ and log in with the password `test-password`.

Useful commands, run from the `ffmpeg-web-gui` folder:

| Command | What it does |
|---|---|
| `docker compose ps` | Shows whether the containers are running |
| `docker compose logs -f worker` | Follows what the worker is converting (Ctrl+C to stop following) |
| `docker compose down` | Stops it. Your uploads and conversions are kept |
| `docker compose down -v` | Stops it and **deletes** the uploads, conversions and logs |

### Run it on a server

1. Get a copy of the code:
   ```bash
   git clone https://github.com/swooningfish/ffmpeg-web-gui.git
   cd ffmpeg-web-gui
   ```
2. Save a hash of your password in `secrets/password_hash`. Docker passes it to the app as a Docker secret, so the password itself is never stored.

   On Linux and macOS:
   ```bash
   mkdir -p secrets
   docker run --rm php:8.4-cli php -r "echo password_hash('your password', PASSWORD_DEFAULT);" > secrets/password_hash
   chmod 644 secrets/password_hash
   ```
   Keep the file readable (`644`) because the app reads it as the `www-data` user. In this command, avoid `$`, `"`, `'` and `\` in your password, or the shell changes them.

   On Windows (PowerShell):
   ```powershell
   New-Item -ItemType Directory -Force secrets | Out-Null
   docker run --rm php:8.4-cli php -r "echo password_hash('your password', PASSWORD_DEFAULT);" | Set-Content -NoNewline -Encoding ascii secrets/password_hash
   ```
   Don't use `>` in Windows PowerShell here: it saves the file as UTF-16, and the app then won't accept your password.
3. Build and start it:
   ```bash
   docker compose -f docker-compose.prod.yml up -d --build
   ```
4. Open `http://<your server>:8080/` (or http://localhost:8080/ on the same computer) and log in.

Put it behind a reverse proxy that handles HTTPS (Caddy, nginx, Traefik and so on), otherwise the password is sent unencrypted. Then create a `.env` file next to `docker-compose.prod.yml` with these settings:

```ini
# Only the proxy on this machine can reach the app
FFMPEG_GUI_PORT=127.0.0.1:8080
# Send the login cookie over HTTPS only
FFMPEG_GUI_SECURE_COOKIE=true
# Delete finished conversions after this many days (0 = keep them)
FFMPEG_GUI_JOB_RETENTION_DAYS=7
```

and run step 3 again to apply them.

Useful commands, run from the `ffmpeg-web-gui` folder:

| Command | What it does |
|---|---|
| `git pull` then `docker compose -f docker-compose.prod.yml up -d --build` | Updates to the latest version |
| `docker compose -f docker-compose.prod.yml logs -f worker` | Follows what the worker is converting |
| `docker compose -f docker-compose.prod.yml restart` | Restarts it, for example after changing the password hash |
| `docker compose -f docker-compose.prod.yml down` | Stops it. Your uploads and conversions are kept |

`down -v` also deletes the volumes, which means **every upload and conversion**.

#### Other settings

The settings in `config.php` that can be set from `.env` are listed in `CONFIG_ENV` in `src/jobs.php`. For any other setting, create a `config.local.php` (see [Running without Docker](#running-without-docker), step 4) and mount it into both containers by adding this line to the `volumes:` list near the top of `docker-compose.prod.yml`:

```yaml
    - ./config.local.php:/var/www/html/config.local.php:ro
```

Then run `docker compose -f docker-compose.prod.yml up -d`. Copying `config.local.php` into the image doesn't work, because `.dockerignore` leaves it out. Settings from `.env` and the password secret take priority over `config.local.php`.

### Troubleshooting

* **`docker: command not found`, or "Cannot connect to the Docker daemon"**: install Docker, or start Docker Desktop and wait until it says it's running.
* **`unknown command: docker compose`** or **`'compose' is not a docker command`**: you have the old Compose v1. Update Docker, or install the Compose plugin.
* **"port is already allocated"**: something else is using port 8080. For the test setup, change `127.0.0.1:8080:80` in `docker-compose.yml` to another port, such as `127.0.0.1:8081:80`, then open http://localhost:8081/. For a server, set `FFMPEG_GUI_PORT=8081` in `.env`.
* **"secret password_hash: file not found"** (server setup): you skipped step 2, or ran it in another folder. `secrets/password_hash` must be inside the `ffmpeg-web-gui` folder.
* **The page doesn't load straight after starting**: give it a few seconds, then check `docker compose ps` and `docker compose logs app`.
* **Your password isn't accepted** (server setup): make the hash again with step 2, then restart. On Windows, use the PowerShell command shown, not `>`.

## Running without Docker

Requirements:

* PHP 8.4 or later (CLI and web), with the `pcntl` extension recommended for the worker. Older PHP releases have known security vulnerabilities (CVEs) and are end of life or receive security fixes only, so keep PHP patched to the latest 8.4+ release.
* ffmpeg and ffprobe 4.3 or later, built with libx264, libvpx 1.7+ (VP9) and libopus. libtheora and libvorbis are only needed for the Ogg output.
* Apache with `mod_rewrite` and `AllowOverride All` for this folder (at minimum `AuthConfig Limit FileInfo Indexes Options`). The `.htaccess` files block web access to uploads, logs, jobs, settings and repository files such as `.git/`, and **Apache ignores them under `AllowOverride None`**, which is the default on many systems. On other web servers, deny web access to `upload/`, `logs/`, `jobs/`, `src/`, `secrets/`, `.git/`, `vendor/`, `docker/`, dot files, `config.php` and `config.local.php` yourself. For extra safety, point `upload_dir`, `log_dir` and `jobs_dir` at folders outside the web root.
* A way to keep the worker running, such as systemd. The worker stops ffmpeg once a conversion has taken `max_execution_time` seconds (default 3600).

Installation:

1. Get a copy of the code:
   ```bash
   git clone https://github.com/swooningfish/ffmpeg-web-gui.git
   ```
2. Give the web server user write access to `upload/`, `converted/`, `logs/` and `jobs/`, for example:
   ```bash
   sudo chown -R www-data:www-data upload/ converted/ logs/ jobs/
   ```
3. Generate a password hash:
   ```bash
   php -r "echo password_hash('your password', PASSWORD_DEFAULT), PHP_EOL;"
   ```
4. Create `config.local.php` with the hash and the paths to ffmpeg and ffprobe. Any setting in `config.php` can be overridden here:
   ```php
   <?php
   return array(
       'password_hash' => '$2y$10$...',
       'ffmpeg'        => '/usr/bin/ffmpeg',
       'ffprobe'       => '/usr/bin/ffprobe',
   );
   ```
5. Run the worker as the web server user, for example with a systemd service:
   ```ini
   [Unit]
   Description=ffmpeg web gui worker
   After=network.target

   [Service]
   User=www-data
   ExecStart=/usr/bin/php /var/www/ffmpeg-web-gui/src/worker.php
   Restart=always

   [Install]
   WantedBy=multi-user.target
   ```
   Or from cron every minute: `php src/worker.php --once` converts everything queued, then exits.
6. Open `upload-and-convert.php` in your browser and log in.

The upload limit is 100MB. To change it, update `max_file_size` in your config and `upload_max_filesize` / `post_max_size` in `.htaccess` (mod_php, which the Docker image uses) or `.user.ini` (PHP-FPM).

To update your copy run `git pull` in the ffmpeg-web-gui directory, then restart the worker.

## Running the tests

GitHub Actions runs every test on each push and pull request (see `.github/workflows/tests.yml`). To run them yourself:

* **Syntax check and unit tests** (needs PHP and Composer):
  ```bash
  composer install
  vendor/bin/phpunit
  ```
* **End-to-end tests** (needs Docker). These start the test server (Apache with ffmpeg, plus the worker), convert real test videos, and check the queue, deleting and old-job clean-up, that uploads of PHP files, fake videos and playlists are rejected, and that private folders return 403:
  ```bash
  docker compose up -d --build
  docker compose exec -T app bash -s < tests/e2e/run.sh
  docker compose down -v
  ```
  This uses the same test server as the [TL;DR](#tldr). `down -v` also deletes its volumes (test uploads, conversions and logs).

* **Browser tests** (needs Docker and Node.js 20+). Playwright drives Chromium through the main user journeys: logging in and out, uploading and following a conversion to its results, the recent conversions list, errors, using the app without JavaScript, phone and wide-screen layouts, dark mode and keyboard order:
  ```bash
  docker compose up -d --build
  npm ci
  npx playwright install chromium
  npm run test:ui
  docker compose down -v
  ```
  Set `BASE_URL` to test another server. `npm run test:ui:report` opens the last report, with screenshots and traces of any failure.

Don't run `composer install` or `npm ci` on your live server; they are only needed for the tests.

## Security notes

* The app requires a password unless you set `'require_login' => false`. Only do that if the site is already protected some other way.
* After 5 wrong passwords in a row from one IP address, each further try has to wait 5 minutes (`max_login_failures`, `login_lockout`).
* If HTTPS is handled by a proxy or load balancer, set `'secure_cookie' => true` (or `FFMPEG_GUI_SECURE_COOKIE=true` with Docker) so the login cookie is only sent over HTTPS.
* Only one conversion runs at a time and the others wait in a queue, so one user can keep the server busy. Keep the app away from untrusted users.
* Converted files are stored in `converted/<random id>/` and can be downloaded by anyone who has the link. Finished conversions are deleted after `job_retention_days` (default 7), or straight away with their Delete button.
* Uploaded originals are deleted after conversion by default (`delete_upload_after_convert`).

Comments and suggestions on the code, and future feature requests, are welcome.
