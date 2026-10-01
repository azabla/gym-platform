# syntax=docker/dockerfile:1

# -----------------------------------------------------------------------------
# PHP image for the gym platform.
#
# Multi-stage build:
#   base -> everything production also needs (PHP + extensions + Composer)
#   dev  -> base + developer tools + a non-root user matching your host user
#
# A later lesson adds a "prod" stage that copies the code in and runs
# `composer install --no-dev`. Dev mounts the code as a volume instead.
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
