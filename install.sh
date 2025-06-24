#!/bin/bash

# PHP Blockchain - Quick Setup for Production
echo "🔧 PHP Blockchain - Production Setup"
echo "===================================="

# Allow Composer to run as root for production deployment
export COMPOSER_ALLOW_SUPERUSER=1

# Setup proper ownership for web server
if [ "$EUID" -eq 0 ]; then
    echo "⚠️  Running as root - setting up for production..."
    chown -R www-data:www-data /home/phpblockchain 2>/dev/null || true
fi

echo "📦 Installing composer dependencies..."
cd /home/phpblockchain || exit 1

# Clean previous installation
rm -rf vendor/
composer clear-cache

# Configure platform and install
composer config platform.php 8.1.0
composer install --ignore-platform-reqs --no-dev --optimize-autoloader

echo "🔑 Setting permissions..."
chmod -R 755 /home/phpblockchain
chmod -R 777 /home/phpblockchain/storage
chmod -R 777 /home/phpblockchain/logs

echo "🧪 Testing installation..."
cd /home/phpblockchain
php test.php

echo "✅ Setup complete!"
echo "📝 Project ready at: /home/phpblockchain"
echo "🌐 Start web server: php -S 0.0.0.0:8080 index.php"
