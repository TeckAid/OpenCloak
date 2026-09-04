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
COPY --chown=root:root . /var/www/html/

RUN printf '%s\n' \
        '<?php' \
        "define('APP_RUNTIME_DIR', '/var/www/html/data');" \
        "define('DB_PATH', '/var/www/html/data/cloaking.sqlite');" \
        "define('LOG_PATH', '/var/www/html/logs/');" \
        "define('APP_BASE_URL', 'http://localhost');" \
        "define('SYSTEM_HOSTS', ['localhost', '127.0.0.1']);" \
        > /var/www/html/config.local.php \
    && install -d -o 33 -g 33 -m 0770 /var/www/html/data /var/www/html/logs /var/www/html/docs /var/www/html/docker \
    && php /var/www/html/bin/migrate.php \
    && printf 'ok\n' > /var/www/html/healthz \
    && printf 'readme sentinel\n' > /var/www/html/README.md \
    && printf 'review sentinel\n' > /var/www/html/CODE_REVIEW.md \
    && printf 'plan sentinel\n' > /var/www/html/IMPLEMENTATION_PLAN.md \
    && printf 'dockerfile sentinel\n' > /var/www/html/Dockerfile \
    && printf 'compose sentinel\n' > /var/www/html/docker-compose.yml \
    && printf 'nginx sentinel\n' > /var/www/html/nginx.conf \
    && printf 'caddy sentinel\n' > /var/www/html/docker/Caddyfile \
    && printf 'doc sentinel\n' > /var/www/html/docs/internal.md \
    && printf 'log sentinel\n' > /var/www/html/logs/app.log \
    && cp /var/www/html/data/cloaking.sqlite /var/www/html/data/cloaking.sqlite-wal \
    && cp /var/www/html/data/cloaking.sqlite /var/www/html/data/cloaking.sqlite-shm \
    && find /var/www/html -mindepth 1 -maxdepth 1 ! -name data ! -name logs -exec chown -R root:root {} +

EXPOSE 80
