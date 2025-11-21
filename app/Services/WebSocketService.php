<?php

namespace App\Services;

use App\Models\TelegramAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebSocketService
{
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

        if ($message['out'] ?? false) {
            return false;
        }

        $fromId = $this->extractUserId($message['from_id'] ?? null);
        $peerId = $this->extractUserId($message['peer_id'] ?? null);

        if (!$fromId || !$peerId) {
            return false;
        }
        Log::error(json_encode($data));
        return $fromId !== $peerId;
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
            'raw' => $update
        ];
    }

    public function sendToWebhook(string $webhookUrl, array $data): void
    {
        try {

            $response = Http::timeout(10)
                ->post($webhookUrl, $data);

            if ($response->successful()) {
                Log::info('Webhook delivered successfully', [
                    'url' => $webhookUrl,
                    'status' => $response->status(),
                ]);
            } else {

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
