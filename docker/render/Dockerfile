# syntax=docker/dockerfile:1

# -----------------------------------------------------------------------------
# Production image for Render (https://render.com), described in ADR 0020.
#
# One container runs everything, so it fits Render's free plan:
#   Apache + mod_php  serves the app on $PORT
#   queue:work        sends queued notifications and announcements
#   schedule:work     runs the schedule in routes/console.php
# supervisord starts the three and restarts any that stop.
#
# Unlike development, the code is copied INTO the image (no volume mount).
#
#   assets -> builds CSS/JS with Vite (Node is not needed at runtime)
#   app    -> PHP + extensions + production Composer dependencies + built assets
# -----------------------------------------------------------------------------

FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json .npmrc ./
RUN npm install

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build


FROM php:8.4-apache-bookworm AS app

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Same extensions as the development image (see docker/php/Dockerfile).
RUN install-php-extensions \
        pdo_pgsql pgsql redis intl bcmath gd exif zip opcache pcntl

RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Serve public/ and listen on the port Render gives us in $PORT.
# Apache expands ${PORT} from the environment when it starts.
ENV PORT=8080
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN a2enmod rewrite \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!Listen 80!Listen ${PORT}!' /etc/apache2/ports.conf \
    && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!' /etc/apache2/sites-available/000-default.conf

COPY docker/render/apache-mpm.conf /etc/apache2/mods-available/mpm_prefork.conf

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
COPY docker/render/php.ini /usr/local/etc/php/conf.d/zz-production.ini

WORKDIR /var/www/html

# Install dependencies first so this layer is cached when only app code changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
COPY --from=assets /app/public/build ./public/build

# The autoload scripts discover packages and publish Filament's CSS, JS and fonts into public/.
RUN composer dump-autoload --optimize --no-dev \
    && php artisan filament:assets \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/render/supervisord.conf /etc/supervisor/gym.conf
COPY docker/render/start.sh /usr/local/bin/start
RUN chmod +x /usr/local/bin/start

CMD ["start"]
