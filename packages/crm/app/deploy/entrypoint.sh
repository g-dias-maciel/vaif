#!/bin/sh
# =============================================================================
# VAIF CRM container entrypoint
#
# 1. make storage/bootstrap writable (they may live on a fresh Coolify volume),
# 2. rebuild Laravel's caches from the *runtime* environment,
# 3. optionally run migrations,
# 4. start PHP-FPM and Nginx.
# =============================================================================
set -e

if [ -z "${APP_KEY}" ]; then
    echo "[entrypoint] FATAL: APP_KEY is not set." >&2
    echo "[entrypoint] Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# --- Writable paths ----------------------------------------------------------
# The image ships these directories as www-data, but a Coolify persistent volume
# mounted at /app/storage replaces them with an empty, root-owned directory.
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

# --- Caches ------------------------------------------------------------------
# Rebuilt on every boot so env-var changes made in Coolify take effect on the
# next deploy/restart. `route:cache` is deliberately omitted: routes/api.php
# registers a closure, which Laravel refuses to cache.
php artisan optimize:clear >/dev/null 2>&1 || true

# --- Migrations --------------------------------------------------------------
# The schema is owned by `packages/crm/db/migrations` (applied by the `migrator`
# deploy step, not by this container). Laravel's own migrations only own
# `users` and `notes`; they are run once by the deploy job as `migrator`.
# RUN_MIGRATIONS is therefore OFF by default: the runtime role (`crm_app`) has
# DML but no DDL.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[entrypoint] running Laravel migrations as $DB_USERNAME"
    php artisan migrate --force --no-interaction
fi

echo "[entrypoint] caching config and views"
php artisan config:cache
php artisan view:cache

# --- Serve -------------------------------------------------------------------
echo "[entrypoint] starting php-fpm + nginx"
php-fpm -D
exec nginx -g 'daemon off;'
