# Исправления и Улучшения

## Что было исправлено

### 1. ✅ Отправка голосовых сообщений через API (`/api/v1/send-voice`)

**Проблема:** При отправке голосовых сообщений по URL возникала ошибка:
```
TypeError: danog\MadelineProto\InternalDoc::sendVoice(): Argument #2 ($file) must be of type 
danog\MadelineProto\EventHandler\Message|danog\MadelineProto\EventHandler\Media|
danog\MadelineProto\LocalFile|danog\MadelineProto\RemoteUrl|danog\MadelineProto\BotApiFileId|
Amp\ByteStream\ReadableStream, string given
```

**Решение:** Обновлены методы `sendVoice()`, `sendPhoto()` и `sendDocument()` в `TasApiService.php`:
- Теперь автоматически определяется тип файла (URL или локальный путь)
- Для URL используется правильный тип `RemoteUrl` вместо `LocalUrl`
- Для локальных файлов используется `LocalFile`

**Изменённые файлы:**
- `app/Services/TasApiService.php`

**Пример использования:**
```bash
curl -X POST "http://your-domain/api/v1/send-voice" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "session_name": "session_69412c470bef7",
    "peer": "5002918981",
    "voice_path": "https://sham-back.shamcrm.com/storage/chat/files/voice_694e5ffc7d656.m4a",
    "caption": "Голосовое сообщение"
  }'
```

### 2. ✅ Координаты в webhook для геолокации

**Проблема:** Когда клиент отправляет местоположение (геолокацию), на webhook приходит объект media без координат (lat/lng):
```json
{
  "media": {
    "type": "messageMediaGeo",
    "has_photo": false,
    "has_document": false
  }
}
```

**Решение:** Создан промежуточный webhook proxy endpoint, который:
1. Принимает webhook от TAS
2. Извлекает координаты из raw данных geo сообщений
3. Добавляет поля `lat`, `lng`, `latitude`, `longitude` в объект media
4. Перенаправляет обогащённые данные на ваш конечный webhook

**Новые файлы:**
- `app/Http/Controllers/API/WebhookProxyController.php` - контроллер proxy
- Обновлён `routes/api.php` - добавлен маршрут `/api/webhook/proxy`
- Обновлён `app/Services/TelegramAccountService.php` - автоматическая настройка proxy

**Теперь в webhook приходит:**
```json
{
  "session": "session_69412c470bef7",
  "message_id": 6023,
  "from_id": 5002918981,
  "peer_id": "7519611407",
  "message": null,
  "date": 1766744227,
  "out": false,
  "media": {
    "type": "messageMediaGeo",
    "has_photo": false,
    "has_document": false,
    "has_video": false,
    "has_audio": false,
    "has_voice": false,
    "lat": 55.7558,
    "lng": 37.6173,
    "latitude": 55.7558,
    "longitude": 37.6173
  },
  "chat": {
    "first_name": "Tursunboy",
    "username": "RMFIS",
    "id": 5002918981
  }
}
```

## Как это работает

### Автоматическая настройка (для новых сессий)

При создании новой сессии через `/api/v1/login/start`:
1. Система автоматически настроит webhook proxy
2. TAS будет отправлять данные на proxy endpoint
3. Proxy обогатит данные координатами (если это geo)
4. Данные будут перенаправлены на ваш конечный webhook URL

### Для существующих сессий

Если у вас уже есть активные сессии, нужно их перезапустить:

```bash
curl -X POST "http://your-domain/api/v1/session/restart" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "session_name": "your_session_name"
  }'
```

## Конфигурация

Убедитесь, что в `.env` файле правильно указан `APP_URL`:

```env
APP_URL=http://your-domain.com
```

Это необходимо для правильной работы webhook proxy.

## Тестирование

### Тест отправки голоса

```bash
curl -X POST "http://localhost/api/v1/send-voice" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "session_name": "session_69412c470bef7",
    "peer": "5002918981",
    "voice_path": "https://example.com/voice.m4a"
  }'
```

### Тест webhook с координатами

1. Попросите клиента отправить вам геолокацию в Telegram
2. Проверьте webhook - теперь должны приходить поля `lat` и `lng` в объекте `media`

## Дополнительная информация

### Поддерживаемые форматы файлов

Для `sendVoice`, `sendPhoto`, `sendDocument` поддерживаются:

1. **URL адреса** (автоматически используется `RemoteUrl`):
   - `https://example.com/file.m4a`
   - `http://example.com/photo.jpg`

2. **Локальные пути** на сервере TAS (автоматически используется `LocalFile`):
   - `/app/storage/file.m4a`
   - `./files/photo.jpg`

### Логирование

Все действия логируются в `storage/logs/laravel.log`:
- Отправка файлов и голоса
- Обработка webhook
- Извлечение координат

Для просмотра логов:
```bash
tail -f storage/logs/laravel.log
```

## Поддержка

Если возникнут проблемы:
1. Проверьте логи: `storage/logs/laravel.log`
2. Убедитесь, что `APP_URL` настроен правильно
3. Перезапустите сессии после обновления

