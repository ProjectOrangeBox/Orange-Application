#!/bin/sh
set -e

APP_DIR=/var/www/html
cd "$APP_DIR"

echo "[entrypoint] preparing writable var/ directories"
for d in logs cache uploads downloads temp working; do
    mkdir -p "var/$d"
done
chmod -R 777 var

# Preserve an existing .env; only seed from the sample when missing.
if [ ! -f .env ]; then
    echo "[entrypoint] no .env found, seeding from support/samples/sample.env"
    cp support/samples/sample.env .env
else
    echo "[entrypoint] existing .env preserved"
fi

# Install PHP dependencies once (fast no-op on restarts once vendor/ exists).
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] running composer install"
    composer install --no-interaction --prefer-dist --no-progress
else
    echo "[entrypoint] vendor/ already present, skipping composer install"
fi

echo "[entrypoint] startup complete, handing off to: $*"
exec docker-php-entrypoint "$@"
