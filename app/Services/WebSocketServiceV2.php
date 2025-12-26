<?php

namespace App\Services;

use App\Models\TelegramAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;

use function Amp\async;


class WebSocketServiceV2
{
    private const WEBHOOK_TIMEOUT = 5;
    private const WEBHOOK_RETRY_ATTEMPTS = 2;

    public function __construct(public TasApiService $tasApiService)
    {
    }

    public function getWebSocketUrl(TelegramAccount $account): string
    {
        return "ws://127.0.0.1:{$account->container_port}/events/{$account->session_name}";
    }

    public function isPrivateMessage(array $data): bool
    {
        $update = $this->extractUpdate($data);

        if (!$update) {
            return false;
        }

        if (($update['_'] ?? null) !== 'updateNewMessage') {
            return false;
        }
        $message = $update['message'] ?? [];


        $fromId = $this->extractUserId($message['from_id'] ?? null);
        $peerId = $this->extractUserId($message['peer_id'] ?? null);

        if (!$fromId || !$peerId) {
            return false;
        }

        if ($fromId == $peerId && isset($message['media'])) {
            $peerId = TelegramAccount::query()->where('session_name', $data['result']['session'])->select('telegram_user_id')->first()->telegram_user_id;
        }

        $isPeerUser = $peerId > 0;

        $re = $fromId !== $peerId && $isPeerUser;

        return $re;
    }



    private function extractUserId($id): ?int
    {
        if (is_numeric($id)) {
            return (int)$id;
        }

        if (is_array($id) && isset($id['user_id'])) {
            return (int)$id['user_id'];
        }

        return null;
    }

    private function extractUpdate(array $data): ?array
    {
        if (isset($data['result']['update'])) {
            return $data['result']['update'];
        }

        if (isset($data['_']) && $data['_'] === 'updateNewMessage') {
            return $data;
        }

        return null;
    }

    public function extractMessageData(array $data): array
    {
        $update = $this->extractUpdate($data);

        if (!$update) {
            return [];
        }

        $message = $update['message'] ?? [];
        $sessionName = $data['result']['session'] ?? null;

        $telegramAccount = TelegramAccount::query()->where('session_name', $sessionName)->select(['container_port', 'telegram_user_id'])->first();

        $result = [
            'session' => $sessionName,
            'message_id' => $message['id'] ?? null,
            'from_id' => $message['from_id'] ?? null,
            'peer_id' => $message['peer_id'] ?? null,
            'message' => $message['message'] ?? null,
            'date' => $message['date'] ?? null,
            'out' => $message['out'] ?? false,
            'mentioned' => $message['mentioned'] ?? false,
            'media' => isset($message['media']) ? $this->extractMediaInfo($message['media']) : null,
            'reply_to' => $message['reply_to'] ?? null,
            'entities' => $message['entities'] ?? null,
        ];
        if (!$result['out'] && ($result['peer_id'] == $result['from_id']))
        {
            $result['peer_id'] = $telegramAccount->telegram_user_id;
        }

        if ($message['out']) {
            $chat = $this->tasApiService->getInfo($telegramAccount->container_port, $message['peer_id']);

            $result['chat']['first_name'] = $chat['response']['User']['first_name'];
            $result['chat']['username'] = $chat['response']['User']['username'];
            $result['chat']['id'] = $chat['response']['User']['id'];
            $result['chat']['phone_number'] = $chat['response']['User']['phone'] ?? null;
        }
        if (!$message['out']) {
            $chat = $this->tasApiService->getInfo($telegramAccount->container_port, $message['from_id']);

            $result['chat']['first_name'] = $chat['response']['User']['first_name'];
            $result['chat']['username'] = $chat['response']['User']['username'];
            $result['chat']['id'] = $chat['response']['User']['id'];
            $result['chat']['phone_number'] = $chat['response']['User']['phone'] ?? null;
        }

        return $result;
    }

