<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Exceptions\TasApiException;

class TasApiService
{
    /**
     * Базовый запрос к TAS
     */
    private function request(
        int $port,
        string $endpoint,
        array $params = [],
        string $method = 'GET'
    ): array {
        $url = "http://127.0.0.1:{$port}{$endpoint}";

        try {
            Log::debug('TAS API request', [
                'url' => $url,
                'method' => $method,
                'params' => $params,
            ]);

            $request = Http::timeout(30)
                ->withBasicAuth(
                    config('tas.api.username'),
                    config('tas.api.password')
                );

            if ($method === 'POST') {
                $response = $request->post($url, $params);
            } else {
                $response = $request->get($url, $params);
            }

            if (!$response->successful()) {
                throw new TasApiException(
                    "TAS API error [{$response->status()}]: {$response->body()}"
                );
            }

            $data = $response->json() ?? [];

            Log::debug('TAS API response', [
                'url' => $url,
                'response' => $data,
            ]);

            // Проверить на ошибки в ответе TAS
            if (isset($data['success']) && $data['success'] === false) {
                $error = $data['errors'][0] ?? 'Unknown error';
                throw new TasApiException("TAS returned error: " . json_encode($error));
            }

            return $data;

        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error('TAS API request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new TasApiException("TAS API request failed: {$e->getMessage()}");
        }
    }

    /**
     * Сохранить настройки сессии (app_id, app_hash)
     */
    public function saveSessionSettings(
        int $port,
        string $session,
        string $apiId,
        string $apiHash
    ): void {
        $this->request($port, '/system/saveSessionSettings', [
            'session' => $session,
            'settings[app_info][app_id]' => $apiId,
            'settings[app_info][app_hash]' => $apiHash,
        ]);


    }

    /**
     * Установить webhook
     */
    public function setWebhook(int $port, string $webhookUrl): void
    {
        $this->request($port, '/api/setWebhook', [
            'url' => $webhookUrl,
        ]);

        Log::info('Webhook set', [
            'port' => $port,
            'webhook_url' => $webhookUrl,
        ]);
    }

    /**
     * Начать авторизацию по номеру телефона
     */
    public function phoneLogin(int $port, string $session, string $phone): void
    {
        $this->request($port, "/api/{$session}/phoneLogin", [
            'phone' => $phone,
        ]);

        Log::info('Phone login initiated', [
            'port' => $port,
            'session' => $session,
            'phone' => substr($phone, 0, 4) . '***',
        ]);
    }

    /**
     * Завершить авторизацию кодом
     */
    public function completePhoneLogin(int $port, string $session, string $code): array
    {
        $response = $this->request($port, "/api/{$session}/completePhoneLogin", [
            'code' => $code,
        ]);

        Log::info('Phone login completed', [
            'port' => $port,
            'session' => $session,
        ]);

        return $response;
    }

    /**
     * Завершить авторизацию 2FA
     */
    public function complete2faLogin(int $port, string $session, string $password): void
    {
        $this->request($port, "/api/{$session}/complete2faLogin", [
            'password' => $password,
        ]);

        Log::info('2FA login completed', [
            'port' => $port,
            'session' => $session,
        ]);
    }

    /**
     * Авторизация бота
     */
    public function botLogin(int $port, string $session, string $token): void
    {
        $this->request($port, "/api/{$session}/botLogin", [
            'token' => $token,
        ]);

        Log::info('Bot login completed', [
            'port' => $port,
            'session' => $session,
        ]);
    }

    /**
     * Получить информацию о текущем аккаунте
     */
    public function getSelf(int $port): array
    {
        return $this->request($port, '/api/getSelf');
    }

    public function getSessionList(int $port): array
    {
        return $this->request($port, '/system/getSessionList');
    }

    public function addSession(int $port, string $sessionName): void
    {
        $this->request($port, '/system/addSession', [
            'session' => $sessionName,
        ]);

        Log::info('Session added', [
            'port' => $port,
            'session' => $sessionName,
        ]);
    }

    public function sendMessage(
        int $port,
        string $peer,
        string $message,
        ?string $parseMode = null,
        ?string $sessionName = null
    ): array {
        $endpoint = $sessionName
            ? "/api/{$sessionName}/messages.sendMessage"
            : "/api/messages.sendMessage";

        $params = [
            'peer' => $peer,
            'message' => $message,
        ];

        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        $response = $this->request($port, $endpoint, $params, 'POST');

        return $response;
    }


    public function sendPhoto(
        int $port,
        string $peer,
        string $photoPath,
        ?string $caption = null,
        ?string $parseMode = null
    ): array {
        $params = [
            'peer' => $peer,
            'file' => [
                '_' => 'LocalUrl',
                'file' => $photoPath,
            ],
        ];

        if ($caption) {
            $params['caption'] = $caption;
        }

        if ($parseMode) {
            $params['parseMode'] = $parseMode;
        }

        return $this->request($port, '/api/sendPhoto', $params, 'POST');
    }


    public function getHistory(
        int $port,
        string $peer,
        int $limit = 10,
        int $offset = 0
    ): array {
        return $this->request($port, '/api/messages.getHistory', [
            'peer' => $peer,
            'limit' => $limit,
            'offset_id' => $offset,
        ]);
    }

    /**
     * Получить информацию о пользователе/канале
     */
    public function getInfo(int $port, string $id): array
    {
        return $this->request($port, '/api/getInfo', [
            'id' => $id,
        ]);
    }

    /**
     * Отправить голосовое сообщение
     */
    public function sendVoice(
        int $port,
        string $peer,
        string $voicePath,
        ?string $caption = null,
        ?string $sessionName = null
    ): array {
        $endpoint = $sessionName
            ? "/api/{$sessionName}/sendVoice"
            : "/api/sendVoice";

        $params = [
            'peer' => $peer,
            'file' => [
                '_' => 'LocalUrl',
                'file' => $voicePath,
            ],
        ];

        if ($caption) {
            $params['caption'] = $caption;
        }

        $response = $this->request($port, $endpoint, $params, 'POST');

        Log::info('Voice sent', [
            'port' => $port,
            'peer' => $peer,
            'message_id' => $response['response']['id'] ?? null,
        ]);

        return $response;
    }

    /**
     * Отправить файл/документ
     */
    public function sendDocument(
        int $port,
        string $peer,
        string $filePath,
        ?string $caption = null,
        ?string $parseMode = null,
        ?string $sessionName = null
    ): array {
        $endpoint = $sessionName
            ? "/api/{$sessionName}/sendDocument"
            : "/api/sendDocument";

        $params = [
            'peer' => $peer,
            'file' => [
                '_' => 'LocalUrl',
                'file' => $filePath,
            ],
        ];

        if ($caption) {
            $params['caption'] = $caption;
        }

        if ($parseMode) {
            $params['parseMode'] = $parseMode;
        }

        $response = $this->request($port, $endpoint, $params, 'POST');

        Log::info('Document sent', [
            'port' => $port,
            'peer' => $peer,
            'message_id' => $response['response']['id'] ?? null,
        ]);

        return $response;
    }
}
