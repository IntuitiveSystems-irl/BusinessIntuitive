#!/bin/sh
# Start cron for scheduled newsletter sends
crond -b -l 8

# Start PHP-FPM in background, then Nginx in foreground
php-fpm -D
nginx -g "daemon off;"
