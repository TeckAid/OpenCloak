#
# Update pinned base images with:
#   docker buildx imagetools inspect php:8.4-apache-bookworm
# Then replace the digest below with the new OCI index digest.
ARG PHP_APACHE_IMAGE=docker.io/library/php:8.4-apache-bookworm@sha256:25d70665acee86d7231af7bc5464794abd14585f80210f85f22dfb0713ac8ec7
FROM ${PHP_APACHE_IMAGE}

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite expires headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    # pdo_sqlite, sqlite3 and opcache are compiled into the official php image;
    # fail the build early if a future base image drops them.
    && php -m | grep -qx pdo_sqlite \
    && php -m | grep -qx 'Zend OPcache'

WORKDIR /var/www/html

COPY docker/php.ini /usr/local/etc/php/conf.d/99-prod.ini
COPY --chown=root:root .htaccess config.php config.local.example.php index.php install.php /var/www/html/
COPY --chown=root:root admin /var/www/html/admin
COPY --chown=root:root api /var/www/html/api
COPY --chown=root:root assets /var/www/html/assets
COPY --chown=root:root bin /var/www/html/bin
COPY --chown=root:root includes /var/www/html/includes
COPY --chown=root:root migrations /var/www/html/migrations

RUN printf 'ok\n' > /var/www/html/healthz \
    && chown -R root:root /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && install -d -o 33 -g 33 -m 0770 /srv/cloaking/runtime/logs \
    && printf '%s\n' \
        '#!/bin/sh' \
        'set -eu' \
        'install -d -o 33 -g 33 -m 0770 /srv/cloaking/runtime /srv/cloaking/runtime/logs' \
        'chown 33:33 /srv/cloaking/runtime /srv/cloaking/runtime/logs' \
        'if [ "$(id -u)" = "0" ]; then' \
        '    setpriv --reuid=33 --regid=33 --clear-groups php /var/www/html/bin/migrate.php >&2' \
        'else' \
        '    php /var/www/html/bin/migrate.php >&2' \
        'fi' \
        'exec docker-php-entrypoint apache2-foreground' \
        > /usr/local/bin/cloaking-entrypoint \
    && chmod 755 /usr/local/bin/cloaking-entrypoint

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 CMD curl -fsS http://127.0.0.1/healthz || exit 1

ENTRYPOINT ["cloaking-entrypoint"]

EXPOSE 80
