# =============================================================================
# VAIF CRM — Artist-facing Laravel 10 app
#
# Single-container deployment for Coolify: Nginx + PHP-FPM.
#
# Build context: packages/crm/app   (Coolify: Base Directory = /packages/crm/app)
#
# Neither `vendor/` nor `.env` is baked into the image: Composer runs at build
# time, and every setting (APP_KEY, DB_*, WAHA_*) is injected by Coolify as a
# runtime environment variable. See DEPLOY-COOLIFY.md.
# =============================================================================

FROM php:8.2.35-fpm-alpine

# --- PHP extensions ----------------------------------------------------------
#   pdo_pgsql -> shared Supabase Postgres (the SDR agent writes the same tables)
#   mbstring  -> required by Laravel
#   opcache   -> tuned in deploy/php.ini
#
# libpq / oniguruma are the *runtime* libraries the compiled extensions link
# against: they are installed as world packages so `apk del .build-deps` (which
# prunes orphaned dependencies) cannot remove them. unzip is needed by Composer
# to extract package archives (`--prefer-dist`).
RUN apk add --no-cache nginx libpq oniguruma unzip \
    && apk add --no-cache --virtual .build-deps postgresql-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring opcache \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

# --- Nginx / PHP-FPM / php.ini ----------------------------------------------
# The image ships a default server block; drop it and install ours.
RUN rm -f /etc/nginx/http.d/default.conf

COPY deploy/nginx.conf /etc/nginx/http.d/crm.conf
COPY deploy/php-fpm.conf /usr/local/etc/php-fpm.d/zz-vaif.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-vaif.ini

RUN mkdir -p /var/cache/nginx /var/log/nginx \
    && chown -R www-data:www-data /var/cache/nginx /var/log/nginx

# --- Composer ----------------------------------------------------------------
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# --- App code ----------------------------------------------------------------
WORKDIR /app

COPY --chown=www-data:www-data . .

# `--no-scripts` skips post-autoload-dump; package:discover is run explicitly
# below once the whole app (not just composer.json) is present.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

# --- Entrypoint --------------------------------------------------------------
COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

# Coolify also polls this; /api/up is session-free so it costs nothing.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/api/up || exit 1

ENTRYPOINT ["/entrypoint.sh"]
