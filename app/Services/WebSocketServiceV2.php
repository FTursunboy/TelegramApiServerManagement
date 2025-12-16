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

        if (isset($media['photo'])) {
            $photo = $media['photo'];
            $info['photo'] = [
                'id' => $photo['id'] ?? null,
                'access_hash' => $photo['access_hash'] ?? null,
                'file_reference' => $photo['file_reference']['bytes'] ?? null,
                'sizes' => $photo['sizes'] ?? [],
                'dc_id' => $photo['dc_id'] ?? null,
            ];
        }

        if (isset($media['document'])) {
            $doc = $media['document'];
            $info['document'] = [
                'id' => $doc['id'] ?? null,
                'access_hash' => $doc['access_hash'] ?? null,
                'file_reference' => $doc['file_reference']['bytes'] ?? null,
                'mime_type' => $doc['mime_type'] ?? null,
                'size' => $doc['size'] ?? null,
                'dc_id' => $doc['dc_id'] ?? null,
            ];

            if (isset($doc['attributes'])) {
                foreach ($doc['attributes'] as $attr) {
                    if (($attr['_'] ?? '') === 'documentAttributeFilename') {
                        $info['document']['file_name'] = $attr['file_name'] ?? null;
                    }
                }
            }
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

            $fileUrl = $this->buildFileUrl($media, $port);

            if (!$fileUrl) {
                Log::warning('Could not build file URL', ['media' => $media]);
                return;
            }

            // Скачиваем файл
            $response = Http::timeout(30)->get($fileUrl);

            if (!$response->successful()) {
                Log::error('Failed to download file', ['url' => $fileUrl, 'status' => $response->status()]);
                return;
            }

            // Добавляем файл в messageData
            $fileName = $this->generateFileName($media);
            $messageData['file_content'] = $response->body();
            $messageData['file_name'] = $fileName;

            Log::info('Media downloaded', ['file_name' => $fileName, 'size' => strlen($response->body())]);

        } catch (\Throwable $e) {
            Log::error('Error downloading media', [
                'error' => $e->getMessage(),
                'session' => $messageData['session'] ?? 'unknown'
            ]);
        }
    }

    private function buildFileUrl(array $media, int $port): ?string
    {
        $baseUrl = "http://127.0.0.1:{$port}";

        // Для фото
        if (isset($media['photo_id'])) {
            // Выбираем самый большой размер
            $sizes = $media['sizes'] ?? [];
            $largestSize = null;
            $maxSize = 0;

            foreach ($sizes as $size) {
                if (in_array($size['_'] ?? '', ['photoSize', 'photoSizeProgressive'])) {
                    $currentSize = ($size['w'] ?? 0) * ($size['h'] ?? 0);
                    if ($currentSize > $maxSize) {
                        $maxSize = $currentSize;
                        $largestSize = $size;
                    }
                }
            }

            if (!$largestSize) {
                return null;
            }

            $params = http_build_query([
                'file_id' => $media['photo_id'],
                'access_hash' => $media['access_hash'],
                'file_reference' => base64_encode($media['file_reference']),
                'size_type' => $largestSize['type'],
                'dc_id' => $media['dc_id'] ?? 2
            ]);

            return "{$baseUrl}/file?{$params}";
        }

        // Для документа
        if (isset($media['document_id'])) {
            $params = http_build_query([
                'file_id' => $media['document_id'],
                'access_hash' => $media['access_hash'],
                'file_reference' => base64_encode($media['file_reference']),
                'dc_id' => $media['dc_id'] ?? 2
            ]);

            return "{$baseUrl}/file?{$params}";
        }

        return null;
    }

    private function generateFileName(array $media): string
    {
        // Для документа берем оригинальное имя
        if (isset($media['file_name'])) {
            return $media['file_name'];
        }

        // Для фото
        if (isset($media['photo_id'])) {
            return 'photo_' . time() . '_' . uniqid() . '.jpg';
        }

        return 'file_' . time() . '_' . uniqid() . '.bin';
    }


    public function sendToWebhook(string $webhookUrl, array $data): void
    {
        try {
            $client = HttpClientBuilder::buildDefault();

            $request = new Request($webhookUrl, 'POST');
            $request->setBody(json_encode($data));
            $request->setHeader('Content-Type', 'application/json');
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

