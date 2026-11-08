# Test server: Apache + mod_php + ffmpeg, set up the way the README describes.
# Build from the repository root:
#   docker compose up -d --build
FROM php:8.4-apache

# Debian's ffmpeg includes libx264, libvpx (VP9), libopus, libtheora and libvorbis
RUN apt-get update \
	&& apt-get install -y --no-install-recommends ffmpeg \
	&& rm -rf /var/lib/apt/lists/*

# Modules used by the .htaccess files, and AllowOverride All for the app
RUN a2enmod headers expires deflate filter rewrite
COPY docker/apache-app.conf /etc/apache2/conf-enabled/zz-app.conf

COPY . /var/www/html/

# Test password and ffmpeg paths. TEST_PASSWORD is only for this test image.
ARG TEST_PASSWORD=test-password
RUN php -r '$hash = password_hash(getenv("TEST_PASSWORD"), PASSWORD_DEFAULT); \
	file_put_contents("/var/www/html/config.local.php", "<?php\nreturn " . var_export(array( \
		"password_hash" => $hash, \
		"ffmpeg"        => "/usr/bin/ffmpeg", \
		"ffprobe"       => "/usr/bin/ffprobe", \
	), true) . ";\n");' \
	&& chown -R www-data:www-data /var/www/html/upload /var/www/html/converted /var/www/html/logs
