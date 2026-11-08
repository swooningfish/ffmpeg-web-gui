#!/usr/bin/env bash
#
# End-to-end tests for ffmpeg web gui. They run inside the Docker test
# container so they can use its ffmpeg and look at the files on disk:
#
#   docker compose up -d --build
#   docker compose exec -T app bash -s < tests/e2e/run.sh
#
# Exits non-zero if any test fails.

set -u

BASE=${BASE_URL:-http://localhost}
APP="$BASE/upload-and-convert.php"
PASSWORD=${TEST_PASSWORD:-test-password}
WEBROOT=${WEBROOT:-/var/www/html}
WORK=$(mktemp -d)
JAR="$WORK/cookies"
PAGE="$WORK/page.html"

passed=0
failed=0
pass() { echo "  PASS  $1"; passed=$((passed + 1)); }
fail() { echo "  FAIL  $1"; failed=$((failed + 1)); }
# expect "description" command...   passes when the command succeeds
expect() { local name=$1; shift; if "$@"; then pass "$name"; else fail "$name"; fi; }
page_has() { grep -qF -- "$1" "$PAGE"; }
page_lacks() { ! grep -qF -- "$1" "$PAGE"; }
status_of() { curl -s -o /dev/null -w '%{http_code}' "$1"; }
csrf_from() { grep -o 'name="csrf" value="[0-9a-f]*"' "$1" | head -n 1 | sed 's/.*value="//; s/"$//'; }
count_job_dirs() { find "$WEBROOT/converted" -mindepth 1 -maxdepth 1 -type d | wc -l; }
uploads_left() { find "$WEBROOT/upload" -type f ! -name '.htaccess' ! -name '.gitignore' | wc -l; }
audio_codec() { ffprobe -v error -select_streams a -show_entries stream=codec_name -of csv=p=0 "$1" | head -n 1; }
audio_channels() { ffprobe -v error -select_streams a -show_entries stream=channels -of csv=p=0 "$1" | head -n 1; }
video_codec() { ffprobe -v error -select_streams v -show_entries stream=codec_name -of csv=p=0 "$1" | head -n 1; }
video_width() { ffprobe -v error -select_streams v -show_entries stream=width -of csv=p=0 "$1" | head -n 1; }

# Wait for the worker to finish the conversion at URL (its ?job= page), then
# save that page in $PAGE.
wait_for_job() {
	local url=$1 state=
	for _ in $(seq 1 300); do
		state=$(curl -s -b "$JAR" "$url&format=json" | grep -o '"status":"[a-z]*"' | cut -d '"' -f 4)
		case "$state" in done|failed) break ;; esac
		sleep 1
	done
	curl -s -c "$JAR" -b "$JAR" -o "$PAGE" "$url"
}
# Upload a video, 320x240 by default. Extra curl arguments are appended.
# An accepted upload redirects to its ?job= page; this waits for the worker
# to convert it, so $PAGE ends up with the results. A refused upload leaves
# the form with its error in $PAGE. $JOB_URL is the job page, or empty.
convert() {
	JOB_URL=$(curl -s --max-time 600 -c "$JAR" -b "$JAR" -o "$PAGE" -L -w '%{url_effective}' \
		-F "csrf=$CSRF" -F action=convert \
		-F video_size=320x240 -F video_bitrate=360 -F video_framerate=25 \
		-F encoding_audio_sampling_rate=44100 -F encoding_audio_bitrate=128 -F encoding_audio_channels=stereo \
		"$@" "$APP")
	case "$JOB_URL" in
		*job=*) wait_for_job "$JOB_URL" ;;
		*) JOB_URL= ;;
	esac
}
count_job_files() { find "$WEBROOT/jobs" -maxdepth 1 -name '*.json' | wc -l; }
# grep -q PATTERN in the job status JSON at URL
status_has() { curl -s -b "$JAR" "$1&format=json" | grep -q "$2"; }
all_outputs=(-F encoding_enable_audio=1 -F encoding_x264=1 -F encoding_webm=1 -F encoding_ogv=1 -F encoding_stills=1)
# Folder of the conversion shown on the page, e.g. /var/www/html/converted/0123456789abcdef
job_dir() { local rel; rel=$(grep -o 'converted/[0-9a-f]\{16\}/' "$PAGE" | head -n 1); [ -n "$rel" ] && echo "$WEBROOT/$rel"; }

echo "Waiting for $APP"
for _ in $(seq 1 30); do
	[ "$(status_of "$APP")" = "200" ] && break
	sleep 1
