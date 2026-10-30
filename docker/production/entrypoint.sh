#!/bin/sh
# Starts the production container (ADR 0020): caches the configuration from
# the environment the host gives, brings the database up to date, seeds the
# demo gym when SEED_DEMO is true (the seeders run safely more than once),
# then hands over to supervisor.
set -e

cd /var/www/html

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

php artisan migrate --force

if [ "${SEED_DEMO:-false}" = "true" ]; then
    php artisan db:seed --force
fi

chown -R www-data:www-data storage bootstrap/cache

exec supervisord -c /etc/supervisor/supervisord.conf