    private function extractMediaInfo(array $media): array
    {
        $info = [
            'type' => $media['_'] ?? 'unknown',
            'has_photo' => isset($media['photo']),
            'has_document' => isset($media['document']),
            'has_video' => isset($media['video']),
            'has_audio' => isset($media['audio']),
            'has_voice' => isset($media['voice']),
        ];

        // ИЗМЕНЕНИЕ: Сохраняем данные на верхнем уровне для удобного доступа
        if (isset($media['photo'])) {
            $photo = $media['photo'];
            $info['photo_id'] = $photo['id'] ?? null;
            $info['access_hash'] = $photo['access_hash'] ?? null;
            $info['file_reference'] = $photo['file_reference']['bytes'] ?? null;
            $info['sizes'] = $photo['sizes'] ?? [];
            $info['dc_id'] = $photo['dc_id'] ?? null;
        }

        if (isset($media['document'])) {
            $doc = $media['document'];
            $info['document_id'] = $doc['id'] ?? null;
            $info['access_hash'] = $doc['access_hash'] ?? null;
            $info['file_reference'] = $doc['file_reference']['bytes'] ?? null;
            $info['mime_type'] = $doc['mime_type'] ?? null;
            $info['size'] = $doc['size'] ?? null;
            $info['dc_id'] = $doc['dc_id'] ?? null;

            if (isset($doc['attributes'])) {
                foreach ($doc['attributes'] as $attr) {
                    if (($attr['_'] ?? '') === 'documentAttributeFilename') {
                        $info['file_name'] = $attr['file_name'] ?? null;
                    }
                }
            }
        }

        // Обработка геолокации
        if (isset($media['geo']) && ($media['_'] ?? '') === 'messageMediaGeo') {
            $geo = $media['geo'];
            $info['latitude'] = $geo['lat'] ?? null;
            $info['longitude'] = $geo['long'] ?? null;
        }

        return $info;
    }
    public function downloadAndAttachMedia(array &$messageData): void
    {
        $media = $messageData['media'] ?? null;

        if (!$media || (!isset($media['photo_id']) && !isset($media['document_id']))) {
            return;
        }

        try {
            $port = TelegramAccount::where('session_name', $messageData['session'])->value('container_port');

            if (!$port) {
                Log::warning('Port not found for session', ['session' => $messageData['session']]);
                return;
            }

            // ИЗМЕНЕНИЕ: buildFileUrl теперь возвращает содержимое файла напрямую
            $fileContent = $this->buildFileUrl($media, $port);

            if (!$fileContent) {
                Log::warning('Could not download file from TAS', ['media' => $media]);
                return;
            }

            // Добавляем файл в messageData
            $fileName = $this->generateFileName($media);
            $messageData['file_content'] = $fileContent;
            $messageData['file_name'] = $fileName;

            Log::info('Media downloaded from TAS', [
                'file_name' => $fileName,
                'size' => strlen($fileContent)
            ]);

        } catch (\Throwable $e) {
            Log::error('Error downloading media from TAS', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'session' => $messageData['session'] ?? 'unknown'
            ]);
        }
    }
    private function buildFileUrl(array $media, int $port): ?string
    {
        // Для фото
        if (isset($media['photo_id'])) {
            $mediaObject = [
                '_' => 'messageMediaPhoto',
                'photo' => [
                    '_' => 'photo',
                    'id' => $media['photo_id'],
                    'access_hash' => $media['access_hash'],
                    'file_reference' => [
                        '_' => 'bytes',
                        'bytes' => $media['file_reference']
                    ],
                    'sizes' => $media['sizes'],
                    'dc_id' => $media['dc_id'] ?? 2
                ]
            ];

            return $this->downloadFromTas($port, $mediaObject);
        }

        // Для документа
        if (isset($media['document_id'])) {
            // Формируем attributes
            $attributes = [];

            // Добавляем имя файла если есть
            if (isset($media['file_name'])) {
                $attributes[] = [
                    '_' => 'documentAttributeFilename',
                    'file_name' => $media['file_name']
                ];
            }

            // Для голосовых сообщений
            if ($media['mime_type'] === 'audio/ogg' || strpos($media['type'], 'voice') !== false) {
                $attributes[] = [
                    '_' => 'documentAttributeAudio',
                    'voice' => true,
                    'duration' => 0,
                    'waveform' => null
                ];
            }

            // Для видео
            if (strpos($media['mime_type'] ?? '', 'video') !== false) {
                $attributes[] = [
                    '_' => 'documentAttributeVideo',
                    'round_message' => false,
                    'supports_streaming' => true,
                    'duration' => 0,
                    'w' => 0,
                    'h' => 0
                ];
            }

            // Если нет атрибутов, добавляем хотя бы имя файла
            if (empty($attributes)) {
                $attributes[] = [
                    '_' => 'documentAttributeFilename',
                    'file_name' => $media['file_name'] ?? 'file'
                ];
            }

            $mediaObject = [
                '_' => 'messageMediaDocument',
                'document' => [
                    '_' => 'document',
                    'id' => $media['document_id'],
                    'access_hash' => $media['access_hash'],
                    'file_reference' => [
                        '_' => 'bytes',
                        'bytes' => $media['file_reference']
                    ],
                    'mime_type' => $media['mime_type'] ?? 'application/octet-stream',
                    'size' => $media['size'] ?? 0,
                    'dc_id' => $media['dc_id'] ?? 2,
                    'attributes' => $attributes,
                    'date' => time()
                ]
            ];

            return $this->downloadFromTas($port, $mediaObject);
        }

        return null;
    }
    private function downloadFromTas(int $port, array $mediaObject): ?string
    {
        try {
            // Используем TAS API для скачивания
            $url = "http://127.0.0.1:{$port}/api/downloadToResponse";

            $response = Http::timeout(60)
                ->withBasicAuth(
                    config('tas.api.username'),
                    config('tas.api.password')
                )
                ->post($url, ['media' => $mediaObject]);

            if (!$response->successful()) {
                Log::error('TAS download failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'media_type' => $mediaObject['_']
                ]);
                return null;
            }

            // Возвращаем содержимое файла напрямую
            return $response->body();

        } catch (\Throwable $e) {
            Log::error('TAS download exception', [
                'error' => $e->getMessage(),
                'media_type' => $mediaObject['_']
            ]);
            return null;
        }
    }
    private function generateFileName(array $media): string
    {
        if (isset($media['file_name'])) {
            return $media['file_name'];
        }

        if (isset($media['photo_id'])) {
            return 'photo_' . time() . '_' . uniqid() . '.jpg';
        }

        if (isset($media['document_id'])) {
            $ext = 'bin';
            if (isset($media['mime_type'])) {
                $mimeMap = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'video/mp4' => 'mp4',
                    'audio/ogg' => 'ogg',
                    'application/pdf' => 'pdf',
                ];
                $ext = $mimeMap[$media['mime_type']] ?? 'bin';
            }
            return 'document_' . time() . '_' . uniqid() . '.' . $ext;
        }

        return 'file_' . time() . '_' . uniqid() . '.bin';
    }

    public function sendToWebhook(string $webhookUrl, array $data): void
    {
        try {
            $client = HttpClientBuilder::buildDefault();

            if (isset($data['file_content'])) {
                $fileContent = $data['file_content'];
                $fileName = $data['file_name'];
                unset($data['file_content'], $data['file_name']);

                $boundary = '----WebKitFormBoundary' . uniqid();
                $body = '';

                foreach ($data as $key => $value) {
                    $body .= "--{$boundary}\r\n";
                    $body .= "Content-Disposition: form-data; name=\"{$key}\"\r\n\r\n";
                    $body .= is_array($value) ? json_encode($value) : $value;
                    $body .= "\r\n";
                }

                $body .= "--{$boundary}\r\n";
                $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$fileName}\"\r\n";
                $body .= "Content-Type: application/octet-stream\r\n\r\n";
                $body .= $fileContent;
                $body .= "\r\n";
                $body .= "--{$boundary}--\r\n";

                $request = new Request($webhookUrl, 'POST');
                $request->setBody($body);
                $request->setHeader('Content-Type', "multipart/form-data; boundary={$boundary}");
            } else {
                $request = new Request($webhookUrl, 'POST');
                $request->setBody(json_encode($data));
                $request->setHeader('Content-Type', 'application/json');
            }

            $request->setInactivityTimeout(self::WEBHOOK_TIMEOUT * 1000);
            $request->setTcpConnectTimeout(self::WEBHOOK_TIMEOUT * 1000);
            $request->setTlsHandshakeTimeout(self::WEBHOOK_TIMEOUT * 1000);
            $request->setTransferTimeout(self::WEBHOOK_TIMEOUT * 1000);

            $response = $client->request($request);
            $statusCode = $response->getStatus();

            if ($statusCode >= 200 && $statusCode < 300) {
                Log::debug('Webhook delivered', [
                    'url' => $webhookUrl,
                    'status' => $statusCode,
                    'session' => $data['session'] ?? 'unknown',
                ]);
            } else {
                Log::warning('Webhook returned non-2xx', [
                    'url' => $webhookUrl,
                    'status' => $statusCode,
                    'body' => $response->getBody()->buffer(),
                ]);
            }

        } catch (\Amp\Http\Client\TimeoutException $e) {
            Log::warning('Webhook timeout', [
                'url' => $webhookUrl,
                'timeout' => self::WEBHOOK_TIMEOUT,
            ]);
        } catch (\Throwable $e) {
            Log::error('Webhook error', [
                'url' => $webhookUrl,
                'error' => $e->getMessage(),
                'session' => $data['session'] ?? 'unknown',
            ]);
        }
    }

}

