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

        $fromId = $message['from_id'] ?? null;
        $peerId = $message['peer_id'] ?? null;

        $isFromIdNumeric = is_numeric($fromId);
        $isPeerIdNumeric = is_numeric($peerId);

        $isPrivate = $isFromIdNumeric && $isPeerIdNumeric && $fromId !== $peerId;



        return $isPrivate;
    }

    /**
     * Извлечь update из jsonrpc обертки
     */
    private function extractUpdate(array $data): ?array
    {
        // Если это jsonrpc ответ
        if (isset($data['result']['update'])) {
            return $data['result']['update'];
        }

        // Если это прямой update
        if (isset($data['_']) && $data['_'] === 'updateNewMessage') {
            return $data;
        }

        return null;
    }

    /**
     * Извлечь данные сообщения для отправки на webhook
     */
    public function extractMessageData(array $data): array
    {
        $update = $this->extractUpdate($data);

        if (!$update) {
            return [];
        }

        $message = $update['message'] ?? [];
        $sessionName = $data['result']['session'] ?? null;

        return [
            'session' => $sessionName,
            'update_type' => $update['_'] ?? null,
            'message_id' => $message['id'] ?? null,
            'from_id' => $message['from_id'] ?? null,
            'peer_id' => $message['peer_id'] ?? null,
            'message' => $message['message'] ?? null,
            'date' => $message['date'] ?? null,
            'out' => $message['out'] ?? false,
            'mentioned' => $message['mentioned'] ?? false,
            'media_unread' => $message['media_unread'] ?? false,
            'silent' => $message['silent'] ?? false,
            'pts' => $update['pts'] ?? null,
            'pts_count' => $update['pts_count'] ?? null,
            'raw' => $update, // Полные данные update без jsonrpc обертки
        ];
    }

    /**
     * Отправить обновление на webhook
     */
    public function sendToWebhook(string $webhookUrl, array $data): void
    {
        try {
            Log::info('Sending to webhook', [
                'url' => $webhookUrl,
                'message_id' => $data['message_id'] ?? null,
                'from_id' => $data['from_id'] ?? null,
            ]);

            $response = Http::timeout(10)
                ->post($webhookUrl, $data);

            if ($response->successful()) {
                Log::info('Webhook delivered successfully', [
                    'url' => $webhookUrl,
                    'status' => $response->status(),
                ]);
            } else {
                Log::warning('Webhook delivery failed', [
                    'url' => $webhookUrl,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Webhook error', [
                'url' => $webhookUrl,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
