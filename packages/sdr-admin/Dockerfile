# sdr-admin — standalone beta bundle for the SDR agent admin surfaces.
# Routes /onboard (QR connect) and /agenda (block days off) on staging.
# nginx + PHP-FPM, no framework.

FROM php:8.3-fpm-alpine

RUN docker-php-ext-install pdo pdo_mysql 2>/dev/null || true

WORKDIR /var/www/html
COPY index.php onboard agenda img ./

EXPOSE 9000
CMD ["php-fpm"]