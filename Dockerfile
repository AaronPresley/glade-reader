# ====================
# Base images with shared extensions
# ====================
FROM serversideup/php:8.4-fpm-nginx AS base-web
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_OPCACHE_MEMORY_CONSUMPTION=128 \
    PHP_OPCACHE_MAX_ACCELERATED_FILES=10000 \
    PHP_OPCACHE_INTERNED_STRINGS_BUFFER=8 \
    PHP_OPCACHE_SAVE_COMMENTS=1 \
    PHP_OPCACHE_JIT=off \
    PHP_OPCACHE_JIT_BUFFER_SIZE=0 \
    PHP_FPM_PM_CONTROL=ondemand \
    PHP_FPM_PM_MAX_CHILDREN=3 \
    PHP_FPM_PM_MAX_REQUESTS=500 \
    LOG_OUTPUT_LEVEL=warn
USER root
RUN install-php-extensions intl mbstring pgsql
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/
USER www-data

FROM serversideup/php:8.4-cli AS base-cli
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_OPCACHE_MEMORY_CONSUMPTION=128 \
    PHP_OPCACHE_MAX_ACCELERATED_FILES=10000 \
    PHP_OPCACHE_INTERNED_STRINGS_BUFFER=8 \
    PHP_OPCACHE_SAVE_COMMENTS=1 \
    PHP_OPCACHE_JIT=off \
    PHP_OPCACHE_JIT_BUFFER_SIZE=0 \
    PHP_FPM_PM_CONTROL=ondemand \
    PHP_FPM_PM_MAX_CHILDREN=3 \
    PHP_FPM_PM_MAX_REQUESTS=500 \
    LOG_OUTPUT_LEVEL=warn
USER root
RUN install-php-extensions intl mbstring pgsql
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/
USER www-data

# ====================
# Production PHP dependencies
# ====================
FROM base-cli AS php-deps
WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ====================
# Frontend build stage
# ====================
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci --ignore-scripts
COPY --from=php-deps /var/www/html/vendor ./vendor
COPY resources ./resources
COPY vite.config.js ./
COPY public ./public
RUN npm run build

# ====================
# Production build stages
# ====================
FROM base-web AS production
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=php-deps --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build
RUN mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
RUN composer dump-autoload --optimize
RUN php artisan route:cache && php artisan view:cache

FROM base-cli AS production-cli
WORKDIR /var/www/html
COPY --from=production --chown=www-data:www-data /var/www/html /var/www/html
