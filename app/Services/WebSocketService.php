<?php

namespace App\Services;

use App\Models\TelegramAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebSocketService
{

    public function getWebSocketUrl(TelegramAccount $account): string
    {
        return "ws://127.0.0.1:{$account->container_port}/events";
    }

    public function isPrivateMessage(array $update): bool
    {
        if (!isset($update['_'])) {
            return false;
        }

        if ($update['_'] === 'updateNewMessage') {
            $message = $update['message'] ?? [];
            $peerId = $message['peer_id']['_'] ?? null;

            return $peerId === 'peerUser';
        }

        return false;
    }

    /**
     * Отправить обновление на webhook
     */
    public function sendToWebhook(string $webhookUrl, array $update): void
    {
        try {
            $response = Http::timeout(10)
                ->post($webhookUrl, $update);

            if (!$response->successful()) {
                Log::warning('Webhook delivery failed', [
                    'url' => $webhookUrl,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Webhook error', [
                'url' => $webhookUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Извлечь данные сообщения
     */
    public function extractMessageData(array $update): array
    {
        $message = $update['message'] ?? [];

        return [
            'update_type' => $update['_'] ?? null,
            'message_id' => $message['id'] ?? null,
            'from_id' => $message['from_id']['user_id'] ?? null,
            'peer_id' => $message['peer_id']['user_id'] ?? null,
            'message' => $message['message'] ?? null,
            'date' => $message['date'] ?? null,
            'out' => $message['out'] ?? false,
            'raw' => $update,
        ];
    }
}

