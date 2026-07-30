FROM dunglas/frankenphp:php8.5

# install-php-extensions ships with the FrankenPHP image and pulls in whatever
# system libraries each extension needs, so there are no manual build deps here.
# git/unzip/openssh-client are what Composer needs to resolve VCS/dist packages.
RUN install-php-extensions \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        intl \
        bcmath \
        mbstring \
        opcache \
        apcu \
        memcached \
        redis \
        igbinary \
        msgpack \
    && apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        openssh-client \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
# APCu is off on the CLI by default, and PHPUnit runs on the CLI - so without
# this orange/cache's ApcuCache tests cannot run at all (apcu_enabled() returns
# false and the backend refuses to initialize). Only affects the CLI SAPI; the
# web workers already had APCu.
    && printf 'apc.enable_cli=1\n' > "$PHP_INI_DIR/conf.d/zz-apcu-cli.ini"

# PHP Composer (pinned to v2 from the official image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Public web root. Caddy serves from here, so worker.php, .env, config/ and
# vendor/ all sit one level up and are unreachable over HTTP.
ENV SERVER_ROOT=/var/www/html/htdocs

COPY Caddyfile /etc/caddy/Caddyfile

# Prepares var/ dirs, seeds .env, runs composer install, and selects worker vs
# classic mode from ENVIRONMENT in .env. See docker-entrypoint.sh.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
