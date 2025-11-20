#!/bin/bash

echo "🚀 Setting up Supervisor for TAS WebSocket Workers"
echo ""

# Проверка что запущено от root
if [ "$EUID" -ne 0 ]; then 
    echo "❌ Please run as root (sudo ./setup-supervisor.sh)"
    exit 1
fi

# Проверка что supervisor установлен
if ! command -v supervisorctl &> /dev/null; then
    echo "📦 Installing supervisor..."
    apt-get update
    apt-get install -y supervisor
fi

# Копирование конфига
echo "📝 Copying supervisor config..."
cp /var/www/TelegramApiServerManagement/supervisor-websocket.conf /etc/supervisor/conf.d/tas-workers.conf

# Создание директории для логов если нет
mkdir -p /var/www/TelegramApiServerManagement/storage/logs
chown -R www-data:www-data /var/www/TelegramApiServerManagement/storage

# Перечитать конфиги
echo "🔄 Reloading supervisor..."
supervisorctl reread
supervisorctl update

# Запустить workers
echo "▶️  Starting workers..."
supervisorctl start tas-websocket-worker:*
supervisorctl start tas-queue-worker:*

echo ""
echo "✅ Done! Check status:"
echo "   sudo supervisorctl status"
echo ""
echo "📊 View logs:"
echo "   tail -f /var/www/TelegramApiServerManagement/storage/logs/websocket-worker.log"
echo "   tail -f /var/www/TelegramApiServerManagement/storage/logs/queue-worker.log"

