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
        Log::error(json_encode($update));
        $message = $update['message'] ?? [];

        $fromId = $this->extractUserId($message['from_id'] ?? null);
        $peerId = $this->extractUserId($message['peer_id'] ?? null);

        if (!$fromId || !$peerId) {
            return false;
        }

        $isPeerUser = $peerId > 0;

        return $fromId !== $peerId && $isPeerUser;
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

        $port = TelegramAccount::query()->where('session_name', $sessionName)->first()->container_port;

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

        if ($message['out']) {
            $chat = $this->tasApiService->getInfo($port, $message['peer_id']);

            $result['chat']['first_name'] = $chat['response']['User']['first_name'];
            $result['chat']['username'] = $chat['response']['User']['username'];
            $result['chat']['id'] = $chat['response']['User']['id'];
            $result['chat']['phone_number'] = $chat['response']['User']['phone'] ?? null;
        }
        if (!$message['out']) {
            $chat = $this->tasApiService->getInfo($port, $message['from_id']);

            $result['chat']['first_name'] = $chat['response']['User']['first_name'];
            $result['chat']['username'] = $chat['response']['User']['username'];
            $result['chat']['id'] = $chat['response']['User']['id'];
            $result['chat']['phone_number'] = $chat['response']['User']['phone'] ?? null;
        }

        return $result;
    }

    private function extractMediaInfo(array $media): array
    {
        return [
            'type' => $media['_'] ?? 'unknown',
            'has_photo' => isset($media['photo']),
            'has_document' => isset($media['document']),
            'has_video' => isset($media['video']),
            'has_audio' => isset($media['audio']),
            'has_voice' => isset($media['voice']),
        ];
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

