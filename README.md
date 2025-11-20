# Telegram API Server Management

Microservice для управления Telegram-аккаунтами через [TelegramApiServer](https://github.com/xtrime-ru/TelegramApiServer).

## Возможности

- Автоматическое создание Docker-контейнеров для каждого аккаунта
- Поддержка User и Bot аккаунтов
- Авторизация через phone/code/2FA или bot token
- Отправка сообщений, голосовых, файлов
- Webhook для получения обновлений
- Мульти-аккаунт поддержка (каждый со своим app_id/app_hash)
- Простой REST API

## Требования

- PHP 8.2+
- Laravel 12+
- Docker (доступ к socket или TCP)
- MySQL/SQLite
- Composer

## Установка

```bash
# Clone repo
git clone <repo_url>
cd TelegramApiServerManagement

# Install dependencies
composer install

# Setup environment
cp .env.example .env
php artisan key:generate

# Configure .env
# DB_CONNECTION=sqlite (or mysql)
# DOCKER_HOST=unix:///var/run/docker.sock
# APP_API_KEY=your_secret_api_key
# TAS_DOCKER_IMAGE=ghcr.io/xtrime-ru/telegram-api-server:latest
# TAS_PASSWORDS={"admin":"admin"}

# Run migrations
php artisan migrate

# Pull TelegramApiServer Docker image
php artisan tas:pull-image

# Install WebSocket client
composer require textalk/websocket

# Start queue worker for WebSocket listeners
php artisan queue:work --queue=websocket --timeout=0 &

# Start server
php artisan serve
```

### Production Setup (Supervisor)

```bash
# Автоматическая установка
sudo ./setup-supervisor.sh

# Или вручную:
sudo cp supervisor-websocket.conf /etc/supervisor/conf.d/tas-workers.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start tas-websocket-worker:*
sudo supervisorctl start tas-queue-worker:*

# Проверить статус
sudo supervisorctl status

# Посмотреть логи
tail -f storage/logs/websocket-worker.log
tail -f storage/logs/queue-worker.log
```

## Конфигурация

Основные настройки в `config/tas.php`:

```php
'docker' => [
    'image' => 'xtrime/telegram-api-server:latest',
    'host' => 'unix:///var/run/docker.sock',
],

'port_range' => [
    'start' => 9510,
    'end' => 9600,
],
```

## Использование

См. [API_USAGE.md](API_USAGE.md) для полной документации.

### Быстрый старт

```bash
# 1. Start login
curl -X POST http://localhost/api/v1/login/start \
  -H "X-API-Key: your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "api_id": "123456",
    "api_hash": "your_hash",
    "type": "user",
    "phone": "+1234567890",
    "webhook_url": "https://your-server.com/webhook"
  }'

# Response: {"success": true, "data": {"session_name": "session_abc123", ...}}

# 2. Complete with code
curl -X POST http://localhost/api/v1/login/complete-code \
  -H "X-API-Key: your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "session_name": "session_abc123",
    "code": "12345"
  }'

# 3. Send message
curl -X POST http://localhost/api/v1/send-message \
  -H "X-API-Key: your_key" \
  -H "Content-Type: application/json" \
  -d '{
    "session_name": "session_abc123",
    "peer": "@username",
    "message": "Hello!"
  }'
```

## API Endpoints

- `POST /api/v1/login/start` - Начать авторизацию
- `POST /api/v1/login/complete-code` - Завершить код
- `POST /api/v1/login/complete-2fa` - Завершить 2FA
- `POST /api/v1/session/status` - Статус сессии
- `POST /api/v1/session/stop` - Остановить
- `POST /api/v1/session/restart` - Перезапустить
- `POST /api/v1/send-message` - Отправить сообщение
- `POST /api/v1/send-voice` - Отправить голос
- `POST /api/v1/send-file` - Отправить файл

## Архитектура

```
Request → Controller → Service → Docker/TAS API → Response
```

- **TelegramAccountService** - основная бизнес-логика
- **DockerService** - управление контейнерами
- **TasApiService** - взаимодействие с TAS
- **MessageService** - отправка сообщений
- **PortService** - управление портами

## Мульти-аккаунт

Каждый аккаунт получает:
- Собственный Docker-контейнер
- Уникальный порт (9510-9600)
- Персональный app_id/app_hash (через TAS API)
- Отдельный session volume

## License

MIT
