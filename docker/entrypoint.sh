#!/bin/sh
set -e

PORT="${PORT:-80}"

if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
    # The database may be unreachable (free-tier pauses, expiry, network blips).
    # Never let that keep the web service down: log and boot Apache anyway.
    if ! php /var/www/html/scripts/migrate.php; then
        echo 'WARNING: schema migration failed; serving app without a migrated database.' >&2
    fi
fi

sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/g" /etc/apache2/sites-enabled/000-default.conf

exec "$@"
