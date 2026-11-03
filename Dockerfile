FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libxml2-dev \
        libzip-dev \
        libonig-dev \
        poppler-utils \
    && docker-php-ext-install pdo_mysql mbstring dom zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-reviewradar.ini
COPY docker/apache.conf /etc/apache2/conf-available/reviewradar.conf
RUN a2enconf reviewradar

COPY docker/entrypoint.sh /usr/local/bin/reviewradar-entrypoint
RUN chmod +x /usr/local/bin/reviewradar-entrypoint

WORKDIR /var/www/html
COPY . /var/www/html/

EXPOSE 10000

ENTRYPOINT ["reviewradar-entrypoint"]
CMD ["apache2-foreground"]
