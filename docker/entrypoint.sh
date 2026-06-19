#!/bin/sh
set -e

# Environment: development vs production
MODE=${PHP_ENV:-production}

# Ensure writable directories exist (in case of fresh volume)
mkdir -p storage/blockchain storage/state storage/cache logs || true
chown -R www-data:www-data storage logs || true

# Ensure read-only assets needed by php-fpm (www-data) are readable regardless of host
# file permissions on a bind mount. The BIP39 word list in particular MUST be readable —
# otherwise Mnemonic falls back to a tiny list and wallet/mnemonic generation fails with
# "Word index out of range".
[ -f core/Cryptography/english.txt ] && chmod a+r core/Cryptography/english.txt 2>/dev/null || true
chmod -R a+rX core api wallet contracts database config 2>/dev/null || true

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
