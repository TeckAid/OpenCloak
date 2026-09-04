FROM php:8.2-apache

# Enable required Apache modules
RUN a2enmod rewrite expires headers

# Ensure .htaccess files are honored
RUN sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Install required PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite opcache

# Production PHP settings
COPY docker/php.ini /usr/local/etc/php/conf.d/99-prod.ini

# Set working directory
WORKDIR /var/www/html

# Copy application files (.dockerignore keeps secrets and dev files out)
COPY . /var/www/html/

# Permissions: code readable, data/logs writable by Apache
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && chmod 775 /var/www/html/data /var/www/html/logs

EXPOSE 80
