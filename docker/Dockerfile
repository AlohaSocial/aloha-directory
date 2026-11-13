# Aloha Directory — production image (PHP-FPM). See docker/compose.yaml.
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache icu-libs libwebp libpng libjpeg-turbo freetype git tini \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libwebp-dev libpng-dev libjpeg-turbo-dev freetype-dev \
 && docker-php-ext-configure gd --with-webp --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" intl pdo_mysql gd opcache \
 && apk del .build-deps

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-aloha-directory.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-aloha-directory.conf
COPY docker/crontab /etc/crontabs/www-data
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY . .
RUN rm -f config.php \
 && composer dump-autoload --classmap-authoritative --no-dev \
 && ALOHA_DIRECTORY_ENV_ONLY=1 APP_ENV=prod APP_DEBUG=0 APP_SECRET=build DATABASE_URL='mysql://build@localhost/build?serverVersion=10.11.0-MariaDB' \
    DIRECTORY_PUBLIC_URL=https://directory.aloha.social DEFAULT_URI=https://directory.aloha.social LOCK_DSN=flock MAILER_DSN=null://null \
    php bin/console cache:warmup --no-interaction \
 && mkdir -p /srv/www var/log \
 && chown -R www-data:www-data var /srv/www \
 && chmod -R a-w src config templates vendor public

USER www-data
ENTRYPOINT ["/sbin/tini", "--", "/app/docker/entrypoint.sh"]
CMD ["php-fpm"]
