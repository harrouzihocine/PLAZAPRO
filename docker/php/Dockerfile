# docker/php/Dockerfile — Laravel app + queue worker image (PHP 8.3)
FROM php:8.3-fpm-alpine

# System deps for common Laravel extensions + image handling
RUN apk add --no-cache \
      git curl zip unzip icu-dev oniguruma-dev libzip-dev \
      libpng-dev libjpeg-turbo-dev freetype-dev mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql bcmath intl zip gd opcache

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
    && adduser -D -u ${UID} -G www-data appuser 2>/dev/null || true

# php-fpm listens on 9000 (nginx fastcgi_pass app:9000)
EXPOSE 9000
CMD ["php-fpm"]
