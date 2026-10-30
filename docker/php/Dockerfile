# syntax=docker/dockerfile:1

# -----------------------------------------------------------------------------
# PHP image for the gym platform.
#
# Multi-stage build:
#   base -> everything production also needs (PHP + extensions + Composer)
#   dev  -> base + developer tools + a non-root user matching your host user
#   prod -> base + the code, `composer install --no-dev`, nginx and the
#           workers in one container (the default target; see ADR 0020)
#
# Dev mounts the code as a volume instead (compose.yaml asks for `dev`).
# -----------------------------------------------------------------------------

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-fpm-bookworm AS base

# install-php-extensions handles system libraries and compile flags for us.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# pdo_pgsql/pgsql : PostgreSQL
# redis           : phpredis client (queues, cache, locks)
# intl            : Amharic/Unicode-aware formatting and sorting
# bcmath          : arbitrary precision math where we ever need it
# gd, exif        : member photos, receipt images
# zip             : Composer, exports
# opcache         : compiled bytecode cache
# pcntl           : required by Horizon and graceful queue shutdown
RUN install-php-extensions \
        pdo_pgsql pgsql redis intl bcmath gd exif zip opcache pcntl

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html


FROM base AS dev

ARG UID=1000
ARG GID=1000

# pcov: fast code coverage for tests.
RUN install-php-extensions pcov

# git/unzip for Composer, postgresql-client for psql/pg_dump from the app container.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip postgresql-client \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

# Run as a user with the same UID/GID as you on the host, so files created
# inside the container (vendor/, migrations, logs) are owned by you, not root.
RUN (getent group "${GID}" || groupadd --gid "${GID}" app) \
    && useradd --uid "${UID}" --gid "${GID}" --create-home --shell /bin/bash app

USER app


FROM base AS prod

# nginx serves the app and supervisor runs PHP-FPM, nginx, the queue worker
# and the scheduler side by side, so one container is the whole app.
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor unzip \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/production/nginx.conf /etc/nginx/conf.d/gym.conf
COPY docker/production/supervisord.conf /etc/supervisor/conf.d/gym.conf
COPY docker/production/entrypoint.sh /usr/local/bin/gym-entrypoint

# Dependencies first, so a code change doesn't reinstall them.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

# Its scripts discover packages and publish Filament's assets.
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/gym-entrypoint

EXPOSE 8080

ENTRYPOINT ["gym-entrypoint"]