done

echo "Creating test videos in $WORK"
# -nostdin: this script itself arrives on stdin (bash -s), and ffmpeg would
# otherwise read keypresses from it and eat part of the script
ffmpeg -nostdin -v error -f lavfi -i testsrc2=size=640x480:rate=25:duration=7 -f lavfi -i sine=frequency=440:duration=7 \
	-c:v libx264 -b:v 4M -pix_fmt yuv420p -c:a aac -shortest "$WORK/main.mp4"
ffmpeg -nostdin -v error -f lavfi -i testsrc=size=160x120:rate=25:duration=3 -f lavfi -i sine=duration=3 \
	-c:v mpeg4 -c:a libmp3lame -shortest "$WORK/small.avi"
for video in main.mp4 small.avi; do
	[ -s "$WORK/$video" ] || { echo "Could not create test video $video"; exit 1; }
done
printf '<?php echo "pwned"; ?>' > "$WORK/shell.php"
printf 'this is not a video\n' > "$WORK/fake.mp4"
printf '#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:0\n#EXTINF:10.0,\nfile:///etc/passwd\n#EXT-X-ENDLIST\n' > "$WORK/playlist.mp4"
echo "  main.mp4 is $(stat -c %s "$WORK/main.mp4") bytes (over PHP's 2MB default, so it also tests the .htaccess limits)"

# ---------------------------------------------------------------------------
echo "Login and CSRF"
curl -s -c "$JAR" -b "$JAR" -o "$PAGE" "$APP"
CSRF=$(csrf_from "$PAGE")
expect "login form is shown before logging in" page_has 'value="login"'
expect "page sends X-Content-Type-Options" sh -c "curl -sI '$APP' | grep -qi '^x-content-type-options: nosniff'"
expect "page sends a Content-Security-Policy" sh -c "curl -sI '$APP' | grep -qi '^content-security-policy:'"
expect "CSP allows no inline styles or scripts" sh -c "! curl -sI '$APP' | grep -i '^content-security-policy:' | grep -q 'unsafe-inline'"
expect "the stylesheets and icon are served" [ "$(status_of "$BASE/assets/app.css")$(status_of "$BASE/assets/vendor/pico/pico.min.css")$(status_of "$BASE/assets/icon.svg")" = "200200200" ]
expect "page templates cannot be opened directly" [ "$(status_of "$BASE/src/views/layout.php")" = "403" ]

before=$(count_job_dirs)
convert "${all_outputs[@]}" -F "file=@$WORK/small.avi"
expect "convert is refused when not logged in" page_has 'value="login"'
expect "nothing is converted when not logged in" [ "$(count_job_dirs)" = "$before" ]

curl -s -c "$JAR" -b "$JAR" -o "$PAGE" -F "csrf=$CSRF" -F action=login -F password=wrong "$APP"
expect "wrong password is rejected" page_has 'Incorrect password.'

curl -s -c "$JAR" -b "$JAR" -o "$PAGE" -F "csrf=wrong-token" -F action=login -F "password=$PASSWORD" "$APP"
expect "login without a valid CSRF token is rejected" page_has 'Your session expired.'

curl -s -c "$JAR" -b "$JAR" -o "$PAGE" -F "csrf=$CSRF" -F action=login -F "password=$PASSWORD" "$APP"
expect "correct password logs in" page_has 'value="logout"'

before=$(count_job_dirs)
CSRF_SAVED=$CSRF; CSRF=bad-token
convert "${all_outputs[@]}" -F "file=@$WORK/small.avi"
CSRF=$CSRF_SAVED
expect "convert without a valid CSRF token is rejected" page_has 'Your session expired.'
expect "nothing is converted without a valid CSRF token" [ "$(count_job_dirs)" = "$before" ]

