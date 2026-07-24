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

    # Production is expected to be reached on the standard ports, so Caddy's
    # automatic HTTP->HTTPS redirect points somewhere real. Leave it on.
    export CADDY_GLOBAL_OPTIONS=""
    export CADDY_SITE_ADDRESS="$SERVER_NAME"

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

    # Compose publishes 80 as 8080 and 443 as 8443, so Caddy's automatic
    # HTTP->HTTPS redirect would send browsers to https://host/ on port 443 and
    # dead-end. Disable the redirect and declare both schemes explicitly so
    # plain HTTP is served on :80 instead of only being redirected away.
    export CADDY_GLOBAL_OPTIONS="auto_https disable_redirects"

    case "$SERVER_NAME" in
        # already scheme- or port-qualified: use as-is
        :* | http://* | https://*)
            CADDY_SITE_ADDRESS="$SERVER_NAME"
            ;;
        *)
            CADDY_SITE_ADDRESS="http://$SERVER_NAME, https://$SERVER_NAME"
            ;;
    esac
    export CADDY_SITE_ADDRESS

    cat > "$OPCACHE_INI" <<'INI'
opcache.enable=1
opcache.validate_timestamps=1
opcache.revalidate_freq=0
INI

    COMPOSER_FLAGS=""
fi

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
