FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libzip-dev \
    && docker-php-ext-install intl pdo pdo_mysql zip

# Increase upload limits for media attachments
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html