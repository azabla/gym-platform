#!/bin/sh
# Runs every time the container starts on Render.
set -e

# Render sets RENDER_EXTERNAL_URL to the service's own https address.
export APP_URL="${APP_URL:-$RENDER_EXTERNAL_URL}"

# Environment variables are only available now (not at build time), so cache here.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Apply new migrations. --force is required when APP_ENV=production.
# The free plan has no pre-deploy step, so this runs on every start.
php artisan migrate --force

# The commands above ran as root; Apache, the queue and the scheduler run PHP as www-data.
chown -R www-data:www-data storage bootstrap/cache

exec supervisord -c /etc/supervisor/gym.conf
