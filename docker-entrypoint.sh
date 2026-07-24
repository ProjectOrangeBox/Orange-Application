#!/bin/sh
set -e

APP_DIR=/var/www/html
cd "$APP_DIR"

ENV_FILE="$APP_DIR/.env"

# Read a top-level key out of the INI-style .env. Stops at the first [section]
# header so section keys (e.g. [db] host) can never shadow a top-level one.
read_env() {
    _key="$1"
    _default="$2"
    _value=$(sed -n "/^[[:space:]]*\[/q; s/^[[:space:]]*${_key}[[:space:]]*=[[:space:]]*//p" "$ENV_FILE" 2>/dev/null | head -n 1)
    # drop any inline comment, surrounding whitespace and quotes
    _value=$(printf '%s' "$_value" | sed -e 's/[;#].*$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e "s/^['\"]//" -e "s/['\"]\$//")
    if [ -z "$_value" ]; then
        printf '%s' "$_default"
    else
        printf '%s' "$_value"
    fi
}

echo "[entrypoint] preparing writable var/ directories"
for d in logs cache uploads downloads temp working; do
    mkdir -p "var/$d"
done
chmod -R 777 var

# Preserve an existing .env; only seed from the sample when missing.
#
# The comment lines are stripped on the way out because .env has two readers
# that disagree: PHP's parse_ini_file() wants ";" comments and fails on "#",
# while Docker Compose auto-loads .env, wants "#", and fails on ";". No comment
# style satisfies both, so .env holds values only and env.sample holds the docs.
# Only whole-line comments are removed, so a value containing "#" is untouched.
if [ ! -f .env ]; then
    echo "[entrypoint] no .env found, seeding from env.sample"
    sed -e '/^[[:space:]]*[;#]/d' env.sample > .env
else
    echo "[entrypoint] existing .env preserved"
fi

# Defaults to production to match the framework, which does the same when
# ENVIRONMENT is absent (Application::loadEnvironment).
ENVIRONMENT=$(read_env ENVIRONMENT production | tr '[:upper:]' '[:lower:]')

# Hostname Caddy binds to; ":80" serves plain HTTP with no TLS.
SERVER_NAME=$(read_env SERVER_NAME localhost)
export SERVER_NAME

# Restart a worker process after this many requests. 0 = unlimited.
MAX_REQUESTS=$(read_env MAX_REQUESTS 0)
export MAX_REQUESTS

OPCACHE_INI=/usr/local/etc/php/conf.d/zz-orange-opcache.ini

if [ "$ENVIRONMENT" = "production" ]; then
    # Worker mode: the app stays resident between requests. Code is fixed for
    # the life of the container, so opcache never needs to stat files, and a
    # restart is what picks up a deploy.
    echo "[entrypoint] ENVIRONMENT=production -> FrankenPHP worker mode"
    export FRANKENPHP_CONFIG="worker $APP_DIR/worker.php"

    cat > "$OPCACHE_INI" <<'INI'
opcache.enable=1
opcache.validate_timestamps=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
INI

    COMPOSER_FLAGS="--no-dev --optimize-autoloader"
else
    # Classic mode: every request re-reads PHP from disk, exactly like the old
    # Apache setup. validate_timestamps=1 is what makes edits show up without
    # a restart, so it must stay on here.
    echo "[entrypoint] ENVIRONMENT=$ENVIRONMENT -> FrankenPHP classic mode"
    export FRANKENPHP_CONFIG=""

    cat > "$OPCACHE_INI" <<'INI'
opcache.enable=1
opcache.validate_timestamps=1
opcache.revalidate_freq=0
INI

    COMPOSER_FLAGS=""
fi

# How Caddy binds and does TLS is decided by SERVER_NAME, NOT by worker/classic
# mode - a production build tested locally still needs the local TLS behavior.
#
# The trap this avoids: Caddy's automatic HTTP->HTTPS redirect always points at
# the standard port 443, but compose publishes the container's 443 on host 8443.
# So on a local host the redirect from http://host:8080 lands on https://host/
# (443), where nothing is listening, and the page never loads. The fix is to
# serve both schemes with the redirect off whenever the host is a local one.
case "$SERVER_NAME" in
    :* | http://* | https://*)
        # explicit scheme/port in .env - honor it exactly as written
        CADDY_SITE_ADDRESS="$SERVER_NAME"
        CADDY_GLOBAL_OPTIONS=""
        ;;
    localhost | *.localhost | 127.0.0.1 | ::1 | 0.0.0.0)
        # local dev/testing: serve http AND https with no cross-port redirect,
        # so both published ports (8080 and 8443) work. localhost still gets a
        # self-signed cert on the https side.
        echo "[entrypoint] local host '$SERVER_NAME' -> serving http+https, redirect off"
        CADDY_SITE_ADDRESS="http://$SERVER_NAME, https://$SERVER_NAME"
        CADDY_GLOBAL_OPTIONS="auto_https disable_redirects"
        ;;
    *)
        # a real domain: full automatic HTTPS (Let's Encrypt) with the standard
        # HTTP->HTTPS redirect. Assumes the container is published on the
        # standard ports 80/443 - see docker-compose.yml notes for production.
        echo "[entrypoint] domain '$SERVER_NAME' -> automatic HTTPS with redirect"
        CADDY_SITE_ADDRESS="$SERVER_NAME"
        CADDY_GLOBAL_OPTIONS=""
        ;;
esac
export CADDY_SITE_ADDRESS CADDY_GLOBAL_OPTIONS

echo "[entrypoint] serving $SERVER_NAME from $APP_DIR/htdocs"

# Install PHP dependencies once (fast no-op on restarts once vendor/ exists).
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] running composer install"
    # shellcheck disable=SC2086
    composer install --no-interaction --prefer-dist --no-progress $COMPOSER_FLAGS
else
    echo "[entrypoint] vendor/ already present, skipping composer install"
fi

echo "[entrypoint] startup complete, handing off to: $*"
exec docker-php-entrypoint "$@"
