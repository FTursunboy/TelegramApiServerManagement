# Telegram API Server Management - API Documentation

## Base URL
```
http://127.0.0.1:8000/api/v1
```

## Authentication
All requests require `X-API-Key` header:
```
X-API-Key: your_api_key_here
```

---

## 1. Send Text Message

### Endpoint
```
POST /send-message
```

### Request Body
```json
{
    "session_name": "session_abc123",
    "peer": "5002918981",
    "message": "Hello, this is a test message!",
    "parse_mode": "HTML"
}
```

### Parameters
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| session_name | string | ✅ | Session identifier of connected Telegram account |
| peer | string | ✅ | Telegram user ID or username (e.g. "5002918981" or "@username") |
| message | string | ✅ | Text message content |
| parse_mode | string | ❌ | "HTML" or "Markdown" (optional) |

### Success Response
```json
{
    "success": true,
    "data": {
        "success": true,
        "message_id": 12345,
        "date": 1702456789,
        "response": { ... }
    }
}
```

### Error Response
```json
{
    "success": false,
    "error": "Account not found or not ready"
}
```

---

## 2. Send File/Document

### Endpoint
```
POST /send-file
```

### Request Body
```json
{
    "session_name": "session_abc123",
    "peer": "5002918981",
    "file_url": "https://example.com/document.pdf",
    "caption": "Here is your document",
    "parse_mode": "HTML"
}
```

### Parameters
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| session_name | string | ✅ | Session identifier |
| peer | string | ✅ | Telegram user ID or username |
| file_url | string | ✅ | Public URL of the file to send |
| caption | string | ❌ | Caption text for the file |
| parse_mode | string | ❌ | "HTML" or "Markdown" |

### Success Response
```json
{
    "success": true,
    "data": {
        "success": true,
        "message_id": 12346,
        "date": 1702456790,
        "response": { ... }
    }
}
```

---

## 3. Send Voice Message

### Endpoint
```
POST /send-voice
```

### Request Body
```json
{
    "session_name": "session_abc123",
    "peer": "5002918981",
    "voice_url": "https://example.com/audio.ogg",
    "caption": "Voice message caption"
}
```

### Parameters
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| session_name | string | ✅ | Session identifier |
| peer | string | ✅ | Telegram user ID or username |
| voice_url | string | ✅ | Public URL of audio file (OGG format recommended) |
| caption | string | ❌ | Caption text |

### Success Response
```json
{
    "success": true,
    "data": {
        "success": true,
        "message_id": 12347,
        "date": 1702456791,
        "response": { ... }
    }
}
```

---

## 4. Send Photo

### Endpoint
```
POST /send-photo
```

### Request Body
```json
{
    "session_name": "session_abc123",
    "peer": "5002918981",
    "photo_url": "https://example.com/image.jpg",
    "caption": "Check out this photo!"
}
```

### Parameters
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| session_name | string | ✅ | Session identifier |
| peer | string | ✅ | Telegram user ID or username |
| photo_url | string | ✅ | Public URL of image file |
| caption | string | ❌ | Caption text |

### Success Response
```json
{
    "success": true,
    "data": {
        "success": true,
        "message_id": 12348,
        "response": { ... }
    }
}
```

---

## Webhook - Incoming Messages

When a private message is received by a connected Telegram account, a POST request is sent to the configured webhook URL.

### Webhook Request (sent to your endpoint)
```
POST https://your-webhook-url.com/telegram
Content-Type: application/json
```

### Webhook Payload
```json
{
    "session": "session_abc123",
    "message_id": 54321,
    "from_id": {
        "user_id": 5002918981
    },
    "peer_id": {
        "user_id": 6972662605
    },
    "message": "Hello! This is the message text",
    "date": 1702456800,
    "out": false,
    "mentioned": false,
    "media": null,
    "reply_to": null,
    "entities": null
}
```

### Webhook Fields Description
| Field | Type | Description |
|-------|------|-------------|
| session | string | Session name of the account that received the message |
| message_id | integer | Unique message ID in Telegram |
| from_id | object | Sender information (`user_id` = Telegram user ID) |
| peer_id | object | Chat/conversation ID |
| message | string | Text content of the message |
| date | integer | Unix timestamp when message was sent |
| out | boolean | `true` if message was sent by connected account, `false` if received |
| mentioned | boolean | `true` if account was mentioned in the message |
| media | object/null | Media information if message contains file/photo/voice |
| reply_to | object/null | Information about replied message |
| entities | array/null | Message formatting entities (bold, links, etc.) |

### Media Object (when present)
```json
{
    "media": {
        "type": "messageMediaPhoto",
        "has_photo": true,
        "has_document": false,
        "has_video": false,
        "has_audio": false,
        "has_voice": false
    }
}
```

### Webhook Response
Your endpoint should return HTTP 200 to confirm receipt:
```json
{
    "status": "ok"
}
```

---

## Error Codes

| HTTP Code | Description |
|-----------|-------------|
| 200 | Success |
| 400 | Bad request (invalid parameters) |
| 401 | Unauthorized (invalid API key) |
| 404 | Account/session not found |
| 500 | Internal server error |

---

## Example: Full Integration Flow

### 1. Send a message
```bash
curl -X POST "http://127.0.0.1:8000/api/v1/send-message" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_api_key" \
  -d '{
    "session_name": "session_abc123",
    "peer": "5002918981",
    "message": "Hello from API!"
  }'
```

### 2. Receive webhook when user replies
Your webhook endpoint receives:
```json
{
    "session": "session_abc123",
    "message_id": 54322,
    "from_id": {"user_id": 5002918981},
    "peer_id": {"user_id": 6972662605},
    "message": "Hi! I got your message!",
    "date": 1702456900,
    "out": false
}
```

### 3. Send file in response
```bash
curl -X POST "http://127.0.0.1:8000/api/v1/send-file" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_api_key" \
  -d '{
    "session_name": "session_abc123",
    "peer": "5002918981",
    "file_url": "https://example.com/report.pdf",
    "caption": "Here is your report!"
  }'
```

---

## Notes

1. **session_name** - obtained when account is connected/authorized
2. **peer** - can be numeric Telegram ID or @username
3. **file_url/voice_url/photo_url** - must be publicly accessible URLs
4. **Webhook** - only private (1-on-1) messages are forwarded, not groups/channels
5. **out: true** means the message was SENT by connected account
6. **out: false** means the message was RECEIVED by connected account

