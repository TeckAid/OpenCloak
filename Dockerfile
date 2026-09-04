ARG PHP_APACHE_IMAGE=php:8.4-apache-bookworm
FROM ${PHP_APACHE_IMAGE}

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite expires headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && docker-php-ext-install pdo_sqlite opcache

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
    && find /var/www/html -mindepth 1 -maxdepth 1 ! -name data ! -name logs -exec chown -R root:root {} + \
    && find /var/www/html -type d ! -path '/var/www/html/data*' ! -path '/var/www/html/logs*' -exec chmod 755 {} \; \
    && find /var/www/html -type f ! -path '/var/www/html/data*' ! -path '/var/www/html/logs*' -exec chmod 644 {} \; \
    && install -d -o 33 -g 33 -m 0770 /var/www/html/data /var/www/html/logs \
    && printf '%s\n' \
        '#!/bin/sh' \
        'set -eu' \
        'install -d -o 33 -g 33 -m 0770 /var/www/html/data /var/www/html/logs' \
        'chown 33:33 /var/www/html/data /var/www/html/logs' \
        'exec docker-php-entrypoint apache2-foreground' \
        > /usr/local/bin/cloaking-entrypoint \
    && chmod 755 /usr/local/bin/cloaking-entrypoint

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 CMD curl -fsS http://127.0.0.1/healthz || exit 1

ENTRYPOINT ["cloaking-entrypoint"]

EXPOSE 80
