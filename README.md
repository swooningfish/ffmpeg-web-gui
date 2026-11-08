ffmpeg-web-gui
==============
<pre>
Name    : ffmpeg web gui
Author  : Greg Colley
Created : 03/12/2011
Update  : 07/11/2026
Version : 0.3 beta
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

Requirements
------------

* PHP 8.4 or later. Older PHP releases have known security vulnerabilities (CVEs) and are end of life or receive security fixes only, so keep PHP patched to the latest 8.4+ release.
* ffmpeg and ffprobe 4.3 or later, built with libx264, libvpx 1.7+ (VP9) and libopus. libtheora and libvorbis are only needed for the Ogg output.
* Apache with `mod_rewrite` and `AllowOverride All` for this folder (at minimum `AuthConfig Limit FileInfo Indexes Options`). The `.htaccess` files block web access to uploads, logs, settings and repository files such as `.git/`, and **Apache ignores them under `AllowOverride None`**, which is the default on many systems. On other web servers, deny web access to `upload/`, `logs/`, `.git/`, `vendor/`, `docker/`, dot files, `config.php` and `config.local.php` yourself. For extra safety, point `upload_dir` and `log_dir` at folders outside the web root.
* Conversion runs inside the web request, so the request can last several minutes. ffmpeg is stopped once a conversion has taken `max_execution_time` seconds (default 3600). Keep your web server's timeouts above that plus the upload time: with PHP-FPM, raise Apache's `ProxyTimeout` (or `Timeout`) and the pool's `request_terminate_timeout`, otherwise long conversions end with a 504 error and the server, not the app, stops PHP.

Installation
------------

1. Get a copy of the code:
   <pre>git clone https://github.com/swooningfish/ffmpeg-web-gui.git</pre>
2. Give the web server user write access to `upload/`, `converted/` and `logs/`, for example:
   <pre>sudo chown -R www-data:www-data upload/ converted/ logs/</pre>
3. Generate a password hash:
   <pre>php -r "echo password_hash('your password', PASSWORD_DEFAULT), PHP_EOL;"</pre>
4. Create `config.local.php` with the hash and the paths to ffmpeg and ffprobe. Any setting in `config.php` can be overridden here:
   <pre>&lt;?php
return array(
    'password_hash' =&gt; '$2y$10$...',
    'ffmpeg'        =&gt; '/usr/bin/ffmpeg',
    'ffprobe'       =&gt; '/usr/bin/ffprobe',
);</pre>
5. Open `upload-and-convert.php` in your browser and log in.

The upload limit is 100MB. To change it, update `max_file_size` in your config and `upload_max_filesize` / `post_max_size` in `.htaccess` (mod_php) or `.user.ini` (PHP-FPM).

To update your copy run the following command in the ffmpeg-web-gui directory.
<pre>
  git pull
</pre>

Running the tests
-----------------

GitHub Actions runs every test on each push and pull request (see `.github/workflows/tests.yml`). To run them yourself:

* **Syntax check and unit tests** (needs PHP and Composer):
  <pre>composer install
vendor/bin/phpunit</pre>
* **End-to-end tests** (needs Docker). These start Apache with ffmpeg, convert real test videos, and check that uploads of PHP files, fake videos and playlists are rejected and that private folders return 403:
  <pre>docker compose up -d --build
docker compose exec -T app bash -s &lt; tests/e2e/run.sh
docker compose down</pre>
  While it is running you can also try the app at http://localhost:8080/upload-and-convert.php with the password `test-password`. The test server only listens on this computer, because that password is public.

* **Browser tests** (needs Docker and Node.js 20+). Playwright drives Chromium through the main user journeys: logging in and out, converting a video and using the results, errors, phone and wide-screen layouts, dark mode and keyboard order. Run them on a fresh test server, before the end-to-end tests, because those finish by locking the test IP out of logging in for 5 minutes:
  <pre>docker compose up -d --build
npm ci
npx playwright install chromium
npm run test:ui
docker compose down</pre>
  Set `BASE_URL` to test another server. `npm run test:ui:report` opens the last report, with screenshots and traces of any failure.

Don't run `composer install` or `npm ci` on your live server; they are only needed for the tests.

Security notes
--------------

* The app requires a password unless you set `'require_login' => false`. Only do that if the site is already protected some other way.
* After 5 wrong passwords in a row from one IP address, each further try has to wait 5 minutes (`max_login_failures`, `login_lockout`).
* If HTTPS is handled by a proxy or load balancer, set `'secure_cookie' => true` so the login cookie is only sent over HTTPS.
* Only one conversion runs at a time, and conversion runs inside the web request, so keep the app away from untrusted users.
* Converted files are stored in `converted/<random id>/` and can be downloaded by anyone who has the link. Delete old folders regularly.
* Uploaded originals are deleted after conversion by default (`delete_upload_after_convert`).

Comments and suggestion on the code, and future feature requests are welcome.
