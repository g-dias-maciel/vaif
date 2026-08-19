# =============================================================================
# VAIF LP — Lucro Oculto Landing Page
#
# Single-container deployment for Coolify: Nginx + PHP-FPM.
#
# Build context: packages/lp   (Coolify: Base Directory = /packages/lp)
#
# App code is copied into /app (which is also the Nginx document root).
# Environment variables (DB_*, N8N_*) are injected at runtime by Coolify.
# =============================================================================

FROM php:8.2-fpm-alpine AS base

# System deps + PHP extensions used by the app:
#   pdo_mysql  -> DB access (leads table)
#   curl       -> n8n webhook forwarding in submit.php
# Build deps (curl-dev, mariadb-dev) are needed to compile the extensions.
RUN apk add --no-cache nginx \
    && apk add --no-cache --virtual .build-deps curl-dev mariadb-dev \
    && docker-php-ext-install pdo_mysql curl \
    && apk del .build-deps

# Nginx needs to write to these dirs; PHP-FPM runs as www-data by default.
RUN mkdir -p /var/cache/nginx /var/log/nginx \
    && chown -R www-data:www-data /var/cache/nginx /var/log/nginx

# --- Nginx config ---
# Drop the default site, install our server block.
RUN rm -f /etc/nginx/http.d/default.conf
COPY deploy/nginx.conf /etc/nginx/http.d/lp.conf

# --- App code ---
# Use a separate stage so /app is owned by www-data (PHP-FPM runs as www-data
# and needs to read it; files are read-only at runtime so nobody is fine too).
COPY --chown=www-data:www-data . /app
WORKDIR /app

# --- Entrypoint ---
# Start PHP-FPM in the background, then run Nginx in the foreground so
# container lifecycle is driven by Nginx.
COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
