#!/bin/sh
set -e

echo "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}..."
until pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" -d "${DB_NAME}" >/dev/null 2>&1; do
  sleep 1
done

composer dump-autoload --optimize >/dev/null
php scripts/migrate.php

if [ "${APP_BOOTSTRAP_DEMO_DATA:-1}" = "1" ]; then
  php scripts/seed_demo.php
fi

exec php -S 0.0.0.0:8080 -t public public/router.php
