#!/bin/sh
set -e

PORT="${PORT:-80}"

if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
    php /var/www/html/scripts/migrate.php
fi

sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/g" /etc/apache2/sites-enabled/000-default.conf

exec "$@"
