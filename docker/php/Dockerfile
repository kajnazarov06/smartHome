# One image for php-fpm (app), the worker (queue + devices:listen + scheduler) and Reverb.
FROM php:8.4-fpm-alpine

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pdo_mysql pcntl bcmath intl opcache zip \
    && apk add --no-cache supervisor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-smarthome.ini
COPY docker/php/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html

# Production-style build: bake the code in. In development docker-compose bind-mounts ./backend over it.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
