# --- PHP dependencies ---
FROM composer:2 AS vendor
WORKDIR /app
COPY application/composer.json application/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    --ignore-platform-reqs

# --- Runtime ---
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libgmp-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libxml2-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gmp gd zip soap pdo_mysql \
 && rm -rf /var/lib/apt/lists/* \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Patch notes cache (CHANGELOG_CACHE_DIR). docker-compose.yml mounts a volume here, which takes over this owner.
RUN mkdir -p /var/cache/portal && chown www-data:www-data /var/cache/portal
# Shared combat logs (COMBATLOG_DIR), a volume the same way.
RUN mkdir -p /srv/combatlogs && chown www-data:www-data /srv/combatlogs
# Crash reports from the launcher (CRASHREPORT_DIR), a volume the same way.
RUN mkdir -p /srv/crashreports && chown www-data:www-data /srv/crashreports

COPY docker/apache-security.conf /etc/apache2/conf-enabled/zz-security.conf
COPY --chown=www-data:www-data . /var/www/html/
COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/application/vendor
COPY --chown=www-data:www-data docker/config.php /var/www/html/application/config/config.php