# ---------------------------------------------------------------------------
echo "Converting a normal video (all formats)"
convert "${all_outputs[@]}" -F "file=@$WORK/main.mp4"
JOB=$(job_dir)
expect "the upload redirects to its conversion page" [ -n "$JOB_URL" ]
expect "conversion reports success" page_has 'Converted'
expect "the conversion page says it is ready" page_has 'Your video is ready'
expect "no conversion failures are reported" page_lacks 'Failed:'
expect "a job folder is created" [ -n "$JOB" ]
JOB=${JOB:-/nonexistent}
expect "MP4 is H.264 with AAC audio" [ "$(video_codec "$JOB/main.mp4")/$(audio_codec "$JOB/main.mp4")" = "h264/aac" ]
expect "WebM is VP9 with Opus audio" [ "$(video_codec "$JOB/main.webm")/$(audio_codec "$JOB/main.webm")" = "vp9/opus" ]
expect "Ogg is Theora with Vorbis audio" [ "$(video_codec "$JOB/main.ogv")/$(audio_codec "$JOB/main.ogv")" = "theora/vorbis" ]
expect "MP4 is scaled down to fit 320x240" [ "$(video_width "$JOB/main.mp4")" = "320" ]
expect "MP4 has faststart (moov before mdat)" sh -c "head -c 200000 '$JOB/main.mp4' | grep -aob 'moov\|mdat' | head -n 1 | grep -q moov"
for i in 1 2 3 4 5; do
	expect "still $i exists" [ -s "$JOB/main_still_$i.png" ]
done
expect "player page exists" [ -s "$JOB/main_player.html" ]
expect "player page has no Flash fallback" sh -c "! grep -q shockwave '$JOB/main_player.html'"
expect "player page uses the third still as poster" grep -q 'poster="main_still_3.png"' "$JOB/main_player.html"
expect "the uploaded original is deleted" [ "$(uploads_left)" = "0" ]
JOB_ID=$(basename "$JOB")
expect "the job is recorded as done" grep -q '"status": "done"' "$WEBROOT/jobs/$JOB_ID.json"
expect "job status JSON reports done" status_has "$JOB_URL" '"status":"done"'
expect "job status JSON needs a login" [ "$(status_of "$APP?job=$JOB_ID&format=json")" = "403" ]
expect "an unknown job is not found" [ "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$APP?job=0123456789abcdef")" = "404" ]
expect "a malformed job id is not found" [ "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$APP?job=../../config")" = "404" ]
curl -s -c "$JAR" -b "$JAR" -o "$PAGE" "$APP"
expect "the form page lists the conversion" page_has "?job=$JOB_ID"
expect "ffmpeg log is written" sh -c "ls '$WEBROOT'/logs/ffmpeg_*.log >/dev/null 2>&1"
expect "log has no stray '2>&1' output argument" sh -c "! grep -q \"'2>&1'\" '$WEBROOT'/logs/ffmpeg_*.log"

