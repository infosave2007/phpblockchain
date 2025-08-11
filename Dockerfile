FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    zip \
    unzip \
    openssl-dev \
    mysql-client \
    gmp-dev \
    autoconf \
    gcc \
    g++ \
    make \
    linux-headers

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    sockets \
    gmp

# Install OpenSSL extension (already included in 8.3)
# RUN docker-php-ext-install openssl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Create required directories first
RUN mkdir -p storage/blockchain storage/state storage/cache logs

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/logs

# Copy initialization script and run it
COPY check.php .
RUN php check.php

# Copy PHP-FPM configuration
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Add entrypoint script for dev/prod runtime adjustments
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Expose port
EXPOSE 9000

ENTRYPOINT ["/entrypoint.sh"]
