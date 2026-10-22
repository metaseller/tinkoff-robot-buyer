FROM composer:2.10.3 AS composer
FROM ghcr.io/shivammathur/extensions/grpc/8.5:1.84.0@sha256:cde35785af4014cf24adc2871a062f80ba60e27c27236a271d5c238bc5afb744 AS grpc

FROM php:8.5.12-apache-trixie AS runtime
ARG BUILD_JOBS=4
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev unzip \
    && docker-php-ext-install -j"${BUILD_JOBS}" bcmath intl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=grpc /grpc@8.5/1.84.0/grpc.so /tmp/grpc.so
RUN mv /tmp/grpc.so "$(php-config --extension-dir)/grpc.so" \
    && docker-php-ext-enable grpc \
    && php --ri grpc \
    && a2enmod rewrite headers \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && apt-mark manual libicu76 \
    && apt-get purge -y --auto-remove autoconf dpkg-dev file g++ gcc libc6-dev make pkg-config re2c libicu-dev \
    && rm -rf /var/lib/apt/lists/*
RUN apt-get update \
    && apt-get install -y --no-install-recommends vim \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

FROM runtime AS dependencies
COPY composer.json composer.lock /app/
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --prefer-dist --no-interaction --no-progress --no-autoloader --no-scripts

FROM dependencies AS production
COPY . /app
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && php init --env=prod --overwrite=n \
    && mkdir -p runtime/logs runtime/files web/assets \
    && chown -R www-data:www-data runtime web/assets \
    && composer check-platform-reqs --no-dev
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s \
    CMD curl --fail --silent http://127.0.0.1/ > /dev/null || exit 1

FROM production AS development
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --prefer-dist --no-interaction --no-progress --no-scripts \
    && composer check-platform-reqs

FROM production AS final
