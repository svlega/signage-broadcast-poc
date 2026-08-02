# syntax=docker/dockerfile:1

# ---- Stage 1: build admin dashboard assets ----------------------------
# Separate stage so the Node toolchain (and its layer weight) never ends
# up in the runtime image — only the compiled public/build/ output does.
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js tsconfig.json ./
COPY resources ./resources
RUN npm run build

# ---- Stage 2: install PHP dependencies ---------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts: artisan isn't available yet (app/ hasn't been copied in),
# and package:discover would fail trying to boot the framework this early.
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

COPY . .

# package:discover (run below via post-autoload-dump) needs the
# framework to boot, which wants *a* .env present — the real one is
# supplied at container start by docker-compose, never baked into the
# image, so this placeholder only exists for this build step.
RUN cp .env.example .env \
    && composer dump-autoload --optimize --classmap-authoritative \
    && composer run-script post-autoload-dump \
    && rm .env

# ---- Stage 3: runtime ---------------------------------------------------
# composer.lock was resolved against PHP 8.4 (the local dev environment's
# version), which pinned at least one dependency to ">=8.4.1" even though
# composer.json's own constraint is the looser "^8.3" — the lock file's
# actual resolved requirement wins, so the runtime image must match it.
FROM php:8.4-fpm-alpine AS runtime

# Redis (matches the app's queue/cache/broadcasting-scaling driver) isn't
# a core PHP extension and has to be built via PECL; everything else here
# is what a stock Laravel app needs (pdo_mysql for the database, gd for
# any future image processing, opcache for production performance). nginx
# lives in this same image — see docker/start-web.sh for why. pcntl is
# what Reverb's server process and `queue:work` use for graceful
# signal handling (SIGTERM/SIGINT) — without it, `reverb:start` fails
# outright on an undefined SIGINT constant.
#
# $PHPIZE_DEPS (autoconf/gcc/make/...) is installed explicitly as our own
# named virtual package rather than relying on docker-php-ext-install's
# auto-managed one — that helper installs and *removes* its own build
# toolchain within a single invocation, which would leave nothing left
# for the `pecl install redis` line right after it to compile with.
RUN apk add --no-cache \
        nginx \
        libpng-dev \
        libjpeg-turbo-dev \
        libzip-dev \
        icu-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        zip \
        bcmath \
        intl \
        opcache \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

# Overwrite the package's own default site (which listens on port 80 —
# a bind that would fail outright once this process drops to a non-root
# user below) with ours, which listens on the unprivileged 8080 instead.
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# Everything in this image — php-fpm, nginx, artisan — runs as www-data,
# never root; nginx's own `user` directive is a no-op without root and
# is left as-is (it just logs a harmless warning and keeps running as
# whatever user started it, which is already www-data here).
RUN chown -R www-data:www-data storage bootstrap/cache /var/lib/nginx /var/log/nginx

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/start-web.sh /usr/local/bin/start-web.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/start-web.sh

USER www-data

ENTRYPOINT ["entrypoint.sh"]
CMD ["start-web.sh"]
