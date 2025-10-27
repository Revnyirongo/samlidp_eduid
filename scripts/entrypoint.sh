#!/usr/bin/env bash
set -euo pipefail

cd /app/app

mkdir -p /home/app/.composer/cache

if [ ! -d vendor ]; then
  echo "Installing PHP dependencies with Composer..."
else
  echo "Updating PHP dependencies with Composer (if needed)..."
fi

composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts

if [ ! -f app/config/parameters.yml ]; then
  echo "Bootstrapping Symfony parameters from dist file..."
  cp app/config/parameters.yml.dist app/config/parameters.yml
fi

/app/scripts/wait-for-db.sh "${POSTGRES_HOST:-db}" "${POSTGRES_PORT:-5432}"

php /app/scripts/migrate.php
php /app/scripts/seed.php

php bin/console cache:clear --no-warmup --env=prod || true
php bin/console cache:warmup --env=prod || true
php bin/console assets:install --symlink --env=prod || true
php bin/console assetic:dump --env=prod --no-debug || true
php bin/console doctrine:schema:update --force --env=prod || true
php bin/console samli:user:create admin admin@example.org adminpass Admin Admin --super-admin --env=prod >/dev/null 2>&1 || true

SERVER_HOST=${APP_HOST:-0.0.0.0}
SERVER_PORT=${APP_PORT:-8080}

echo "Starting PHP built-in server on ${SERVER_HOST}:${SERVER_PORT}"

exec php -S "${SERVER_HOST}:${SERVER_PORT}" -t web web/app.php
