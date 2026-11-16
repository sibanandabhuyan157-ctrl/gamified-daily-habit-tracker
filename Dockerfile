# Docker container for deploying PHP + PDO SQLite/MySQL publicly (Render, Railway, Fly.io, etc.)
FROM php:8.2-apache

# Enable Apache mod_rewrite, headers, deflate & PDO MySQL/SQLite extensions
RUN a2enmod rewrite headers deflate \
    && docker-php-ext-install pdo pdo_mysql

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /var/www/html/database \
    && chown -R www-data:www-data /var/www/html/database \
    && chmod -R 775 /var/www/html/database

EXPOSE 80
