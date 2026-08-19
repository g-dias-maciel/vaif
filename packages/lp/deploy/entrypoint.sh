#!/bin/sh
set -e

# Start PHP-FPM in the background
php-fpm -D

# Run Nginx in the foreground (container lifecycle follows nginx)
exec nginx -g 'daemon off;'
