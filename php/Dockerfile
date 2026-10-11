FROM php:8.2-fpm-alpine

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo_mysql \
    && apk del .build-deps

WORKDIR /var/www
