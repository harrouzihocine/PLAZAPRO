# docker/php/Dockerfile — Laravel app + queue worker + Reverb image (PHP 8.3)
FROM php:8.3-fpm-alpine

# System deps for common Laravel extensions + image handling.
# linux-headers provides <linux/sock_diag.h>, required to compile the sockets ext.
RUN apk add --no-cache \
      git curl zip unzip icu-dev oniguruma-dev libzip-dev \
      libpng-dev libjpeg-turbo-dev freetype-dev mysql-client linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    # pcntl: signal handling for `queue:work --timeout` and the Reverb server's
    # graceful shutdown. sockets: transport for the Reverb websocket loop.
    && docker-php-ext-install pdo_mysql bcmath intl zip gd opcache pcntl sockets

# Redis extension (cache / queue / session driver)
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Match the host user so bind-mounted files are writable without root-owned artefacts
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} appgroup 2>/dev/null || true \
    && adduser -D -u ${UID} -G www-data appuser 2>/dev/null || true \
    # Run the php-fpm pool as that user (not the default www-data) so writes to the
    # bind-mounted storage/ and bootstrap/cache land as the host UID instead of failing
    # with permission errors (compiled views, tempnam). Master stays root to setuid.
    && sed -i 's/^user = www-data/user = appuser/; s/^group = www-data/group = appgroup/' \
         /usr/local/etc/php-fpm.d/www.conf

# php-fpm listens on 9000 (nginx fastcgi_pass app:9000)
EXPOSE 9000
CMD ["php-fpm"]
