# ffmpeg web gui: Apache + mod_php + ffmpeg. The same image runs the web app
# and the worker (php src/worker.php). Build from the repository root.
#
#   production   docker compose -f docker-compose.prod.yml up -d --build
#                (password hash from a Docker secret, see README)
#   test         docker compose up -d --build
#                (adds the public test password; only listens on 127.0.0.1)
FROM php:8.4-apache AS production

# Debian's ffmpeg includes libx264, libvpx (VP9), libopus, libtheora and libvorbis.
# pcntl lets the worker finish cleanly on docker stop.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends ffmpeg \
	&& rm -rf /var/lib/apt/lists/* \
	&& docker-php-ext-install pcntl

# Modules used by the .htaccess files, and AllowOverride All for the app
RUN a2enmod headers expires deflate filter rewrite
COPY docker/apache-app.conf /etc/apache2/conf-enabled/zz-app.conf

COPY . /var/www/html/
RUN mkdir -p /var/www/html/jobs \
	&& chown -R www-data:www-data /var/www/html/upload /var/www/html/converted /var/www/html/logs /var/www/html/jobs

# Settings that config.local.php would otherwise hold (see src/jobs.php, CONFIG_ENV)
ENV FFMPEG_GUI_FFMPEG=/usr/bin/ffmpeg \
	FFMPEG_GUI_FFPROBE=/usr/bin/ffprobe

FROM production AS test

# Public test password, only for this test image
ARG TEST_PASSWORD=test-password
RUN php -r '$hash = password_hash(getenv("TEST_PASSWORD"), PASSWORD_DEFAULT); \
	file_put_contents("/var/www/html/config.local.php", "<?php\nreturn " . var_export(array( \
		"password_hash" => $hash, \
	), true) . ";\n");'
