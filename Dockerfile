FROM php:8.4-apache

ENV TZ=America/Monterrey \
    APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        antiword \
        ca-certificates \
        curl \
        git \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
        msmtp \
        msmtp-mta \
        poppler-utils \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        intl \
        mbstring \
        mysqli \
        opcache \
    && a2enmod expires headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

COPY . .

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-desembarques.ini
COPY docker/entrypoint.sh /usr/local/bin/desembarques-entrypoint

RUN chmod +x /usr/local/bin/desembarques-entrypoint \
    && mkdir -p /var/www/html/storage/uploads \
    && chown -R www-data:www-data /var/www/html/storage

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=5 \
    CMD curl --fail --silent --show-error http://127.0.0.1/login.php > /dev/null || exit 1

ENTRYPOINT ["desembarques-entrypoint"]
CMD ["apache2-foreground"]
