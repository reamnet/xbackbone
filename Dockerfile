# syntax=docker/dockerfile:1
FROM php:8.5-fpm-trixie AS runtime
RUN --mount=type=secret,id=ca_bundle \
    apt-get update && apt-get install -y --no-install-recommends \
      nginx supervisor ffmpeg curl unzip git ca-certificates \
      libicu-dev libzip-dev libonig-dev libxml2-dev libsqlite3-dev libldap2-dev \
      libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j4 exif ftp gd intl ldap mbstring pdo_mysql pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

FROM runtime AS dependencies
WORKDIR /build
COPY composer.json composer.lock ./
COPY docker/cache-composer.php /tmp/cache-composer.php
RUN --mount=type=secret,id=ca_bundle --mount=type=cache,target=/root/.cache/composer \
    if [ -f /run/secrets/ca_bundle ]; then export COMPOSER_CAFILE=/run/secrets/ca_bundle; fi; \
    php /tmp/cache-composer.php && composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress --no-autoloader
COPY app ./app
RUN composer dump-autoload --no-dev --no-scripts --optimize

FROM node:24-trixie-slim AS assets
WORKDIR /build
COPY package*.json ./
RUN --mount=type=secret,id=ca_bundle --mount=type=cache,target=/root/.npm \
    if [ -f /run/secrets/ca_bundle ]; then export NODE_EXTRA_CA_CERTS=/run/secrets/ca_bundle; fi; npm ci --fetch-retries=5 --fetch-retry-mintimeout=2000
COPY . ./
COPY --from=dependencies /build/vendor ./vendor
RUN npx grunt copy cssmin uglify && rm -rf node_modules tests

FROM runtime AS app
WORKDIR /app/www/public
COPY --from=assets /build/ ./
RUN cp docker/nginx.conf /etc/nginx/nginx.conf \
    && cp docker/supervisord.conf /etc/supervisor/conf.d/xbackbone.conf \
    && cp docker/php.ini /usr/local/etc/php/conf.d/zz-xbackbone.ini \
    && cp docker/entrypoint.sh /usr/local/bin/xbackbone-entrypoint \
    && printf '[www]\nphp_admin_flag[register_argc_argv] = off\n' > /usr/local/etc/php-fpm.d/zz-xbackbone-security.conf \
    && mkdir -p resources/cache/di resources/cache/twig resources/sessions logs \
    && find . -type d -exec chmod 755 {} + \
    && find . -type f -exec chmod 644 {} + \
    && chmod 755 bin/* /usr/local/bin/xbackbone-entrypoint \
    && chown -R www-data:www-data resources/cache resources/sessions logs
ENV PUID=1000 PGID=1000 XBACKBONE_CONTAINER=1
EXPOSE 80 443
VOLUME ["/config"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD curl --fail --silent http://127.0.0.1/login >/dev/null || exit 1
ENTRYPOINT ["xbackbone-entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/xbackbone.conf"]
