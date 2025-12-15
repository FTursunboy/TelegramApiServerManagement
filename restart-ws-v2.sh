#!/bin/bash

echo "🔄 Restarting WebSocket Manager V2..."

# Kill old processes
pkill -f "websocket:manager-v2"
sleep 2

# Clear old logs
rm -f /tmp/ws-v2-debug.log

# Start new process
cd /var/www/TelegramApiServerManagement
nohup php artisan websocket:manager-v2 --debug > /tmp/ws-v2-debug.log 2>&1 &

sleep 3

# Check if running
PID=$(ps aux | grep "websocket:manager-v2" | grep -v grep | awk '{print $2}')

if [ ! -z "$PID" ]; then
    echo "✅ WebSocket Manager V2 started (PID: $PID)"
    echo ""
    echo "📊 Connections:"
    tail -15 /tmp/ws-v2-debug.log
    echo ""
    echo "📝 Watch logs:"
    echo "   tail -f /tmp/ws-v2-debug.log"
    echo "   tail -f storage/logs/laravel.log"
else
    echo "❌ Failed to start"
    cat /tmp/ws-v2-debug.log
fi