REL=${JOB#"$WEBROOT/"}
expect "converted MP4 can be downloaded (200)" [ "$(status_of "$BASE/$REL/main.mp4")" = "200" ]
expect "converted WebM can be downloaded (200)" [ "$(status_of "$BASE/$REL/main.webm")" = "200" ]
expect "player page can be opened (200)" [ "$(status_of "$BASE/$REL/main_player.html")" = "200" ]
expect "still image can be opened (200)" [ "$(status_of "$BASE/$REL/main_still_1.png")" = "200" ]

# ---------------------------------------------------------------------------
echo "Audio options"
convert -F encoding_enable_audio=1 -F encoding_webm=1 -F encoding_audio_bitrate=320 -F encoding_audio_channels=mono -F "file=@$WORK/small.avi"
JOB=$(job_dir); JOB=${JOB:-/nonexistent}
expect "WebM works with mono at 320 kbps (Opus cap)" [ "$(audio_channels "$JOB/small.webm")" = "1" ]

convert -F encoding_enable_audio=1 -F encoding_ogv=1 -F encoding_audio_bitrate=8 -F encoding_audio_sampling_rate=11025 -F "file=@$WORK/small.avi"
JOB=$(job_dir); JOB=${JOB:-/nonexistent}
expect "Ogg works at 8 kbps / 11025 Hz (Vorbis quality mode)" [ "$(audio_codec "$JOB/small.ogv")" = "vorbis" ]

convert -F encoding_x264=1 -F encoding_webm=1 -F "file=@$WORK/small.avi"
JOB=$(job_dir); JOB=${JOB:-/nonexistent}
expect "audio off: MP4 has no audio stream" [ -z "$(audio_codec "$JOB/small.mp4")" ]
expect "audio off: WebM has no audio stream" [ -z "$(audio_codec "$JOB/small.webm")" ]
expect "small video is not upscaled" [ "$(video_width "$JOB/small.mp4")" = "160" ]

# ---------------------------------------------------------------------------
echo "Filenames"
convert -F encoding_x264=1 -F "file=@$WORK/small.avi;filename=\"My <img src=x onerror=alert(1)> clip.avi\""
JOB=$(job_dir); JOB=${JOB:-/nonexistent}
expect "filename with spaces and HTML converts" [ -s "$JOB/my-img-src-x-onerror-alert-1-clip.mp4" ]
expect "filename is escaped on the page" page_has '&lt;img src=x onerror=alert(1)&gt;'
expect "raw HTML from the filename is not on the page" page_lacks '<img src=x onerror'
expect "filename is escaped in the player page" sh -c "! grep -q '<img src=x' '$JOB'/*_player.html"

# ---------------------------------------------------------------------------
echo "Rejected uploads"
before=$(count_job_dirs)
before_jobs=$(count_job_files)
convert "${all_outputs[@]}" -F "file=@$WORK/shell.php;type=video/mp4"
expect "PHP file sent as video/mp4 is rejected" page_has 'Unsupported file type'

convert "${all_outputs[@]}" -F "file=@$WORK/shell.php;type=video/mp4;filename=shell.php.mp4"
expect "PHP code named .mp4 is rejected by ffprobe" page_has 'That file is not a supported video.'

convert "${all_outputs[@]}" -F "file=@$WORK/fake.mp4"
expect "text file named .mp4 is rejected" page_has 'That file is not a supported video.'

convert "${all_outputs[@]}" -F "file=@$WORK/playlist.mp4"
expect "HLS playlist named .mp4 is rejected" page_has 'That file is not a supported video.'

expect "no job folders are created for rejected uploads" [ "$(count_job_dirs)" = "$before" ]
expect "no jobs are queued for rejected uploads" [ "$(count_job_files)" = "$before_jobs" ]
expect "rejected uploads stay on the form page" [ -z "$JOB_URL" ]
expect "no PHP file is left anywhere in the web root" [ -z "$(find "$WEBROOT/upload" "$WEBROOT/converted" -name '*.php*')" ]
expect "rejected uploads are deleted" [ "$(uploads_left)" = "0" ]

convert "${all_outputs[@]}" -F "video_size=640x360 -f data -i /etc/passwd" -F "file=@$WORK/small.avi"
expect "injected ffmpeg arguments are rejected" page_has 'Invalid value for Video size.'

convert -F "file=@$WORK/small.avi"
expect "converting with no outputs ticked is rejected" page_has 'Please choose at least one output format.'

# ---------------------------------------------------------------------------
echo "Queue: one conversion at a time"
# Hold the lock the worker takes while converting, as if a conversion were running
LOCK="$WEBROOT/logs/convert.lock"
touch "$LOCK" && chown www-data:www-data "$LOCK"
flock -o "$LOCK" sleep 30 &
LOCK_PID=$!
sleep 1
QUEUED_URL=$(curl -s --max-time 120 -c "$JAR" -b "$JAR" -o "$PAGE" -L -w '%{url_effective}' \
	-F "csrf=$CSRF" -F action=convert -F encoding_x264=1 -F video_size=320x240 -F "file=@$WORK/small.avi" "$APP")
expect "an upload is accepted while another conversion runs" page_has 'Waiting to convert'
sleep 3
expect "it stays queued while the other conversion runs" status_has "$QUEUED_URL" '"status":"queued"'
kill "$LOCK_PID" 2>/dev/null; wait "$LOCK_PID" 2>/dev/null
wait_for_job "$QUEUED_URL"
expect "it is converted once the other conversion has finished" page_has 'Your video is ready'

# ---------------------------------------------------------------------------
echo "Deleting conversions"
DEL_ID=${QUEUED_URL##*job=}
curl -s -c "$JAR" -b "$JAR" -o "$PAGE" -F "csrf=$CSRF" -F action=delete_job -F "job=$DEL_ID" "$APP"
expect "a deleted conversion's folder is removed" [ ! -e "$WEBROOT/converted/$DEL_ID" ]
expect "a deleted conversion's job file is removed" [ ! -e "$WEBROOT/jobs/$DEL_ID.json" ]
curl -s -c "$JAR" -b "$JAR" -o "$PAGE" -F "csrf=bad-token" -F action=delete_job -F "job=$JOB_ID" "$APP"
expect "delete without a valid CSRF token is rejected" [ -e "$WEBROOT/jobs/$JOB_ID.json" ]

convert -F encoding_x264=1 -F "file=@$WORK/small.avi"
OLD_ID=${JOB_URL##*job=}
php -r '$f = $argv[1]; $j = json_decode(file_get_contents($f), true); $j["finished"] = time() - 8 * 86400; file_put_contents($f, json_encode($j));' "$WEBROOT/jobs/$OLD_ID.json"
su -s /bin/sh www-data -c "php '$WEBROOT/src/worker.php' --once" >/dev/null
expect "the worker deletes conversions older than job_retention_days" sh -c "[ ! -e '$WEBROOT/jobs/$OLD_ID.json' ] && [ ! -e '$WEBROOT/converted/$OLD_ID' ]"
expect "newer conversions are kept" [ -e "$WEBROOT/jobs/$JOB_ID.json" ]

# ---------------------------------------------------------------------------
echo "Web access to private files"
ANY_JOB=$(find "$WEBROOT/converted" -mindepth 1 -maxdepth 1 -type d | head -n 1)
ANY_JOB=${ANY_JOB#"$WEBROOT/"}
echo '<?php echo "pwned";' > "$WEBROOT/converted/${ANY_JOB#converted/}/planted.php"
echo '<?php echo "pwned";' > "$WEBROOT/upload/planted.php"
LOG_NAME=$(basename "$(ls "$WEBROOT"/logs/ffmpeg_*.log | head -n 1)")
for path in upload/ upload/planted.php logs/ "logs/$LOG_NAME" logs/convert.lock jobs/ "jobs/$JOB_ID.json" jobs/jobs.lock src/worker.php src/views/layout.php \
	config.php config.local.php .user.ini src/ src/functions.php \
	converted/ "$ANY_JOB/" "$ANY_JOB/planted.php"; do
	code=$(status_of "$BASE/$path")
	expect "/$path is blocked ($code)" [ "$code" = "403" ]
done
rm -f "$WEBROOT/converted/${ANY_JOB#converted/}/planted.php" "$WEBROOT/upload/planted.php"

echo "Web access to repository and development files"
mkdir -p "$WEBROOT/.git" "$WEBROOT/.github" "$WEBROOT/vendor" "$WEBROOT/emptydir"
echo '[remote "origin"]' > "$WEBROOT/.git/config"
echo 'name: x' > "$WEBROOT/.github/ci.yml"
echo '<?php echo "pwned";' > "$WEBROOT/vendor/autoload.php"
for path in .git/ .git/config .github/ci.yml vendor/ vendor/autoload.php docker/ docker/Dockerfile 	composer.json phpunit.xml.dist docker-compose.yml docker-compose.prod.yml .gitignore .gitattributes .htaccess emptydir/; do
	code=$(status_of "$BASE/$path")
	expect "/$path is blocked ($code)" [ "$code" = "403" ]
done
rm -rf "$WEBROOT/.git" "$WEBROOT/.github" "$WEBROOT/vendor" "$WEBROOT/emptydir"
expect "the folder URL opens the app" sh -c "curl -s '$BASE/' | grep -q 'ffmpeg web gui'"

# ---------------------------------------------------------------------------
echo "Logout"
curl -s -c "$JAR" -b "$JAR" -o "$PAGE" -F "csrf=$CSRF" -F action=logout "$APP"
expect "logout shows the login form again" page_has 'value="login"'

# ---------------------------------------------------------------------------
echo "Login lockout"
CSRF=$(csrf_from "$PAGE")
for _ in 1 2 3 4 5; do
	curl -s -c "$JAR" -b "$JAR" -o "$PAGE" -F "csrf=$CSRF" -F action=login -F password=wrong "$APP"
done
expect "five wrong passwords are each rejected" page_has 'Incorrect password.'
curl -s -c "$JAR" -b "$JAR" -o "$PAGE" -F "csrf=$CSRF" -F action=login -F "password=$PASSWORD" "$APP"
expect "the correct password is refused after five wrong ones" page_has 'Too many incorrect passwords.'
expect "the lockout does not log in" page_lacks 'value="logout"'
JAR2="$WORK/cookies2"
curl -s -c "$JAR2" -b "$JAR2" -o "$PAGE" "$APP"
CSRF2=$(csrf_from "$PAGE")
curl -s -c "$JAR2" -b "$JAR2" -o "$PAGE" -F "csrf=$CSRF2" -F action=login -F "password=$PASSWORD" "$APP"
expect "a new session is locked out too" page_has 'Too many incorrect passwords.'
rm -f "$WEBROOT/logs/login_failures.json"

echo
echo "$passed passed, $failed failed"
rm -rf "$WORK"
[ "$failed" -eq 0 ]
