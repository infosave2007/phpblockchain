#!/bin/sh
set -e

# Environment: development vs production
MODE=${PHP_ENV:-production}

# Ensure writable directories exist (in case of fresh volume)
mkdir -p storage/blockchain storage/state storage/cache logs || true
chown -R www-data:www-data storage logs || true

# If vendor is missing (e.g. mounted code overwrote image layer) install deps
if [ ! -d vendor/psr ]; then
  echo "[entrypoint] vendor directory missing, running composer install..."
  composer install --no-dev --optimize-autoloader || composer install
fi

# Run integrity check only in production (can be heavy)
if [ "$MODE" = "production" ] && [ -f check.php ]; then
  echo "[entrypoint] Running production integrity check"
  php check.php || echo "[entrypoint] check.php failed (continuing)"
fi

# Development hot-reload note
if [ "$MODE" = "development" ]; then
  echo "[entrypoint] Development mode: code is mounted from host."
fi

exec php-fpm
