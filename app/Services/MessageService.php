<?php

namespace App\Services;

use App\Models\TelegramAccount;
use Illuminate\Support\Facades\Log;

class MessageService
{
    public function __construct(
        private TasApiService $tas
    ) {}

    /**
     * Отправить текстовое сообщение
     */
    public function send(
        TelegramAccount $account,
        string $peer,
        string $message,
        ?string $parseMode = 'Markdown'
    ): array {
        if (!$account->isReady()) {
            throw new \InvalidArgumentException("Account #{$account->id} is not ready (status: {$account->status->value})");
        }

        if (!$account->hasContainer()) {
            throw new \InvalidArgumentException("Account #{$account->id} has no active container");
        }

        try {
            $response = $this->tas->sendMessage(
                port: $account->container_port,
                peer: $peer,
                message: $message,
                parseMode: $parseMode,
                sessionName: $account->session_name
            );

            // Обновить статистику
            $account->incrementMessageCount();

            return [
                'success' => true,
                'message_id' => $response['response']['id'] ?? null,
                'date' => $response['response']['date'] ?? null,
                'peer_id' => $response['response']['peer_id'] ?? null,
                'response' => $response,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to send message', [
                'account_id' => $account->id,
                'peer' => $peer,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Отправить фото
     */
    public function sendPhoto(
        TelegramAccount $account,
        string $peer,
        string $photoPath,
        ?string $caption = null
    ): array {
        if (!$account->isReady()) {
            throw new \InvalidArgumentException("Account #{$account->id} is not ready");
        }

        Log::info('Sending photo via TAS', [
            'account_id' => $account->id,
            'peer' => $peer,
            'photo_path' => $photoPath,
        ]);

        $response = $this->tas->sendPhoto(
            port: $account->container_port,
            peer: $peer,
            photoPath: $photoPath,
            caption: $caption
        );

        $account->incrementMessageCount();

        return [
            'success' => true,
            'message_id' => $response['response']['id'] ?? null,
            'response' => $response,
        ];
    }

    /**
     * Получить историю чата
     */
    public function getHistory(
        TelegramAccount $account,
        string $peer,
        int $limit = 10
    ): array {
        if (!$account->isReady()) {
            throw new \InvalidArgumentException("Account #{$account->id} is not ready");
        }

        Log::info('Getting chat history via TAS', [
            'account_id' => $account->id,
            'peer' => $peer,
            'limit' => $limit,
        ]);

        return $this->tas->getHistory(
            port: $account->container_port,
            peer: $peer,
            limit: $limit
        );
    }

    /**
     * Получить информацию о пользователе
     */
    public function getInfo(
        TelegramAccount $account,
        string $userId
    ): array {
        if (!$account->isReady()) {
            throw new \InvalidArgumentException("Account #{$account->id} is not ready");
        }

        return $this->tas->getInfo(
            port: $account->container_port,
            id: $userId
        );
    }

    /**
     * Отправить голосовое сообщение
     */
    public function sendVoice(
        TelegramAccount $account,
        string $peer,
        string $voicePath,
        ?string $caption = null
    ): array {
        if (!$account->isReady()) {
            throw new \InvalidArgumentException("Account #{$account->id} is not ready");
        }

        if (!$account->hasContainer()) {
            throw new \InvalidArgumentException("Account #{$account->id} has no active container");
        }

        Log::info('Sending voice via TAS', [
            'account_id' => $account->id,
            'peer' => $peer,
            'voice_path' => $voicePath,
        ]);

        try {
            $response = $this->tas->sendVoice(
                port: $account->container_port,
                peer: $peer,
                voicePath: $voicePath,
                caption: $caption,
                sessionName: $account->session_name
            );

            return [
                'success' => true,
                'message_id' => $response['response']['id'] ?? null,
                'date' => $response['response']['date'] ?? null,
                'response' => $response,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to send voice', [
                'account_id' => $account->id,
                'peer' => $peer,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Отправить файл/документ
     */
    public function sendFile(
        TelegramAccount $account,
        string $peer,
        string $filePath,
        ?string $caption = null,
        ?string $parseMode = null
    ): array {
        if (!$account->isReady()) {
            throw new \InvalidArgumentException("Account #{$account->id} is not ready");
        }

        if (!$account->hasContainer()) {
            throw new \InvalidArgumentException("Account #{$account->id} has no active container");
        }

        Log::info('Sending file via TAS', [
            'account_id' => $account->id,
            'peer' => $peer,
            'file_path' => $filePath,
        ]);

        try {
            $response = $this->tas->sendDocument(
                port: $account->container_port,
                peer: $peer,
                filePath: $filePath,
                caption: $caption,
                parseMode: $parseMode,
                sessionName: $account->session_name
            );

            return [
                'success' => true,
                'message_id' => $response['response']['id'] ?? null,
                'date' => $response['response']['date'] ?? null,
                'response' => $response,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to send file', [
                'account_id' => $account->id,
                'peer' => $peer,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
