# syntax=docker/dockerfile:1
#
# Production image of the site (php artisan content:install --docker): PHP 8.5 FPM + nginx in ONE container, port 3000, tuned for a small
# idle footprint. The runtime stage is a copy of the one in FullStack Hub's examples/laravel/Dockerfile (the content:install test keeps
# them in sync); the first two stages differ because a site has no path repository: the SDK comes from composer.
# Build from the repository root: docker build -t site .
#
# No secrets and no .env in the image: APP_KEY, FSC_SERVER_TOKEN, FSC_WEBHOOK_SECRET and FSC_API_URL arrive as runtime environment variables.

# --- 1. PHP dependencies (no dev packages) ---------------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
# git is needed when the SDK comes from a VCS repository; with --prefer-dist it usually arrives as a zipball.
RUN apk add --no-cache git
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY app app
COPY bootstrap bootstrap
COPY config config
COPY routes routes
RUN composer dump-autoload --optimize --classmap-authoritative --no-scripts --no-interaction --ignore-platform-reqs

# --- 2. Frontend assets (vite) ---------------------------------------------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources resources
# The stylesheet and the Blade views of the SDK are read by vite (and Tailwind).
COPY --from=vendor /app/vendor/fullstackstudio/content/resources vendor/fullstackstudio/content/resources
RUN npm run build

# --- 3. Runtime ------------------------------------------------------------------------------------------------------------
FROM registry.int0.net/base/php:8.5.12-fpm-alpine AS runtime

# OPcache is built into PHP 8.5; it must not be compiled as a shared extension.
RUN apk add --no-cache nginx \
    && rm -rf /usr/local/etc/php-fpm.d/* /etc/nginx/http.d/* /var/cache/apk/* /tmp/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /var/www/html
COPY --chown=www-data:www-data artisan ./
COPY --chown=www-data:www-data public public
COPY --chown=www-data:www-data resources resources
COPY --chown=www-data:www-data storage storage
COPY --from=vendor --chown=www-data:www-data /app/vendor vendor
COPY --from=vendor --chown=www-data:www-data /app/app app
COPY --from=vendor --chown=www-data:www-data /app/bootstrap bootstrap
COPY --from=vendor --chown=www-data:www-data /app/config config
COPY --from=vendor --chown=www-data:www-data /app/routes routes
COPY --from=vendor --chown=www-data:www-data /app/composer.json composer.json
COPY --from=assets --chown=www-data:www-data /app/public/build public/build

RUN mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SESSION_DRIVER=file \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    FSC_FORMS_ENABLED=true

USER www-data
# The php-fpm base image sets SIGQUIT; the entrypoint handles TERM (and QUIT).
STOPSIGNAL SIGTERM
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 CMD wget -q -O /dev/null http://127.0.0.1:3000/api/health || exit 1
ENTRYPOINT ["entrypoint"]
