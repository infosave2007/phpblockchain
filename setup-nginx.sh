#!/bin/bash

# Быстрая настройка Nginx для PHP Blockchain
echo "🌐 Настройка Nginx для PHP Blockchain..."

# Копируем конфигурацию
cp nginx-site.conf /etc/nginx/sites-available/phpblockchain

# Активируем сайт
ln -sf /etc/nginx/sites-available/phpblockchain /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# Проверяем конфигурацию
nginx -t

# Перезапускаем Nginx
systemctl reload nginx

echo "✅ Nginx настроен!"
echo "🌐 Веб-установщик: http://$(hostname -I | awk '{print $1}')/install"
echo "📱 Приложение: http://$(hostname -I | awk '{print $1}')/"
