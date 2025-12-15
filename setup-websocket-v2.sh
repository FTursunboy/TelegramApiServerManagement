#!/bin/bash

echo "🚀 Setting up WebSocket Manager V2"
echo ""

# Check root
if [ "$EUID" -ne 0 ]; then 
    echo "❌ Please run as root (sudo ./setup-websocket-v2.sh)"
    exit 1
fi

PROJECT_DIR="/var/www/TelegramApiServerManagement"

# Check composer
if ! command -v composer &> /dev/null; then
    echo "❌ Composer not found. Please install composer first."
    exit 1
fi

# Install dependencies
echo "📦 Installing dependencies..."
cd "$PROJECT_DIR"
composer require amphp/http-client

if [ $? -ne 0 ]; then
    echo "❌ Failed to install dependencies"
    exit 1
fi

# Check supervisor
if ! command -v supervisorctl &> /dev/null; then
    echo "📦 Installing supervisor..."
    apt-get update
    apt-get install -y supervisor
fi

# Create log directory
echo "📁 Creating log directory..."
mkdir -p "$PROJECT_DIR/storage/logs"
chown -R www-data:www-data "$PROJECT_DIR/storage"

# Copy supervisor config
echo "📝 Installing supervisor config..."
cp "$PROJECT_DIR/supervisor-websocket-v2.conf" /etc/supervisor/conf.d/tas-workers-v2.conf

# Stop old version if running
echo "🛑 Stopping old version (if running)..."
supervisorctl stop tas-websocket-manager 2>/dev/null || true

# Reload supervisor
echo "🔄 Reloading supervisor..."
supervisorctl reread
supervisorctl update

# Start V2
echo "▶️  Starting WebSocket Manager V2..."
supervisorctl start tas-websocket-manager-v2
supervisorctl start tas-queue-worker:*

# Wait a bit
sleep 2

# Check status
echo ""
echo "✅ Installation complete!"
echo ""
echo "📊 Current status:"
supervisorctl status tas-websocket-manager-v2
supervisorctl status tas-queue-worker:*

echo ""
echo "📝 View logs:"
echo "   tail -f $PROJECT_DIR/storage/logs/websocket-v2.log"
echo "   tail -f $PROJECT_DIR/storage/logs/laravel.log"
echo ""
echo "🎯 Commands:"
echo "   sudo supervisorctl status"
echo "   sudo supervisorctl restart tas-websocket-manager-v2"
echo "   sudo supervisorctl stop tas-websocket-manager-v2"
echo ""
echo "📖 Read docs: $PROJECT_DIR/WEBSOCKET_V2.md"





