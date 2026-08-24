#syntax=docker/dockerfile:1

# Versions
FROM dunglas/frankenphp:1-php8.5 AS frankenphp_upstream

# Base FrankenPHP image
FROM frankenphp_upstream AS frankenphp_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# persistent deps
RUN <<-EOF
    apt-get update
    apt-get install -y --no-install-recommends \
       file \
       git

    install-php-extensions \
       @composer \
       apcu \
       intl \
       opcache \
       zip \
       pdo_pgsql \
       pgsql
    rm -rf /var/lib/apt/lists/*
EOF

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

###> recipes ###
###> doctrine/doctrine-bundle ###
RUN install-php-extensions pdo_pgsql
###< doctrine/doctrine-bundle ###
###< recipes ###

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch

# dev dependencies
# hadolint ignore=DL3008
RUN <<-EOF
    mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
    apt-get update
    apt-get install -y --no-install-recommends \
       aggregate \
       curl \
       dnsmasq \
       dnsutils \
       iproute2 \
       ipset \
       iptables \
       jq \
       sudo
    install-php-extensions xdebug
    rm -rf /var/lib/apt/lists/*
    useradd -m -s /bin/bash nonroot
    echo "nonroot ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/nonroot
    git config --system --add safe.directory /app
EOF

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Builder for the prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod_builder

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link --exclude=frankenphp/ . ./

# Sequential Production Compilation & Dependency Mapping Execution
RUN <<-EOF
    mkdir -p var/cache var/log var/share
    composer dump-autoload --classmap-authoritative --no-dev
    composer dump-env prod
    composer run-script --no-dev post-install-cmd

    php bin/console tailwind:build

    if [ -f importmap.php ]; then
       php bin/console asset-map:compile
    fi
    chmod +x bin/console
    chmod -R g=u var

    apt-get update
    apt-get install -y --no-install-recommends libtree
    mkdir -p /tmp/libs
    BINARIES=(frankenphp php file)
    for target in $(printf '%s\n' "${BINARIES[@]}" | xargs -I{} which {}) \
       $(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
       libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
          [ -f "$lib" ] && cp -n "$lib" /tmp/libs/
       done
    done
    rm -rf /var/lib/apt/lists/*

    sync
EOF

# Prod FrankenPHP image
FROM debian:13-slim AS frankenphp_prod

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=prod
ENV PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"

COPY --from=frankenphp_prod_builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=frankenphp_prod_builder /usr/local/bin/php /usr/local/bin/php
COPY --from=frankenphp_prod_builder /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint
COPY --from=frankenphp_prod_builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=frankenphp_prod_builder /tmp/libs /usr/lib

COPY --from=frankenphp_prod_builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=frankenphp_prod_builder /usr/local/etc/php/php.ini /usr/local/etc/php/php.ini
COPY --from=frankenphp_prod_builder /usr/local/etc/php/app.conf.d /usr/local/etc/php/app.conf.d

COPY --from=frankenphp_prod_builder /etc/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

# CA certificates for TLS, file/libmagic for Symfony MIME type detection
COPY --from=frankenphp_prod_builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=frankenphp_prod_builder /etc/ssl/openssl.cnf /etc/ssl/openssl.cnf
COPY --from=frankenphp_prod_builder /usr/bin/file /usr/bin/file
COPY --from=frankenphp_prod_builder /usr/lib/file/magic.mgc /usr/lib/file/magic.mgc

ENV  OPENSSL_CONF=/etc/ssl/openssl.cnf XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data

# ── FIXED PERMISSIONS BLOCK ──
RUN <<-EOF
    # Pre-create the internal Caddy directories inside the image layer
    mkdir -p /data/caddy /config/caddy /data/caddy/pki/authorities/local

    # Force ownership of the entire data/config ecosystem to www-data
    chown -R www-data:www-data /data /config

    # Remove setuid/setgid bits for production security hardening
    find / -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true
EOF

COPY --link --exclude=var --from=frankenphp_prod_builder /app /app
# Group 0 + g=u for arbitrary-UID runtimes (e.g. OpenShift).
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/var /app/var
RUN chmod g=u /app/var

COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

USER www-data

WORKDIR /app

# Stamped by the release workflow, which is the only thing that publishes an
# image. The defaults are what a hand-rolled local build gets, and that is the
# point: "make verify-deploy" reads the version label off the running container,
# so an image that was never released cannot pass itself off as one.
#
# Declared here at the end of the stage so a version bump invalidates nothing
# but the metadata layers -- the vendor install, the asset build and the binary
# scrape above are all still cache hits.
ARG APP_VERSION=0.0.0-dev
ARG APP_REVISION=unknown
ARG APP_BUILD_DATE=1970-01-01T00:00:00Z

LABEL org.opencontainers.image.title="Malta Beyblade League" \
      org.opencontainers.image.source="https://github.com/mcutajar/beybladexmalta" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${APP_REVISION}" \
      org.opencontainers.image.created="${APP_BUILD_DATE}"

# The same value the label carries, readable from inside the container without
# docker inspect -- which is what the app would need to render it on a page.
ENV APP_VERSION=${APP_VERSION}

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
