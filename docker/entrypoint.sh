#!/bin/sh
set -e

mkdir -p /var/www/html/storage/app/private /var/www/html/storage/app/public
mkdir -p /var/www/html/storage/framework/cache /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

cd /var/www/html
php artisan storage:link --force >/dev/null 2>&1 || true
php artisan migrate --force
php artisan config:clear
php artisan optimize

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
